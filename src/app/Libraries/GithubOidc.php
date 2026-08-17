<?php

declare(strict_types=1);

namespace App\Libraries;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

/**
 * Verifies the short-lived identity token GitHub Actions mints for a workflow.
 *
 * This is what lets a workflow publish without a stored secret: instead of a
 * long-lived token sitting in repository settings, GitHub signs a statement
 * about which repository, workflow and environment is running, and this server
 * decides whether it trusts that statement.
 *
 * Shield's own JWT addon cannot do this job: it issues tokens for *our* users
 * with *our* keys. Here we are the audience for someone else's token, signed
 * with rotating keys we have to fetch.
 */
final class GithubOidc
{
    public const ISSUER   = 'https://token.actions.githubusercontent.com';
    public const JWKS_URL = self::ISSUER . '/.well-known/jwks';

    /** GitHub rotates signing keys; a day is short enough to follow them. */
    private const JWKS_TTL = 86400;

    private string $audience;

    public function __construct(string $audience)
    {
        $this->audience = $audience;
    }

    /**
     * @return array{ok: true, claims: array<string, mixed>}|array{ok: false, error: string}
     */
    public function verify(string $jwt): array
    {
        $jwks = $this->jwks();
        if ($jwks === null) {
            return ['ok' => false, 'error' => "Could not fetch GitHub's signing keys."];
        }

        try {
            // parseKeySet pins each key to the algorithm its JWKS entry
            // declares, which is what closes the "alg: none" and HMAC
            // confusion families — never trust the header's alg.
            $claims = (array) JWT::decode($jwt, JWK::parseKeySet($jwks));
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Token rejected: ' . $e->getMessage()];
        }

        if (($claims['iss'] ?? null) !== self::ISSUER) {
            return ['ok' => false, 'error' => 'Token was not issued by GitHub Actions.'];
        }

        // The audience must be one we asked the workflow to request. Accepting
        // the default would let a token minted for any other service be
        // replayed here.
        $aud = $claims['aud'] ?? null;
        $aud = is_array($aud) ? $aud : [$aud];

        if (! in_array($this->audience, array_map('strval', $aud), true)) {
            return ['ok' => false, 'error' => 'Token was minted for a different audience.'];
        }

        return ['ok' => true, 'claims' => $claims];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jwks(): ?array
    {
        $cache  = service('cache');
        $cached = $cache->get('github_oidc_jwks');

        if (is_array($cached)) {
            return $cached;
        }

        $raw = @file_get_contents(self::JWKS_URL, false, stream_context_create(['http' => [
            'method'  => 'GET',
            'timeout' => 6,
            'header'  => "Accept: application/json\r\nUser-Agent: ci4-update-server\r\n",
        ]]));

        if ($raw === false) {
            return null;
        }

        $jwks = json_decode($raw, true);

        if (! is_array($jwks) || empty($jwks['keys'])) {
            return null;
        }

        $cache->save('github_oidc_jwks', $jwks, self::JWKS_TTL);

        return $jwks;
    }
}
