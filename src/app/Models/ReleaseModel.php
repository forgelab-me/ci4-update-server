<?php

namespace App\Models;

use CodeIgniter\Model;

class ReleaseModel extends Model
{
    protected $table            = 'releases';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $allowedFields    = ['project_id', 'version', 'roots', 'required', 'changelog', 'zip_path', 'manifest_path', 'is_latest', 'released_at', 'created_at'];
    protected $useTimestamps    = false;

    public function getLatestForProject(int $projectId): ?array
    {
        return $this->where('project_id', $projectId)
            ->where('is_latest', 1)
            ->first();
    }

    public function getForProject(int $projectId): array
    {
        return $this->where('project_id', $projectId)
            ->orderBy('released_at', 'DESC')
            ->orderBy('created_at', 'DESC')
            ->findAll();
    }

    public function setLatest(int $releaseId, int $projectId): void
    {
        $this->db->table('releases')
            ->where('project_id', $projectId)
            ->set('is_latest', 0)
            ->update();

        $this->db->table('releases')
            ->where('id', $releaseId)
            ->set('is_latest', 1)
            ->update();
    }

    public function getByVersion(int $projectId, string $version): ?array
    {
        return $this->where('project_id', $projectId)
            ->where('version', $version)
            ->first();
    }
}
