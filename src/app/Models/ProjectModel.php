<?php

namespace App\Models;

use CodeIgniter\Model;

class ProjectModel extends Model
{
    protected $table            = 'projects';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $allowedFields    = ['name', 'slug', 'token', 'created_at'];
    protected $useTimestamps    = false;

    public function findBySlug(string $slug): ?array
    {
        return $this->where('slug', $slug)->first();
    }

    public function withReleasesCount(): array
    {
        return $this->db->table('projects p')
            ->select('p.*, COUNT(r.id) AS releases_count, MAX(CASE WHEN r.is_latest = 1 THEN r.version END) AS latest_version')
            ->join('releases r', 'r.project_id = p.id', 'left')
            ->groupBy('p.id')
            ->orderBy('p.name', 'ASC')
            ->get()
            ->getResultArray();
    }
}
