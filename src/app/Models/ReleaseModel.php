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

    /**
     * Records one download of a release archive.
     */
    public function recordDownload(int $releaseId): void
    {
        $this->db->table('releases')
            ->where('id', $releaseId)
            ->set('downloads', 'downloads + 1', false)
            ->update();
    }

    /**
     * Total downloads per project, keyed by project id.
     *
     * @return array<int, int>
     */
    public function downloadTotals(): array
    {
        $rows = $this->db->table('releases')
            ->select('project_id, SUM(downloads) AS total')
            ->groupBy('project_id')
            ->get()
            ->getResultArray();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(int) $row['project_id']] = (int) $row['total'];
        }

        return $totals;
    }
}
