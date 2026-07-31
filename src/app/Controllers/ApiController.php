<?php

namespace App\Controllers;

use App\Models\ProjectModel;
use App\Models\ReleaseModel;
use CodeIgniter\HTTP\ResponseInterface;

class ApiController extends BaseController
{
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

    /**
     * Validates the Bearer token for a project.
     * Returns true when the project is public OR the token matches.
     */
    private function checkToken(array $project): bool
    {
        if (empty($project['token'])) {
            return true;
        }

        $header = $this->request->getHeaderLine('Authorization');
        if (! preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return false;
        }

        return hash_equals($project['token'], $m[1]);
    }

    /**
     * GET /api/{slug}/latest.json
     */
    public function latest(string $slug): ResponseInterface
    {
        $project = $this->projects->findBySlug($slug);
        if (! $project) {
            return $this->response
                ->setStatusCode(404)
                ->setJSON(['error' => 'Project not found']);
        }

        if (! $this->checkToken($project)) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON(['error' => 'Unauthorized']);
        }

        $release = $this->releases->getLatestForProject((int) $project['id']);
        if (! $release) {
            return $this->response
                ->setStatusCode(404)
                ->setJSON(['error' => 'No latest release found']);
        }

        $base = rtrim(base_url(), '/');

        return $this->response->setJSON([
            'version'      => $release['version'],
            'changelog'    => $release['changelog'] ?? '',
            'date'         => $release['released_at'],
            'zip_url'      => "{$base}/api/{$slug}/files/{$release['version']}/release.zip",
            'manifest_url' => "{$base}/api/{$slug}/files/{$release['version']}/manifest.json",
        ]);
    }

    /**
     * GET /api/{slug}/files/{version}/{filename}
     * Serves release.zip or manifest.json — never exposes writable/ directly.
     */
    public function file(string $slug, string $version, string $filename): ResponseInterface
    {
        if (! in_array($filename, ['release.zip', 'manifest.json'], true)) {
            return $this->response->setStatusCode(404)->setBody('Not found');
        }

        $project = $this->projects->findBySlug($slug);
        if (! $project) {
            return $this->response->setStatusCode(404)->setBody('Not found');
        }

        if (! $this->checkToken($project)) {
            return $this->response->setStatusCode(401)->setBody('Unauthorized');
        }

        $release = $this->releases->getByVersion((int) $project['id'], $version);
        if (! $release) {
            return $this->response->setStatusCode(404)->setBody('Version not found');
        }

        $field    = $filename === 'release.zip' ? 'zip_path' : 'manifest_path';
        $filepath = $release[$field] ?? null;

        if (! $filepath || ! file_exists($filepath)) {
            return $this->response->setStatusCode(404)->setBody('File not found');
        }

        $mime = $filename === 'release.zip' ? 'application/zip' : 'application/json';

        return $this->response
            ->download($filepath, null)
            ->setFileName($filename)
            ->setContentType($mime);
    }
}
