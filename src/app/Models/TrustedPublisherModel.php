<?php

namespace App\Models;

use CodeIgniter\Model;
use Forgelabme\TrustedPublishing\Identity;
use Forgelabme\TrustedPublishing\PublisherMatcher;

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
     * The publisher of this project that a verified identity matches, or null.
     *
     * The comparison itself lives in forgelab-me/ci4-trusted-publishing, so it
     * cannot drift from the other applications using the same mechanism. This
     * model decides only which rows are candidates.
     *
     * @return array<string, mixed>|null
     */
    public function match(int $projectId, Identity $identity): ?array
    {
        return (new PublisherMatcher())->match($this->forProject($projectId), $identity);
    }

    public function touch(int $id): void
    {
        $this->update($id, ['last_used_at' => date('Y-m-d H:i:s')]);
    }
}
