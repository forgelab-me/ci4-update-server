<?php

declare(strict_types=1);

use App\Models\ProjectModel;
use App\Models\ReleaseModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class ReleaseModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';

    private ReleaseModel $releases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->releases = new ReleaseModel();
    }

    private function makeProject(string $slug): int
    {
        return (int) (new ProjectModel())->insert([
            'name'       => ucfirst($slug),
            'slug'       => $slug,
            'created_at' => '2026-01-01 00:00:00',
        ], true);
    }

    private function makeRelease(int $projectId, string $version, bool $isLatest = false, string $releasedAt = '2026-06-01'): int
    {
        return (int) $this->releases->insert([
            'project_id'    => $projectId,
            'version'       => $version,
            'zip_path'      => "/tmp/{$version}/release.zip",
            'manifest_path' => "/tmp/{$version}/manifest.json",
            'is_latest'     => $isLatest ? 1 : 0,
            'released_at'   => $releasedAt,
            'created_at'    => $releasedAt . ' 12:00:00',
        ], true);
    }

    /**
     * Like makeRelease(), but zip_path points at a file that genuinely
     * exists — for tests where "the archive is actually there" matters.
     * Returns [releaseId, path]; the caller is responsible for unlink()ing it.
     */
    private function makeReleaseWithRealFile(int $projectId, string $version, bool $isLatest = false): array
    {
        $path = sys_get_temp_dir() . '/ci4us-release-' . bin2hex(random_bytes(4)) . '.zip';
        file_put_contents($path, 'not really a zip, just needs to exist');

        $id = (int) $this->releases->insert([
            'project_id'    => $projectId,
            'version'       => $version,
            'zip_path'      => $path,
            'manifest_path' => $path,
            'is_latest'     => $isLatest ? 1 : 0,
            'released_at'   => '2026-06-01',
            'created_at'    => '2026-06-01 12:00:00',
        ], true);

        return [$id, $path];
    }

    public function testSetLatestLeavesExactlyOneLatestRelease(): void
    {
        $project = $this->makeProject('my-app');
        $old     = $this->makeRelease($project, '1.0.0', true);
        $new     = $this->makeRelease($project, '2.0.0');

        $this->releases->setLatest($new, $project);

        $this->assertSame(0, (int) $this->releases->find($old)['is_latest']);
        $this->assertSame(1, (int) $this->releases->find($new)['is_latest']);
        $this->seeNumRecords(1, 'releases', ['project_id' => $project, 'is_latest' => 1]);
    }

    /**
     * setLatest() clears the flag with a project-scoped UPDATE. If that scope
     * were ever dropped, promoting a release in one project would silently
     * un-publish every other project's latest release.
     */
    public function testSetLatestDoesNotTouchOtherProjects(): void
    {
        $mine   = $this->makeProject('my-app');
        $theirs = $this->makeProject('other-app');

        $mineRelease   = $this->makeRelease($mine, '1.0.0');
        $theirsRelease = $this->makeRelease($theirs, '9.9.9', true);

        $this->releases->setLatest($mineRelease, $mine);

        $this->assertSame(1, (int) $this->releases->find($theirsRelease)['is_latest']);
    }

    public function testSetLatestCanBeRePromotedToAnOlderRelease(): void
    {
        $project = $this->makeProject('my-app');
        $v1      = $this->makeRelease($project, '1.0.0');
        $v2      = $this->makeRelease($project, '2.0.0', true);

        $this->releases->setLatest($v1, $project);

        $this->assertSame(1, (int) $this->releases->find($v1)['is_latest']);
        $this->assertSame(0, (int) $this->releases->find($v2)['is_latest']);
    }

    public function testGetLatestForProjectReturnsNullWhenNothingIsFlagged(): void
    {
        $project = $this->makeProject('my-app');
        $this->makeRelease($project, '1.0.0');

        $this->assertNull($this->releases->getLatestForProject($project));
    }

    public function testGetByVersionIsScopedToTheProject(): void
    {
        $mine   = $this->makeProject('my-app');
        $theirs = $this->makeProject('other-app');
        $this->makeRelease($theirs, '1.0.0');

        $this->assertNull($this->releases->getByVersion($mine, '1.0.0'));
        $this->assertNotNull($this->releases->getByVersion($theirs, '1.0.0'));
    }

    public function testGetForProjectReturnsNewestFirst(): void
    {
        $project = $this->makeProject('my-app');
        $this->makeRelease($project, '1.0.0', false, '2026-01-01');
        $this->makeRelease($project, '3.0.0', false, '2026-08-01');
        $this->makeRelease($project, '2.0.0', false, '2026-04-01');

        $versions = array_column($this->releases->getForProject($project), 'version');

        $this->assertSame(['3.0.0', '2.0.0', '1.0.0'], $versions);
    }

    /**
     * The dashboard's whole point: an upload that outranks what's actually
     * being served, sitting there un-promoted.
     */
    public function testPendingPromotionsFlagsAReleaseAheadOfLatest(): void
    {
        $project = $this->makeProject('my-app');
        $this->makeRelease($project, '1.0.0', true);
        $this->makeRelease($project, '2.0.0');

        $pending = $this->releases->pendingPromotions();

        $this->assertSame(['2.0.0'], array_values($pending));
        $this->assertSame($project, array_key_first($pending));
    }

    /**
     * version_compare(), not upload order or created_at: an upload made
     * later doesn't mean its version is higher.
     */
    public function testPendingPromotionsComparesByVersionNotUploadOrder(): void
    {
        $project = $this->makeProject('my-app');
        $this->makeRelease($project, '2.0.0', true, '2026-01-01');
        $this->makeRelease($project, '1.5.0', false, '2026-08-01');

        $this->assertArrayNotHasKey($project, $this->releases->pendingPromotions());
    }

    /**
     * Several releases ahead of latest: only the highest is worth surfacing.
     */
    public function testPendingPromotionsReportsTheHighestVersionOnly(): void
    {
        $project = $this->makeProject('my-app');
        $this->makeRelease($project, '1.0.0', true);
        $this->makeRelease($project, '1.1.0');
        $this->makeRelease($project, '1.2.0');

        $this->assertSame('1.2.0', $this->releases->pendingPromotions()[$project]);
    }

    public function testPendingPromotionsIsSilentWhenLatestIsAlreadyTheHighest(): void
    {
        $project = $this->makeProject('my-app');
        $this->makeRelease($project, '1.0.0');
        $this->makeRelease($project, '2.0.0', true);

        $this->assertArrayNotHasKey($project, $this->releases->pendingPromotions());
    }

    /**
     * Nothing promoted yet isn't this indicator's concern — that state is
     * already visible as "—" in the dashboard's Latest Version column.
     */
    public function testPendingPromotionsIsSilentWhenNothingIsPromoted(): void
    {
        $project = $this->makeProject('my-app');
        $this->makeRelease($project, '1.0.0');
        $this->makeRelease($project, '2.0.0');

        $this->assertArrayNotHasKey($project, $this->releases->pendingPromotions());
    }

    /**
     * version_compare(), not text order: as text, 1.9.0 sorts above 1.10.0.
     */
    public function testHighestVersionIsComparedNumericallyAcrossAllReleases(): void
    {
        $project = $this->makeProject('my-app');
        $this->makeRelease($project, '1.9.0', true);
        $this->makeRelease($project, '1.10.0');
        $this->makeRelease($project, '1.2.0');

        self::assertSame('1.10.0', $this->releases->highestVersion($project));
    }

    public function testHighestVersionIsNullForAProjectWithNoReleases(): void
    {
        $project = $this->makeProject('my-app');
        $other   = $this->makeProject('other-app');
        $this->makeRelease($other, '9.0.0');

        self::assertNull($this->releases->highestVersion($project));
    }

    public function testBrokenLatestReleasesFlagsAMissingArchive(): void
    {
        $project = $this->makeProject('my-app');
        $this->makeRelease($project, '1.0.0', true); // zip_path never actually exists

        $this->assertSame('1.0.0', $this->releases->brokenLatestReleases()[$project]);
    }

    public function testBrokenLatestReleasesIsSilentWhenTheArchiveExists(): void
    {
        $project = $this->makeProject('my-app');
        [, $path] = $this->makeReleaseWithRealFile($project, '1.0.0', true);

        try {
            $this->assertArrayNotHasKey($project, $this->releases->brokenLatestReleases());
        } finally {
            unlink($path);
        }
    }

    /**
     * A missing archive only matters for the release actually being served.
     * An older, un-promoted release with a missing file isn't breaking any
     * download — that belongs to the project's own releases table, not the
     * dashboard's "something is actively broken" indicator.
     */
    public function testBrokenLatestReleasesIgnoresNonLatestReleases(): void
    {
        $project = $this->makeProject('my-app');
        [, $path] = $this->makeReleaseWithRealFile($project, '2.0.0', true);
        $this->makeRelease($project, '1.0.0'); // missing archive, but not latest

        try {
            $this->assertArrayNotHasKey($project, $this->releases->brokenLatestReleases());
        } finally {
            unlink($path);
        }
    }
}
