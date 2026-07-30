<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ProjectModel;
use App\Models\ReleaseModel;
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

        $changelog  = trim((string) $this->request->getPost('changelog'));
        $releasedAt = (string) ($this->request->getPost('released_at') ?: date('Y-m-d'));
        $setLatest  = (bool) $this->request->getPost('set_latest');

        $zipFile = $this->request->getFile('zip_file');

        if (! $zipFile || ! $zipFile->isValid() || $zipFile->hasMoved()) {
            session()->setFlashdata('error', 'A valid ZIP file is required.');
            return redirect()->to(site_url("projects/{$projectId}"));
        }
        if (strtolower($zipFile->getClientExtension()) !== 'zip') {
            session()->setFlashdata('error', 'ZIP file must have a .zip extension.');
            return redirect()->to(site_url("projects/{$projectId}"));
        }
        if ($zipFile->getSize() > self::MAX_RELEASE_BYTES) {
            session()->setFlashdata('error', sprintf(
                'Release archive is too large (%.1f MB); the limit is %d MB.',
                $zipFile->getSize() / 1048576,
                (int) (self::MAX_RELEASE_BYTES / 1048576)
            ));
            return redirect()->to(site_url("projects/{$projectId}"));
        }

        $manifest = $this->readManifestFromZip($zipFile->getTempName());
        if ($manifest === null) {
            session()->setFlashdata('error', 'No manifest.json found inside the ZIP. Make sure it is included at the root of the archive.');
            return redirect()->to(site_url("projects/{$projectId}"));
        }
        if (empty($manifest['version'])) {
            session()->setFlashdata('error', 'manifest.json inside the ZIP must have a "version" field.');
            return redirect()->to(site_url("projects/{$projectId}"));
        }

        $version = trim((string) $manifest['version']);

        // The version comes from manifest.json *inside* the uploaded archive and
        // is used as a directory name, so it has to be constrained before it
        // touches the filesystem — otherwise "../../public" escapes writable/.
        if (! self::isSafeVersion($version)) {
            session()->setFlashdata('error', 'Invalid version in manifest.json: only letters, digits, dots, hyphens and underscores are allowed (max 20 characters).');
            return redirect()->to(site_url("projects/{$projectId}"));
        }

        $uploadPath = WRITEPATH . 'uploads/' . $project['slug'] . '/' . $version;

        if (! is_dir($uploadPath)) {
            mkdir($uploadPath, 0755, true);
        }

        $zipFile->move($uploadPath, 'release.zip', true);

        file_put_contents(
            $uploadPath . '/manifest.json',
            (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $releaseId = $this->releases->insert([
            'project_id'    => $projectId,
            'version'       => $version,
            'changelog'     => $changelog !== '' ? $changelog : null,
            'zip_path'      => $uploadPath . '/release.zip',
            'manifest_path' => $uploadPath . '/manifest.json',
            'is_latest'     => 0,
            'released_at'   => $releasedAt,
            'created_at'    => date('Y-m-d H:i:s'),
        ], true);

        if ($setLatest) {
            $this->releases->setLatest((int) $releaseId, $projectId);
        }

        session()->setFlashdata('success', "Release {$version} uploaded successfully.");
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
return redirect()->to(site_url("projects/{$release['project_id']}"));
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
    private function readManifestFromZip(string $zipPath): ?array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return null;
        }

        $content = $zip->getFromName('manifest.json');

        if ($content === false) {
            $prefix  = $this->detectZipPrefix($zip);
            $content = $prefix !== '' ? $zip->getFromName($prefix . 'manifest.json') : false;
        }

        $zip->close();

        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        return (is_array($data) && json_last_error() === JSON_ERROR_NONE) ? $data : null;
    }

    private function detectZipPrefix(\ZipArchive $zip): string
    {
        $commonPrefix = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || strpos($name, '/') === false) {
                return '';
            }
            $segment = substr($name, 0, strpos($name, '/') + 1);
            if ($commonPrefix === null) {
                $commonPrefix = $segment;
            } elseif ($commonPrefix !== $segment) {
                return '';
            }
        }

        return $commonPrefix ?? '';
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


