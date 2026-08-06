<?php

declare(strict_types=1);

use App\Models\ProjectModel;
use App\Models\ReleaseModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * A release marked as a required step is served on its own, ahead of anything
 * newer, so an app walks through it instead of jumping over it.
 *
 * @internal
 */
final class ApiRequiredStepTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';

    private function makeProject(string $slug = 'my-app'): int
    {
        return (int) (new ProjectModel())->insert([
            'name'       => 'My App',
            'slug'       => $slug,
            'created_at' => '2026-01-01 00:00:00',
        ], true);
    }

    private function makeRelease(int $projectId, string $version, ?string $roots, bool $isLatest = false, bool $required = false): void
    {
        (new ReleaseModel())->insert([
            'project_id'    => $projectId,
            'version'       => $version,
            'roots'         => $roots,
            'required'      => $required ? 1 : 0,
            'zip_path'      => '/tmp/release.zip',
            'manifest_path' => '/tmp/manifest.json',
            'is_latest'     => $isLatest ? 1 : 0,
            'released_at'   => '2026-06-01',
            'created_at'    => '2026-06-01 12:00:00',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $from): array
    {
        return json_decode(
            $this->call('get', 'api/my-app/latest.json?from=' . rawurlencode($from))->getJSON(),
            true
        );
    }

    public function testARequiredReleaseIsServedInsteadOfTheNewestOne(): void
    {
        $id = $this->makeProject();
        $this->makeRelease($id, '1.1.0', 'app, public');
        $this->makeRelease($id, '1.2.0', 'app, public, vendor', false, true);
        $this->makeRelease($id, '1.3.0', 'app, public', true);

        $body = $this->fetch('1.1.0');

        self::assertSame('1.2.0', $body['version']);
        self::assertTrue($body['required_step']);
        self::assertSame('1.3.0', $body['latest_version']);

        // The URLs must follow the release actually being served, or the app
        // downloads one version and is told it is installing another.
        self::assertStringContainsString('/files/1.2.0/release.zip', $body['zip_url']);
        self::assertStringContainsString('/files/1.2.0/manifest.json', $body['manifest_url']);
    }

    /**
     * One step at a time: the app applies 1.2.0, comes back, and is handed the
     * next thing rather than a plan it would have to execute itself.
     */
    public function testTheChainAdvancesOneStepPerCheck(): void
    {
        $id = $this->makeProject();
        $this->makeRelease($id, '1.1.0', 'app, public');
        $this->makeRelease($id, '1.2.0', 'app, public, vendor', false, true);
        $this->makeRelease($id, '1.3.0', 'app, public, extra', false, true);
        $this->makeRelease($id, '1.4.0', 'app, public', true);

        self::assertSame('1.2.0', $this->fetch('1.1.0')['version']);
        self::assertSame('1.3.0', $this->fetch('1.2.0')['version']);

        $last = $this->fetch('1.3.0');
        self::assertSame('1.4.0', $last['version']);
        self::assertArrayNotHasKey('required_step', $last);
    }

    public function testARequiredReleaseAlreadyInstalledIsNotServedAgain(): void
    {
        $id = $this->makeProject();
        $this->makeRelease($id, '1.2.0', 'app, public, vendor', false, true);
        $this->makeRelease($id, '1.3.0', 'app, public', true);

        $body = $this->fetch('1.2.0');

        self::assertSame('1.3.0', $body['version']);
        self::assertArrayNotHasKey('required_step', $body);
    }

    public function testTheNewestReleaseBeingRequiredChangesNothing(): void
    {
        $id = $this->makeProject();
        $this->makeRelease($id, '1.2.0', 'app, public');
        $this->makeRelease($id, '1.3.0', 'app, public', true, true);

        $body = $this->fetch('1.2.0');

        self::assertSame('1.3.0', $body['version']);
        self::assertArrayNotHasKey('required_step', $body);
    }

    /**
     * The skip report is computed against what is actually served, so a
     * required step still reports what was stepped over on the way to it.
     */
    public function testTheSkipReportFollowsTheServedRelease(): void
    {
        $id = $this->makeProject();
        $this->makeRelease($id, '1.1.0', 'app, public, legacy');
        $this->makeRelease($id, '1.2.0', 'app, public');
        $this->makeRelease($id, '1.3.0', 'app, public, vendor', false, true);
        $this->makeRelease($id, '1.4.0', 'app, public', true);

        $body = $this->fetch('1.0.0');

        self::assertSame('1.3.0', $body['version']);
        // Everything between 1.0.0 and the served 1.3.0 — not up to 1.4.0.
        self::assertSame(['1.1.0', '1.2.0'], $body['skipped_versions']);
        self::assertSame(['legacy'], $body['missed_roots']);
    }

    /**
     * Without ?from= the server has no idea where the app is, so it cannot
     * pick a step — and the response keeps the shape every install expects.
     */
    public function testNoStepIsServedWithoutTheParameter(): void
    {
        $id = $this->makeProject();
        $this->makeRelease($id, '1.2.0', 'app, public, vendor', false, true);
        $this->makeRelease($id, '1.3.0', 'app, public', true);

        $body = json_decode($this->call('get', 'api/my-app/latest.json')->getJSON(), true);

        self::assertSame('1.3.0', $body['version']);
        self::assertSame(
            ['version', 'changelog', 'date', 'zip_url', 'manifest_url'],
            array_keys($body)
        );
    }
}
