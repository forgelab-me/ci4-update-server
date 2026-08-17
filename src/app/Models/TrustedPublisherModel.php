<?php

namespace App\Models;

use CodeIgniter\Model;

class TrustedPublisherModel extends Model
{
    protected $table         = 'trusted_publishers';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['project_id', 'user_id', 'provider', 'repository', 'repository_owner_id', 'environment', 'last_used_at', 'created_at'];
    protected $useTimestamps = false;

    /**
     * @return list<array<string, mixed>>
     */
    public function forProject(int $projectId): array
    {
        return $this->where('project_id', $projectId)
            ->orderBy('created_at', 'DESC')
            ->findAll();
    }

    /**
     * The publisher a set of verified OIDC claims matches, or null.
     *
     * The match is pinned on `repository_owner_id`, the numeric account id,
     * and only then on the name. A name can be renamed or freed and claimed by
     * someone else; the id is never reassigned. Matching on the name alone
     * would mean losing the account means losing the project.
     *
     * @param array<string, mixed> $claims
     *
     * @return array<string, mixed>|null
     */
    public function match(int $projectId, array $claims): ?array
    {
        $repository = (string) ($claims['repository'] ?? '');
        $ownerId    = (string) ($claims['repository_owner_id'] ?? '');
        $env        = (string) ($claims['environment'] ?? '');

        if ($repository === '' || $ownerId === '') {
            return null;
        }

        foreach ($this->forProject($projectId) as $row) {
            if (! hash_equals((string) $row['repository_owner_id'], $ownerId)) {
                continue;
            }

            if (! hash_equals(strtolower((string) $row['repository']), strtolower($repository))) {
                continue;
            }

            // A configured environment is a requirement, not a hint: it is
            // what lets a protected environment's approval gate stand between
            // a push and a publish.
            $required = trim((string) ($row['environment'] ?? ''));

            if ($required !== '' && ! hash_equals($required, $env)) {
                continue;
            }

            return $row;
        }

        return null;
    }

    public function touch(int $id): void
    {
        $this->db->table($this->table)
            ->where('id', $id)
            ->set('last_used_at', date('Y-m-d H:i:s'))
            ->update();
    }
}
