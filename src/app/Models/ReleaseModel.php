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

    /**
     * For each project with a promoted release, the highest version that
     * outranks it but was never promoted — someone uploaded ahead of what's
     * actually being served, and the dashboard is the only place that says
     * so before it becomes a support question.
     *
     * Version order, not upload order: SQL's MAX() sorts versions as text,
     * so this is done in PHP with version_compare() the same way every other
     * "which release is newer" decision in this app already is.
     *
     * Silent when nothing has been promoted yet — that's the "—" already
     * shown in the Latest Version column, not this indicator's concern.
     *
     * @return array<int, string> project_id => version
     */
    public function pendingPromotions(): array
    {
        $rows = $this->db->table('releases')
            ->select('project_id, version, is_latest')
            ->get()
            ->getResultArray();

        $byProject = [];
        foreach ($rows as $row) {
            $byProject[(int) $row['project_id']][] = $row;
        }

        $pending = [];

        foreach ($byProject as $projectId => $releases) {
            $latest = null;
            foreach ($releases as $release) {
                if ((int) $release['is_latest'] === 1) {
                    $latest = (string) $release['version'];
                    break;
                }
            }

            if ($latest === null) {
                continue;
            }

            $ahead = null;
            foreach ($releases as $release) {
                $version = (string) $release['version'];

                if ((int) $release['is_latest'] === 1 || version_compare($version, $latest, '<=')) {
                    continue;
                }

                if ($ahead === null || version_compare($version, $ahead, '>')) {
                    $ahead = $version;
                }
            }

            if ($ahead !== null) {
                $pending[$projectId] = $ahead;
            }
        }

        return $pending;
    }

    /**
     * Projects whose current latest release's archive is missing from disk.
     *
     * The latest release is what `/api/{slug}/files/.../release.zip` actually
     * serves; when its zip is gone, every real client downloading it gets a
     * 404 with nothing in the panel to explain why. Only latest releases are
     * checked — an older, un-promoted release with a missing file isn't
     * serving anyone, so it belongs to the releases table, not the dashboard.
     *
     * @return array<int, string> project_id => version
     */
    public function brokenLatestReleases(): array
    {
        $broken = [];

        foreach ($this->where('is_latest', 1)->findAll() as $release) {
            if (empty($release['zip_path']) || ! file_exists($release['zip_path'])) {
                $broken[(int) $release['project_id']] = (string) $release['version'];
            }
        }

        return $broken;
    }
}
