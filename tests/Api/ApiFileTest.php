<?php

declare(strict_types=1);

use App\Models\ProjectModel;
use App\Models\ReleaseModel;
use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * GET /api/{slug}/files/{version}/{filename}
 *
 * The filename whitelist is the important one: it's what stops this
 * endpoint from handing out arbitrary files, so it gets a regression test.
 *
 * @internal
 */
final class ApiFileTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';

    private string $tmpDir = '';

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    /**
     * Same as seedProjectWithRelease(), but the release actually exists on
     * disk so the download path can be exercised.
     */
    private function seedProjectWithRealFiles(string $zipContents, string $manifestContents, ?string $token = null): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ci4us-files-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);

        $zipPath      = $this->tmpDir . '/release.zip';
        $manifestPath = $this->tmpDir . '/manifest.json';
        file_put_contents($zipPath, $zipContents);
        file_put_contents($manifestPath, $manifestContents);

        $id = (int) (new ProjectModel())->insert([
            'name'       => 'My App',
            'slug'       => 'my-app',
            'token'      => $token,
            'created_at' => '2026-01-01 00:00:00',
        ], true);

        (new ReleaseModel())->insert([
            'project_id'    => $id,
            'version'       => '1.0.0',
            'zip_path'      => $zipPath,
            'manifest_path' => $manifestPath,
            'is_latest'     => 1,
            'released_at'   => '2026-06-01',
            'created_at'    => '2026-06-01 12:00:00',
        ]);
    }

    private function seedProjectWithRelease(?string $token = null, string $version = '1.0.0'): void
    {
        $id = (int) (new ProjectModel())->insert([
            'name'       => 'My App',
            'slug'       => 'my-app',
            'token'      => $token,
            'created_at' => '2026-01-01 00:00:00',
        ], true);

        (new ReleaseModel())->insert([
            'project_id'    => $id,
            'version'       => $version,
            'zip_path'      => '/definitely/missing/release.zip',
            'manifest_path' => '/definitely/missing/manifest.json',
            'is_latest'     => 1,
            'released_at'   => '2026-06-01',
            'created_at'    => '2026-06-01 12:00:00',
        ]);
    }

    /**
     * Anything outside the whitelist must be refused before the project is
     * even looked up.
     */
    public function testRefusesFilenamesOutsideTheWhitelist(): void
    {
        $this->seedProjectWithRelease();

        foreach (['secret.txt', '.env', 'composer.json', 'release.ZIP'] as $filename) {
            $result = $this->get("api/my-app/files/1.0.0/{$filename}");

            $result->assertStatus(404);
            $this->assertStringContainsString(
                'Not found',
                $result->getBody(),
                "Expected '{$filename}' to be refused by the whitelist"
            );
        }
    }

    public function testUnknownProjectReturns404(): void
    {
        $result = $this->get('api/nope/files/1.0.0/release.zip');

        $result->assertStatus(404);
        $this->assertStringContainsString('Not found', $result->getBody());
    }

    public function testUnknownVersionReturns404(): void
    {
        $this->seedProjectWithRelease(null, '1.0.0');

        $result = $this->get('api/my-app/files/9.9.9/release.zip');

        $result->assertStatus(404);
        $this->assertStringContainsString('Version not found', $result->getBody());
    }

    public function testProtectedProjectRejectsMissingToken(): void
    {
        $this->seedProjectWithRelease('s3cret-token');

        $result = $this->get('api/my-app/files/1.0.0/release.zip');

        $result->assertStatus(401);
        $this->assertStringContainsString('Unauthorized', $result->getBody());
    }

    public function testProtectedProjectRejectsWrongToken(): void
    {
        $this->seedProjectWithRelease('s3cret-token');

        $result = $this->withHeaders(['Authorization' => 'Bearer nope'])
            ->get('api/my-app/files/1.0.0/release.zip');

        $result->assertStatus(401);
    }

    /**
     * A release row pointing at a file that no longer exists on disk must
     * 404 rather than emit a broken download.
     */
    public function testMissingFileOnDiskReturns404(): void
    {
        $this->seedProjectWithRelease();

        $result = $this->get('api/my-app/files/1.0.0/release.zip');

        $result->assertStatus(404);
        $this->assertStringContainsString('File not found', $result->getBody());
    }

    /**
     * The success path: a DownloadResponse for the file on disk, with the
     * right mime type, attachment filename and length.
     *
     * A DownloadResponse streams straight from the path when it is sent, so
     * the bytes never land in getBody() — Content-Length is what proves the
     * right file was picked.
     */
    public function testServesTheReleaseArchive(): void
    {
        $this->seedProjectWithRealFiles('zip contents here', '{"version":"1.0.0"}');

        $result = $this->get('api/my-app/files/1.0.0/release.zip');

        $result->assertStatus(200);
        $result->assertHeader('Content-Type', 'application/zip; charset=UTF-8');

        $response = $result->response();
        $this->assertInstanceOf(DownloadResponse::class, $response);
        $this->assertSame(strlen('zip contents here'), $response->getContentLength());

        $response->buildHeaders();
        $this->assertStringContainsString('release.zip', $response->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('attachment', $response->getHeaderLine('Content-Disposition'));
    }

    public function testServesTheManifest(): void
    {
        $manifest = '{"version":"1.0.0"}';
        $this->seedProjectWithRealFiles('zip contents here', $manifest);

        $result = $this->get('api/my-app/files/1.0.0/manifest.json');

        $result->assertStatus(200);
        $result->assertHeader('Content-Type', 'application/json; charset=UTF-8');
        $this->assertSame(strlen($manifest), $result->response()->getContentLength());
    }

    public function testProtectedProjectServesTheArchiveWithTheCorrectToken(): void
    {
        $this->seedProjectWithRealFiles('zip contents here', '{}', 's3cret-token');

        $result = $this->withHeaders(['Authorization' => 'Bearer s3cret-token'])
            ->get('api/my-app/files/1.0.0/release.zip');

        $result->assertStatus(200);
        $this->assertSame(strlen('zip contents here'), $result->response()->getContentLength());
    }
}
