<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\PublishTokens;
use App\Libraries\ReleaseIngestor;
use App\Models\ProjectModel;
use App\Models\ReleaseModel;
use App\Models\TrustedPublisherModel;
use CodeIgniter\I18n\Time;
use CodeIgniter\HTTP\RedirectResponse;

class Projects extends BaseController
{
    /**
     * Upper bound for an uploaded release archive. PHP's upload_max_filesize
     * still applies and may be lower; this is an explicit application-level
     * cap so the limit doesn't depend on server configuration alone.
     */
    private const MAX_RELEASE_BYTES = 256 * 1024 * 1024;

    private ProjectModel $projects;
    private ReleaseModel $releases;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->projects = new ProjectModel();
        $this->releases = new ReleaseModel();
    }

    // -------------------------------------------------------------------------
    // Projects
    // -------------------------------------------------------------------------

    public function newProject(): string|RedirectResponse
    {
        if ($this->request->is('post')) {
            $name  = trim((string) $this->request->getPost('name'));
            $slug  = trim((string) $this->request->getPost('slug'));
            $token = trim((string) $this->request->getPost('token'));

            if ($name === '' || $slug === '') {
                session()->setFlashdata('error', 'Name and slug are required.');
                return redirect()->back()->withInput();
            }

            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $slug));
            $slug = trim($slug, '-');

            $this->projects->insert([
                'name'       => $name,
                'slug'       => $slug,
                'token'      => $token !== '' ? $token : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            session()->setFlashdata('success', "Project \"{$name}\" created.");
            return redirect()->to(site_url('admin'));
        }

        return view('admin/projects/new', ['title' => 'New Project']);
    }

    public function detail(int $id): string|RedirectResponse
    {
        $project = $this->projects->find($id);
        if (! $project) {
            session()->setFlashdata('error', 'Project not found.');
            return redirect()->to(site_url('admin'));
        }

        return view('admin/projects/detail', [
            'title'    => $project['name'],
            'project'  => $project,
            'releases' => $this->releases->getForProject($id),
            // Recomputed each time: which release is newest decides which of
            // the others have become skippable.
            'unguarded' => array_column($this->unguardedSteps($id), 'roots', 'version'),
            'publishScope'   => 'publish:' . $project['slug'],
            'publishTokens'  => PublishTokens::withScope('publish:' . $project['slug']),
            'publishers'     => (new TrustedPublisherModel())->forProject($id),
            'oidcAudience'   => rtrim(base_url(), '/'),
        ]);
    }

    public function update(int $id): RedirectResponse
    {
        if (! $this->projects->find($id)) {
            return redirect()->to(site_url('admin'));
        }

        $name  = trim((string) $this->request->getPost('name'));
        $slug  = trim((string) $this->request->getPost('slug'));
        $token = trim((string) $this->request->getPost('token'));

        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $slug));
        $slug = trim($slug, '-');

        $this->projects->update($id, [
            'name'  => $name,
            'slug'  => $slug,
            'token' => $token !== '' ? $token : null,
        ]);

        session()->setFlashdata('success', 'Project updated.');
        return redirect()->to(site_url("projects/{$id}"));
    }

    public function regenerateToken(int $id): RedirectResponse
    {
        if (! $this->projects->find($id)) {
            return redirect()->to(site_url('admin'));
        }

        $this->projects->update($id, ['token' => bin2hex(random_bytes(32))]);

        session()->setFlashdata('success', 'Token regenerated.');
        return redirect()->to(site_url("projects/{$id}"));
    }

    public function delete(int $id): RedirectResponse
    {
        $project = $this->projects->find($id);
        if (! $project) {
            return redirect()->to(site_url('admin'));
        }

        foreach ($this->releases->getForProject($id) as $release) {
            $this->deleteReleaseFiles($release);
        }

        $uploadDir = WRITEPATH . 'uploads/' . $project['slug'];
        if (is_dir($uploadDir)) {
            $this->removeDir($uploadDir);
        }

        $this->releases->where('project_id', $id)->delete();
        $this->projects->delete($id);

        session()->setFlashdata('success', "Project \"{$project['name']}\" deleted.");
        return redirect()->to(site_url('admin'));
    }

    // -------------------------------------------------------------------------
    // Releases
    // -------------------------------------------------------------------------

    public function uploadRelease(int $projectId): RedirectResponse
    {
        $project = $this->projects->find($projectId);
        if (! $project) {
            return redirect()->to(site_url('admin'));
        }

        $zipFile = $this->request->getFile('zip_file');

        if (! $zipFile) {
            session()->setFlashdata('error', 'A valid ZIP file is required.');

            return redirect()->to(site_url("projects/{$projectId}"));
        }

        // Same path as the publish API, so the two cannot drift.
        $result = (new ReleaseIngestor($this->releases))->ingest($project, $zipFile, [
            'changelog'   => trim((string) $this->request->getPost('changelog')),
            'released_at' => (string) ($this->request->getPost('released_at') ?: date('Y-m-d')),
            'required'    => (bool) $this->request->getPost('required'),
            'set_latest'  => (bool) $this->request->getPost('set_latest'),
        ]);

        if (! $result['success']) {
            session()->setFlashdata('error', $result['error']);

            return redirect()->to(site_url("projects/{$projectId}"));
        }

        session()->setFlashdata('success', "Release {$result['version']} uploaded successfully.");
        $this->flagUnguardedSteps($projectId);

        return redirect()->to(site_url("projects/{$projectId}"));
    }

    // -------------------------------------------------------------------------
    // Publishing from CI
    // -------------------------------------------------------------------------

    /**
     * Issues a token scoped to this project and nothing else.
     *
     * Shown once. Shield stores only a SHA-256, so there is no second chance
     * and no database leak that hands anyone the ability to publish.
     */
    public function createPublishToken(int $projectId): RedirectResponse
    {
        $project = $this->projects->find($projectId);
        if (! $project) {
            return redirect()->to(site_url('admin'));
        }

        $days  = (int) $this->request->getPost('expires_days');
        $token = auth()->user()->generateAccessToken(
            trim((string) $this->request->getPost('name')) ?: 'CI',
            ['publish:' . $project['slug']],
            $days > 0 ? Time::now()->addDays($days) : null
        );

        session()->setFlashdata('new_publish_token', $token->raw_token);
        session()->setFlashdata('success', 'Token created. Copy it now — it cannot be shown again.');

        return redirect()->to(site_url("projects/{$projectId}"));
    }

    public function revokePublishToken(int $projectId, int $tokenId): RedirectResponse
    {
        $project = $this->projects->find($projectId);
        if (! $project) {
            return redirect()->to(site_url('admin'));
        }

        $done = PublishTokens::revoke($tokenId, 'publish:' . $project['slug']);

        session()->setFlashdata($done ? 'success' : 'error',
            $done ? 'Token revoked.' : 'That token does not belong to this project.');

        return redirect()->to(site_url("projects/{$projectId}"));
    }

    /**
     * Trusts a GitHub workflow to publish here without a stored secret.
     */
    public function addPublisher(int $projectId): RedirectResponse
    {
        $project = $this->projects->find($projectId);
        if (! $project) {
            return redirect()->to(site_url('admin'));
        }

        $repository = trim((string) $this->request->getPost('repository'));
        $ownerId    = trim((string) $this->request->getPost('repository_owner_id'));
        $env        = trim((string) $this->request->getPost('environment'));

        if (preg_match('#\A[\w.-]+/[\w.-]+\z#', $repository) !== 1) {
            session()->setFlashdata('error', 'Repository must look like owner/name.');

            return redirect()->to(site_url("projects/{$projectId}"));
        }

        // The numeric account id is what the match is really pinned on, so a
        // typo here silently means "nothing will ever authenticate".
        if (preg_match('/\A\d+\z/', $ownerId) !== 1) {
            session()->setFlashdata('error', 'Owner id must be the numeric GitHub account id — see https://api.github.com/users/<owner>.');

            return redirect()->to(site_url("projects/{$projectId}"));
        }

        (new TrustedPublisherModel())->insert([
            'project_id'          => $projectId,
            'user_id'             => auth()->id(),
            'provider'            => 'github',
            'repository'          => $repository,
            'repository_owner_id' => $ownerId,
            'environment'         => $env !== '' ? $env : null,
            'created_at'          => date('Y-m-d H:i:s'),
        ]);

        session()->setFlashdata('success', "{$repository} can now publish to this project.");

        return redirect()->to(site_url("projects/{$projectId}"));
    }

    public function deletePublisher(int $projectId, int $publisherId): RedirectResponse
    {
        $publishers = new TrustedPublisherModel();
        $row        = $publishers->find($publisherId);

        if ($row !== null && (int) $row['project_id'] === $projectId) {
            $publishers->delete($publisherId);
            session()->setFlashdata('success', 'Trusted publisher removed.');
        }

        return redirect()->to(site_url("projects/{$projectId}"));
    }

    public function setLatest(int $projectId, int $releaseId): RedirectResponse
    {
        $release = $this->releases->find($releaseId);
        if (! $release) {
            return redirect()->to(site_url('admin'));
        }

        $this->releases->setLatest($releaseId, (int) $release['project_id']);

        session()->setFlashdata('success', "Release {$release['version']} set as latest.");

        // Promoting a release can make an older one skippable that wasn't.
        $this->flagUnguardedSteps((int) $release['project_id']);

        return redirect()->to(site_url("projects/{$release['project_id']}"));
    }

    /**
     * Marks a release as a step apps must walk through, or clears it.
     *
     * A required release is served on its own, ahead of anything newer, so an
     * app updating from further back applies it instead of jumping over it.
     */
    public function toggleRequired(int $projectId, int $releaseId): RedirectResponse
    {
        $release = $this->releases->find($releaseId);
        if (! $release || (int) $release['project_id'] !== $projectId) {
            return redirect()->to(site_url('admin'));
        }

        $required = empty($release['required']);
        $this->releases->update($releaseId, ['required' => $required ? 1 : 0]);

        session()->setFlashdata('success', $required
            ? "Release {$release['version']} is now a required step — apps will apply it before anything newer."
            : "Release {$release['version']} is no longer a required step.");

        return redirect()->to(site_url("projects/{$projectId}"));
    }

    public function deleteRelease(int $projectId, int $releaseId): RedirectResponse
    {
        $release = $this->releases->find($releaseId);
        if (! $release) {
            return redirect()->to(site_url('admin'));
        }

        $projectId = (int) $release['project_id'];
        $this->deleteReleaseFiles($release);
        $this->releases->delete($releaseId);

        session()->setFlashdata('success', "Release {$release['version']} deleted.");
        return redirect()->to(site_url("projects/{$projectId}"));
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * A version string is only ever used as a path segment and as a lookup key,
     * so it's restricted to a conservative charset. The 20-character cap
     * matches the `version` column in the releases table.
     *
     * Rejects traversal (`..`), separators, and anything exotic.
     */
    public static function isSafeVersion(string $version): bool
    {
        return $version !== ''
            && strlen($version) <= 20
            // \z rather than $: PCRE's $ would also match before a trailing newline.
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $version) === 1
            && ! str_contains($version, '..');
    }

    /**
     * Reads manifest.json from inside a ZIP archive.
     * Handles both flat ZIPs and ZIPs with a single root prefix (e.g. GitHub releases).
     */
    /**
     * Splits the stored `roots` column back into directory names.
     *
     * @return list<string>
     */
    public static function parseRoots(?string $roots): array
    {
        if ($roots === null || trim($roots) === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('trim', explode(',', $roots)))));
    }

    /**
     * Directories a release covers that the newest one does not.
     *
     * This is the reason a release would need to be a required step: an app
     * jumping straight to the newest release never applies it, and nothing
     * later will either. Empty when either side declares no scope — archives
     * built before ci4-updater 2.6 — since there is nothing to compare.
     *
     * @return list<string>
     */
    public static function unmatchedRoots(?string $releaseRoots, ?string $latestRoots): array
    {
        $release = self::parseRoots($releaseRoots);
        $latest  = self::parseRoots($latestRoots);

        if ($release === [] || $latest === []) {
            return [];
        }

        return array_values(array_diff($release, $latest));
    }

    /**
     * Releases that ought to be marked as required steps and are not.
     *
     * Recomputed rather than stored: it depends on which release is currently
     * the newest, and that changes every time one is promoted.
     *
     * @return list<array{version: string, roots: list<string>}>
     */
    private function unguardedSteps(int $projectId): array
    {
        $latest = $this->releases->getLatestForProject($projectId);
        if (! $latest) {
            return [];
        }

        $steps = [];

        foreach ($this->releases->getForProject($projectId) as $release) {
            if (! empty($release['required'])) {
                continue;
            }

            // The newest release cannot be skipped, and neither can anything
            // after it — only what sits before is at risk.
            if (version_compare((string) $release['version'], (string) $latest['version'], '>=')) {
                continue;
            }

            $missed = self::unmatchedRoots($release['roots'] ?? null, $latest['roots'] ?? null);

            if ($missed !== []) {
                $steps[] = ['version' => (string) $release['version'], 'roots' => $missed];
            }
        }

        usort($steps, static fn (array $a, array $b): int => version_compare($a['version'], $b['version']));

        return $steps;
    }

    /**
     * Points out releases that are now skippable but shouldn't be.
     *
     * Called after anything that changes which release is the newest, because
     * that is exactly when a previously safe release becomes skippable.
     */
    private function flagUnguardedSteps(int $projectId): void
    {
        $steps = $this->unguardedSteps($projectId);

        if ($steps === []) {
            return;
        }

        $parts = array_map(
            static fn (array $s): string => 'v' . $s['version'] . ' (' . implode(', ', $s['roots']) . ')',
            $steps
        );

        session()->setFlashdata('warning', sprintf(
            'Apps updating from an older version will now skip %s — %s not covered by the latest release, '
            . 'so nothing will apply %s. Mark %s as a required step if that matters.',
            implode(' and ', $parts),
            count($steps) > 1 ? 'those directories are' : 'that directory is',
            count($steps) > 1 ? 'them' : 'it',
            count($steps) > 1 ? 'them' : 'it'
        ));
    }


    /**
     * The directories a release covers, as declared in its manifest.
     *
     * Stored for display only — this server never applies an update, so it
     * takes no view on whether a scope is acceptable. That decision belongs to
     * each installation's Config\Updater::$allowedRoots.
     *
     * Null for manifests generated before ci4-updater 2.6, which declare none.
     */
    public static function readRoots(array $manifest): ?string
    {
        if (! isset($manifest['roots']) || ! is_array($manifest['roots'])) {
            return null;
        }

        $roots = [];

        foreach ($manifest['roots'] as $root) {
            // Rendered in the admin panel, so it is constrained here rather
            // than trusted from an uploaded archive.
            if (is_string($root) && preg_match('/\A[A-Za-z0-9_][A-Za-z0-9._-]{0,63}\z/', $root) === 1) {
                $roots[] = $root;
            }
        }

        $joined = implode(', ', array_unique($roots));

        return $joined === '' ? null : mb_substr($joined, 0, 255);
    }


    private function deleteReleaseFiles(array $release): void
    {
        foreach (['zip_path', 'manifest_path'] as $field) {
            if (! empty($release[$field]) && file_exists($release[$field])) {
                unlink($release[$field]);
            }
        }

        if (! empty($release['zip_path'])) {
            $dir = dirname($release['zip_path']);
            if (is_dir($dir) && count((array) glob($dir . '/*')) === 0) {
                rmdir($dir);
            }
        }
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}


