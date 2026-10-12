<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Controllers\Projects;
use App\Models\ReleaseModel;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Takes an uploaded archive and turns it into a stored release.
 *
 * Extracted so the admin form and the publish API cannot drift: the version
 * validation that keeps an archive from writing outside writable/uploads, the
 * verbatim manifest bytes a signature depends on, and the scope recorded for
 * the panel all have to behave identically whichever door the archive came
 * through.
 */
final class ReleaseIngestor
{
    /**
     * PHP's upload_max_filesize still applies and may be lower; this is an
     * explicit application-level cap so the limit doesn't depend on server
     * configuration alone.
     */
    public const MAX_BYTES = 256 * 1024 * 1024;

    /**
     * Cap on manifest.json's *uncompressed* size. A manifest is a JSON document
     * listing every file with its hash, so it grows with a release that ships
     * vendor/ — roughly 150 bytes a file: this server's own is ~170 KB, an
     * application with a large dependency tree several times that. Past this
     * it is either a mistake or a decompression bomb — a small,
     * highly-compressible entry that expands to gigabytes once read into
     * memory. Checked via statName() before the content is ever pulled out, so
     * the bomb never actually goes off.
     */
    private const MAX_MANIFEST_BYTES = 8 * 1024 * 1024;

    private ReleaseModel $releases;

    public function __construct(?ReleaseModel $releases = null)
    {
        $this->releases = $releases ?? new ReleaseModel();
    }

    /**
     * @param array<string, mixed> $project
     * @param array{changelog?:string, released_at?:string, required?:bool, set_latest?:bool, required_must_be_newest?:bool} $meta
     *
     * @return array{success:bool, error?:string, version?:string, release_id?:int}
     */
    public function ingest(array $project, UploadedFile $zip, array $meta = []): array
    {
        if ($zip->hasMoved()) {
            return ['success' => false, 'error' => 'A valid ZIP file is required.'];
        }

        if (! $zip->isValid()) {
            return ['success' => false, 'error' => $zip->getError() !== UPLOAD_ERR_OK
                ? 'The upload did not arrive intact: ' . $zip->getErrorString()
                : 'The upload did not arrive as a file. It was likely cut short by post_max_size, or rewritten on the way in.'];
        }

        if (strtolower($zip->getClientExtension()) !== 'zip') {
            return ['success' => false, 'error' => 'ZIP file must have a .zip extension.'];
        }

        if ($zip->getSize() > self::MAX_BYTES) {
            return ['success' => false, 'error' => sprintf(
                'Release archive is too large (%.1f MB); the limit is %d MB.',
                $zip->getSize() / 1048576,
                (int) (self::MAX_BYTES / 1048576)
            )];
        }

        $read = self::readManifestFromZip($zip->getTempName());
        if ($read === null) {
            // Not found, and too large to read, are different problems — and
            // telling someone their manifest is missing when it is sitting at
            // the root of the archive sends them looking in the wrong place.
            return ['success' => false, 'error' => self::manifestIsOversized($zip->getTempName())
                ? sprintf('manifest.json inside the ZIP is larger than the %d MB this server reads. It lists every file in the release; check that nothing unintended (a cache, a build directory) was included.', self::MAX_MANIFEST_BYTES / 1048576)
                : 'No manifest.json found inside the ZIP. Make sure it is included at the root of the archive.'];
        }

        $manifest = $read['data'];

        if (empty($manifest['version'])) {
            return ['success' => false, 'error' => 'manifest.json inside the ZIP must have a "version" field.'];
        }

        $version = trim((string) $manifest['version']);

        // The version comes from manifest.json *inside* the uploaded archive
        // and is used as a directory name, so it has to be constrained before
        // it touches the filesystem — otherwise "../../public" escapes writable/.
        if (! Projects::isSafeVersion($version)) {
            return ['success' => false, 'error' => 'Invalid version in manifest.json: only letters, digits, dots, hyphens and underscores are allowed (max 20 characters).'];
        }

        if ($this->releases->getByVersion((int) $project['id'], $version) !== null) {
            // Published bytes are immutable: an app that already downloaded
            // this version must never get different files under the same name.
            return ['success' => false, 'error' => "Version {$version} already exists for this project. Publish a new version instead."];
        }

        // Set for uploads that arrive through the publish API. A required step
        // is served ahead of the newest release to every app behind it, so one
        // marked required below the latest would be pushed to apps by whoever
        // holds a publish credential. Above everything the project has, it
        // reaches no one until a person promotes something higher.
        if (! empty($meta['required']) && ! empty($meta['required_must_be_newest'])) {
            $highest = $this->releases->highestVersion((int) $project['id']);

            if ($highest !== null && version_compare($version, $highest, '<=')) {
                return ['success' => false, 'error' => sprintf(
                    'Through the API a release can only be a required step if its version (%s) is above every version the project already has (%s). Publish it without "required" and mark it from the project page.',
                    $version,
                    $highest
                )];
            }
        }

        $uploadPath = WRITEPATH . 'uploads/' . $project['slug'] . '/' . $version;

        if (! is_dir($uploadPath) && ! mkdir($uploadPath, 0755, true) && ! is_dir($uploadPath)) {
            return ['success' => false, 'error' => 'Could not create the upload directory.'];
        }

        $zip->move($uploadPath, 'release.zip', true);

        // The bytes from the archive, verbatim. Re-encoding would produce a
        // manifest that no longer matches its signature.
        file_put_contents($uploadPath . '/manifest.json', $read['raw']);

        $releaseId = (int) $this->releases->insert([
            'project_id'    => (int) $project['id'],
            'version'       => $version,
            'roots'         => Projects::readRoots($manifest),
            'required'      => ! empty($meta['required']) ? 1 : 0,
            'changelog'     => ($meta['changelog'] ?? '') !== '' ? $meta['changelog'] : null,
            'zip_path'      => $uploadPath . '/release.zip',
            'manifest_path' => $uploadPath . '/manifest.json',
            'is_latest'     => 0,
            'released_at'   => $meta['released_at'] ?? date('Y-m-d'),
            'created_at'    => date('Y-m-d H:i:s'),
        ], true);

        if (! empty($meta['set_latest'])) {
            $this->releases->setLatest($releaseId, (int) $project['id']);
        }

        return ['success' => true, 'version' => $version, 'release_id' => $releaseId];
    }

