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

        $base   = rtrim(base_url(), '/');
        $latest = $release;
        $extra  = [];

        // An app tells us where it is with ?from=. Only this server sees the
        // releases in between, so it is the only side that can say whether
        // going straight to the newest one steps over something.
        $from = trim((string) $this->request->getGet('from'));
        if ($from !== '' && Projects::isSafeVersion($from)) {
            // A release marked as a required step is served ahead of the
            // newest one. The app applies it, checks again, and carries on —
            // walking the path instead of jumping over it.
            $step = $this->nextRequiredStep((int) $project['id'], $from, $latest);

            if ($step !== null) {
                $release = $step;
                $extra   = [
                    'required_step'  => true,
                    'latest_version' => $latest['version'],
                ];
            }

            // Computed against what is actually being served, so a required
            // step still reports anything skipped on the way to it.
            $extra += $this->skipReport((int) $project['id'], $from, $release);
        }

        return $this->response->setJSON([
            'version'      => $release['version'],
            'changelog'    => $release['changelog'] ?? '',
            'date'         => $release['released_at'],
            'zip_url'      => "{$base}/api/{$slug}/files/{$release['version']}/release.zip",
            'manifest_url' => "{$base}/api/{$slug}/files/{$release['version']}/manifest.json",
        ] + $extra);
    }

    /**
     * The oldest release marked required that sits strictly between $from and
     * the newest one, if any.
     *
     * Only one is returned: the app applies it, comes back, and is handed the
     * next. That keeps every response a single well-formed release rather than
     * a plan the client would have to execute.
     *
     * @return array<string, mixed>|null
     */
    private function nextRequiredStep(int $projectId, string $from, array $latest): ?array
    {
        $candidates = [];

        foreach ($this->releases->getForProject($projectId) as $release) {
            if (empty($release['required'])) {
                continue;
            }

            $version = (string) $release['version'];

            if (version_compare($version, $from, '<=')
                || version_compare($version, (string) $latest['version'], '>=')) {
                continue;
            }

            $candidates[] = $release;
        }

        if ($candidates === []) {
            return null;
        }

        usort(
            $candidates,
            static fn (array $a, array $b): int => version_compare((string) $a['version'], (string) $b['version'])
        );

        return $candidates[0];
    }

    /**
     * What an app on $from would step over by installing $target directly.
     *
     * $target is whatever is actually being served — the newest release, or a
     * required step standing ahead of it.
     *
     * Only directories matter here. A skipped release whose files all live
     * under something the latest release also covers is harmless: the latest
     * one rewrites those directories wholesale. A directory the latest release
     * does *not* cover is another matter — nothing will bring it up to date,
     * and the app has no way of knowing on its own.
     *
     * @return array{skipped_versions: list<string>, missed_roots: list<string>}
     */
    private function skipReport(int $projectId, string $from, array $target): array
    {
        $targetRoots = Projects::parseRoots($target['roots'] ?? null);

        // A latest release that declares nothing predates ci4-updater 2.6.
        // Without knowing what it covers, every comparison below would be
        // guesswork — so we say nothing rather than warn wrongly.
        if ($targetRoots === []) {
            return ['skipped_versions' => [], 'missed_roots' => []];
        }

        $skipped = [];
        $missed  = [];

        foreach ($this->releases->getForProject($projectId) as $release) {
            $version = (string) $release['version'];

            if (version_compare($version, $from, '<=')
                || version_compare($version, (string) $target['version'], '>=')) {
                continue;
            }

            $skipped[] = $version;

            foreach (Projects::parseRoots($release['roots'] ?? null) as $root) {
                if (! in_array($root, $targetRoots, true) && ! in_array($root, $missed, true)) {
                    $missed[] = $root;
                }
            }
        }

        // Both lists are rendered in a warning, so their order is fixed here
        // rather than left to however the rows came back from the table.
        usort($skipped, static fn (string $a, string $b): int => version_compare($a, $b));
        sort($missed);

        return ['skipped_versions' => $skipped, 'missed_roots' => $missed];
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
