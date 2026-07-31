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
}
