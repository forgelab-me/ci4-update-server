<?php

declare(strict_types=1);

use App\Controllers\Projects;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Covers how a release ZIP is inspected on upload: the manifest has to be
 * found whether it sits at the archive root or under a single top-level
 * folder (the shape GitHub's auto-generated archives have).
 *
 * readManifestFromZip()/detectZipPrefix() are private, so they're reached
 * through CIUnitTestCase's invoker rather than by widening their visibility
 * just for tests.
 *
 * @internal
 */
final class ZipManifestTest extends CIUnitTestCase
{
    private string $tmpDir;
    private Projects $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new Projects();
        $this->tmpDir     = sys_get_temp_dir() . '/ci4us-zip-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    /**
     * @param array<string, string> $entries path inside the zip => contents
     */
    private function makeZip(string $name, array $entries): string
    {
        $path = $this->tmpDir . '/' . $name;

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($entries as $entry => $contents) {
            $zip->addFromString($entry, $contents);
        }
        $zip->close();

        return $path;
    }

    private function readManifest(string $zipPath): ?array
    {
        return $this->getPrivateMethodInvoker($this->controller, 'readManifestFromZip')($zipPath);
    }

    public function testReadsManifestAtTheArchiveRoot(): void
    {
        $zip = $this->makeZip('flat.zip', [
            'manifest.json'      => json_encode(['version' => '1.2.0', 'files' => ['app/x.php' => 'hash']]),
            'app/x.php'          => '<?php',
            'public/index.php'   => '<?php',
        ]);

        $manifest = $this->readManifest($zip);

        $this->assertIsArray($manifest);
        $this->assertSame('1.2.0', $manifest['version']);
    }

    /**
     * GitHub-style archive: everything nested under one generated folder.
     */
    public function testReadsManifestUnderASingleRootFolder(): void
    {
        $zip = $this->makeZip('prefixed.zip', [
            'owner-repo-abc1234/manifest.json' => json_encode(['version' => '2.0.0']),
            'owner-repo-abc1234/app/x.php'     => '<?php',
        ]);

        $manifest = $this->readManifest($zip);

        $this->assertIsArray($manifest);
        $this->assertSame('2.0.0', $manifest['version']);
    }

    public function testReturnsNullWhenThereIsNoManifest(): void
    {
        $zip = $this->makeZip('no-manifest.zip', [
            'app/x.php'        => '<?php',
            'public/index.php' => '<?php',
        ]);

        $this->assertNull($this->readManifest($zip));
    }

    public function testReturnsNullWhenTheManifestIsNotValidJson(): void
    {
        $zip = $this->makeZip('broken.zip', [
            'manifest.json' => '{ this is not json',
        ]);

        $this->assertNull($this->readManifest($zip));
    }

    public function testReturnsNullWhenTheFileIsNotAZip(): void
    {
        $path = $this->tmpDir . '/not-a-zip.zip';
        file_put_contents($path, 'just some bytes');

        $this->assertNull($this->readManifest($path));
    }

    /**
     * Two different top-level folders means there is no common prefix to
     * strip, so the manifest must be looked up at the root only.
     */
    public function testNoPrefixIsDetectedWhenTopLevelFoldersDiffer(): void
    {
        $zip = $this->makeZip('two-roots.zip', [
            'app/x.php'    => '<?php',
            'public/y.php' => '<?php',
        ]);

        $archive = new ZipArchive();
        $archive->open($zip);
        $prefix = $this->getPrivateMethodInvoker($this->controller, 'detectZipPrefix')($archive);
        $archive->close();

        $this->assertSame('', $prefix);
    }

    public function testPrefixIsDetectedForASingleRootFolder(): void
    {
        $zip = $this->makeZip('one-root.zip', [
            'my-release/app/x.php'    => '<?php',
            'my-release/manifest.json' => '{}',
        ]);

        $archive = new ZipArchive();
        $archive->open($zip);
        $prefix = $this->getPrivateMethodInvoker($this->controller, 'detectZipPrefix')($archive);
        $archive->close();

        $this->assertSame('my-release/', $prefix);
    }
}
