<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\Shield\Authentication\Authenticators\AccessTokens;

/**
 * Shield access tokens, seen through the lens of one project.
 *
 * Shield stores tokens against a *user* and keeps their scopes serialised in
 * `auth_identities.extra`. The panel needs the opposite view — "who can publish
 * to this project" — which no Shield query offers, so the filtering happens
 * here rather than being smeared across the controller and the view.
 *
 * Listing every access token and filtering in PHP is fine at this scale: an
 * admin server has a handful, not thousands.
 */
final class PublishTokens
{
    /**
     * Tokens carrying $scope, newest first.
     *
     * Never returns the secret: `auth_identities.secret` holds a SHA-256 and
     * the token itself is unrecoverable by design.
     *
     * @return list<array<string, mixed>>
     */
    public static function withScope(string $scope): array
    {
        $rows = db_connect()->table('auth_identities')
            ->select('id, user_id, name, expires, last_used_at, created_at, extra')
            ->where('type', AccessTokens::ID_TYPE_ACCESS_TOKEN)
            ->orderBy('created_at', 'DESC')
            ->get()
            ->getResultArray();

        $matching = [];

        foreach ($rows as $row) {
            if (! in_array($scope, self::scopesOf($row), true)) {
                continue;
            }

            $row['expired'] = $row['expires'] !== null && strtotime((string) $row['expires']) < time();
            unset($row['extra']);

            $matching[] = $row;
        }

        return $matching;
    }

    /**
     * Deletes the already-expired tokens carrying $scope, and says how many.
     *
     * Shield refuses an expired token but never removes it, and Trusted
     * Publishing mints one per workflow run — so without this the panel fills
     * with dead entries and the long-lived tokens worth revoking get lost among
     * them. Called when minting, which is exactly where the growth happens, so
     * no scheduled job has to exist for it.
     *
     * Scoped on purpose: it must never reach a token belonging to another
     * project.
     */
    public static function purgeExpired(string $scope): int
    {
        $db = db_connect();

        $rows = $db->table('auth_identities')
            ->select('id, extra')
            ->where('type', AccessTokens::ID_TYPE_ACCESS_TOKEN)
            ->where('expires <', date('Y-m-d H:i:s'))
            ->get()
            ->getResultArray();

        $ids = [];

        foreach ($rows as $row) {
            if (in_array($scope, self::scopesOf($row), true)) {
                $ids[] = (int) $row['id'];
            }
        }

        if ($ids === []) {
            return 0;
        }

        $db->table('auth_identities')->whereIn('id', $ids)->delete();

        return count($ids);
    }

    /**
     * Deletes a token, but only if it really carries $scope.
     *
     * The id arrives from a form post, and without this check a crafted one
     * could revoke any access token in the system from a project page that has
     * no business touching it.
     */
    public static function revoke(int $identityId, string $scope): bool
    {
        $db  = db_connect();
        $row = $db->table('auth_identities')
            ->where('id', $identityId)
            ->where('type', AccessTokens::ID_TYPE_ACCESS_TOKEN)
            ->get()
            ->getRowArray();

        if ($row === null || ! in_array($scope, self::scopesOf($row), true)) {
            return false;
        }

        $db->table('auth_identities')->where('id', $identityId)->delete();

        return true;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private static function scopesOf(array $row): array
    {
        $extra = $row['extra'] ?? null;

        if (! is_string($extra) || $extra === '') {
            return [];
        }

        // Shield writes these itself, but unserialize() on stored data is
        // still told to accept no objects.
        $scopes = @unserialize($extra, ['allowed_classes' => false]);

        return is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [];
    }
}
