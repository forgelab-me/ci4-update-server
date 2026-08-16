<?php

declare(strict_types=1);

use App\Models\ProjectModel;
use App\Models\ReleaseModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Download counting.
 *
 * Counted per release; a project's figure is the sum of its releases, so the
 * two can never disagree. Only the archive counts — `manifest.json` is fetched
 * to check for updates, and counting it would report checks as installs.
 *
 * @internal
 */
final class ApiDownloadCounterTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = WRITEPATH . 'tmp/dl-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir . '/release.zip', 'PK-not-really');
        file_put_contents($this->dir . '/manifest.json', '{"version":"1.0.0"}');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function makeProject(?string $token = null): int
    {
        return (int) (new ProjectModel())->insert([
            'name' => 'My App', 'slug' => 'my-app', 'token' => $token,
            'created_at' => '2026-01-01 00:00:00',
        ], true);
    }

    private function makeRelease(int $projectId, string $version, bool $isLatest = true): int
    {
        return (int) (new ReleaseModel())->insert([
            'project_id'    => $projectId,
            'version'       => $version,
            'zip_path'      => $this->dir . '/release.zip',
            'manifest_path' => $this->dir . '/manifest.json',
            'is_latest'     => $isLatest ? 1 : 0,
            'released_at'   => '2026-06-01',
            'created_at'    => '2026-06-01 12:00:00',
        ], true);
    }

    private function downloads(int $releaseId): int
    {
        return (int) (new ReleaseModel())->find($releaseId)['downloads'];
    }

    // -- Counting ---------------------------------------------------------------

    public function testANewReleaseStartsAtZero(): void
    {
        $id = $this->makeProject();

        self::assertSame(0, $this->downloads($this->makeRelease($id, '1.0.0')));
    }

    public function testDownloadingTheArchiveIncrementsTheCount(): void
    {
        $p = $this->makeProject();
        $r = $this->makeRelease($p, '1.0.0');

        $this->call('get', 'api/my-app/files/1.0.0/release.zip');
        self::assertSame(1, $this->downloads($r));

        $this->call('get', 'api/my-app/files/1.0.0/release.zip');
        $this->call('get', 'api/my-app/files/1.0.0/release.zip');
        self::assertSame(3, $this->downloads($r));
    }

    /**
     * A client fetches the manifest to decide whether to update at all.
     * Counting it would turn every check into a reported install.
     */
    public function testFetchingTheManifestDoesNotCount(): void
    {
        $p = $this->makeProject();
        $r = $this->makeRelease($p, '1.0.0');

        $this->call('get', 'api/my-app/files/1.0.0/manifest.json');

        self::assertSame(0, $this->downloads($r));
    }

    public function testEachVersionIsCountedSeparately(): void
    {
        $p  = $this->makeProject();
        $r1 = $this->makeRelease($p, '1.0.0', false);
        $r2 = $this->makeRelease($p, '1.1.0');

        $this->call('get', 'api/my-app/files/1.1.0/release.zip');
        $this->call('get', 'api/my-app/files/1.1.0/release.zip');
        $this->call('get', 'api/my-app/files/1.0.0/release.zip');

        self::assertSame(1, $this->downloads($r1));
        self::assertSame(2, $this->downloads($r2));
    }

    // -- Refused requests must not count ----------------------------------------

    public function testARequestRefusedForAMissingTokenDoesNotCount(): void
    {
        $p = $this->makeProject('s3cret');
        $r = $this->makeRelease($p, '1.0.0');

        $this->call('get', 'api/my-app/files/1.0.0/release.zip');

        self::assertSame(0, $this->downloads($r), 'a 401 is not a download');
    }

    public function testAnUnknownVersionCannotCountAgainstAnother(): void
    {
        $p = $this->makeProject();
        $r = $this->makeRelease($p, '1.0.0');

        $this->call('get', 'api/my-app/files/9.9.9/release.zip');

        self::assertSame(0, $this->downloads($r));
    }

    // -- Totals -----------------------------------------------------------------

    /**
     * The project figure is derived, never stored twice.
     */
    public function testProjectTotalsAreTheSumOfTheirReleases(): void
    {
        $p  = $this->makeProject();
        $r1 = $this->makeRelease($p, '1.0.0', false);
        $this->makeRelease($p, '1.1.0');

        $this->call('get', 'api/my-app/files/1.0.0/release.zip');
        $this->call('get', 'api/my-app/files/1.1.0/release.zip');
        $this->call('get', 'api/my-app/files/1.1.0/release.zip');

        self::assertSame([$p => 3], (new ReleaseModel())->downloadTotals());

        // Deleting a release takes its downloads with it — which is exactly
        // why the project total is summed rather than kept alongside.
        (new ReleaseModel())->delete($r1);
        self::assertSame([$p => 2], (new ReleaseModel())->downloadTotals());
    }

    /**
     * The increment has to happen in SQL, not by reading the row, adding one
     * and writing it back: two concurrent downloads would both read the same
     * value and store the same result, losing a count.
     *
     * A real race cannot be provoked from a single-process test, and the
     * outcome is identical either way when nothing is concurrent — so this
     * asserts the *mechanism* instead. It is the only observable difference.
     */
    public function testTheIncrementIsComputedByTheDatabase(): void
    {
        $p = $this->makeProject();
        $r = $this->makeRelease($p, '1.0.0');

        $model = new ReleaseModel();
        $model->recordDownload($r);

        $sql = (string) db_connect()->getLastQuery();

        self::assertMatchesRegularExpression('/set\s+.?downloads.?\s*=\s*.?downloads.?\s*\+\s*1/i', $sql, $sql);
        self::assertSame(1, $this->downloads($r));
    }
}
