<?php

declare(strict_types=1);

use App\Libraries\ReleaseIngestor;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Covers how a release ZIP is inspected on upload: the manifest has to be
 * found whether it sits at the archive root or under a single top-level
 * folder (the shape GitHub's auto-generated archives have).
 *
 * They live on ReleaseIngestor, which the admin form and the publish API both
 * go through — the two must read an archive identically.
 *
 * @internal
 */
final class ZipManifestTest extends CIUnitTestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

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
        return ReleaseIngestor::readManifestFromZip($zipPath);
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
        $this->assertSame('1.2.0', $manifest['data']['version']);
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
        $this->assertSame('2.0.0', $manifest['data']['version']);
    }

    /**
     * The stored manifest has to be the archive's bytes, not a re-encoding of
     * the decoded array: a release signature covers exactly those bytes, and
     * re-serialising changes spacing, key order and escaping enough to make
     * the signature stop matching.
     */
    public function testTheExactBytesAreKeptAlongsideTheDecodedManifest(): void
    {
        // Deliberately not what json_encode would produce: odd spacing, a
        // slash that PHP would escape by default, trailing newline.
        $raw = "{\n  \"version\":\"1.2.0\",\n  \"roots\":[\"app\",\"public\"],\n  \"note\":\"a/b\"\n}\n";

        $zip = $this->makeZip('signed.zip', ['manifest.json' => $raw]);

        $manifest = $this->readManifest($zip);

        $this->assertIsArray($manifest);
        $this->assertSame($raw, $manifest['raw']);
        $this->assertSame(['app', 'public'], $manifest['data']['roots']);
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
     * A manifest.json entry whose uncompressed size blows past the cap is
     * refused before it is ever extracted — a highly-compressible entry could
     * otherwise expand to gigabytes in memory (a decompression bomb).
     */
    public function testReturnsNullWhenTheManifestIsOversized(): void
    {
        $huge = str_repeat('0', 9 * 1024 * 1024); // over MAX_MANIFEST_BYTES, trivially compressible
        $zip  = $this->makeZip('oversized.zip', ['manifest.json' => $huge]);

        $this->assertNull($this->readManifest($zip));
        $this->assertTrue(ReleaseIngestor::manifestIsOversized($zip));
    }

    /**
     * A release shipping vendor/ lists every file with its hash, so the
     * manifest grows with the dependency tree: this server's own is ~170 KB, a
     * larger application's several times that. The cap is there to stop a
     * decompression bomb, not to turn such a release away.
     */
    public function testAManifestListingAThousandsOfFilesIsAccepted(): void
    {
        $files = [];
        for ($i = 0; $i < 25000; $i++) {
            $files["vendor/package-{$i}/src/Some/Deeply/Nested/Class{$i}.php"] = hash('sha256', (string) $i);
        }

        $raw = json_encode(['version' => '1.0.0', 'roots' => ['app', 'vendor'], 'files' => $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->assertGreaterThan(3 * 1024 * 1024, strlen($raw), 'the fixture should be well past the old 1 MiB cap');

        $manifest = $this->readManifest($this->makeZip('big-vendor.zip', ['manifest.json' => $raw]));

        $this->assertIsArray($manifest);
        $this->assertSame('1.0.0', $manifest['data']['version']);
    }

    public function testAnOrdinaryManifestIsNotReportedAsOversized(): void
    {
        $zip = $this->makeZip('ok.zip', ['manifest.json' => json_encode(['version' => '1.0.0'])]);

        $this->assertFalse(ReleaseIngestor::manifestIsOversized($zip));
    }

    public function testNoManifestIsNotReportedAsOversized(): void
    {
        $this->assertFalse(ReleaseIngestor::manifestIsOversized($this->makeZip('none.zip', ['app/x.php' => '<?php'])));
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
        $prefix = ReleaseIngestor::detectZipPrefix($archive);
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
        $prefix = ReleaseIngestor::detectZipPrefix($archive);
        $archive->close();

        $this->assertSame('my-release/', $prefix);
    }
}