    /**
     * @return array{data: array<string, mixed>, raw: string}|null
     *         The decoded manifest and the exact bytes it was decoded from.
     *         Both matter: a release signature covers those bytes, so the copy
     *         this server serves has to be the original and not a re-encoding.
     */
    public static function readManifestFromZip(string $zipPath): ?array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return null;
        }

        $name = self::locateManifest($zip);

        if ($name === null || ! self::manifestSizeIsSafe($zip, $name)) {
            $zip->close();

            return null;
        }

        $content = $zip->getFromName($name);
        $zip->close();

        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);

        return (is_array($data) && json_last_error() === JSON_ERROR_NONE)
            ? ['data' => $data, 'raw' => $content]
            : null;
    }

    /**
     * Whether the archive has a manifest.json that is there but too large to
     * read — as opposed to missing, or not JSON.
     */
    public static function manifestIsOversized(string $zipPath): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return false;
        }

        $name      = self::locateManifest($zip);
        $oversized = $name !== null && ! self::manifestSizeIsSafe($zip, $name);

        $zip->close();

        return $oversized;
    }

    /**
     * The entry name manifest.json is stored under — either at the root, or
     * behind the single top-level folder detectZipPrefix() finds — or null
     * when neither exists.
     */
    private static function locateManifest(\ZipArchive $zip): ?string
    {
        if ($zip->statName('manifest.json') !== false) {
            return 'manifest.json';
        }

        $prefix = self::detectZipPrefix($zip);

        return $prefix !== '' && $zip->statName($prefix . 'manifest.json') !== false
            ? $prefix . 'manifest.json'
            : null;
    }

    /**
     * Whether $name's *uncompressed* size is small enough to read into memory.
     *
     * Checked via statName() rather than after extraction: a tiny compressed
     * entry can expand to gigabytes, so the size has to be known before the
     * bytes are ever pulled out, not after.
     */
    private static function manifestSizeIsSafe(\ZipArchive $zip, string $name): bool
    {
        $stat = $zip->statName($name);

        return $stat !== false && (int) $stat['size'] <= self::MAX_MANIFEST_BYTES;
    }

    /**
     * The single top-level folder a GitHub-generated archive wraps everything
     * in, or '' when the archive has its files at the root.
     */
    public static function detectZipPrefix(\ZipArchive $zip): string
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
}
