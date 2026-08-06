<?php

declare(strict_types=1);

use App\Models\ProjectModel;
use App\Models\ReleaseModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * GET /api/{slug}/latest.json?from={version}
 *
 * An app sees one release at a time, so it cannot tell that the version it is
 * about to install steps over another one that shipped a directory this one
 * doesn't. Only this server sees the whole history, which is why the warning
 * is computed here.
 *
 * @internal
 */
final class ApiSkippedReleasesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';

    private function makeProject(string $slug = 'my-app'): int
    {
        return (int) (new ProjectModel())->insert([
            'name'       => 'My App',
            'slug'       => $slug,
            'token'      => null,
            'created_at' => '2026-01-01 00:00:00',
        ], true);
    }

    private function makeRelease(int $projectId, string $version, ?string $roots, bool $isLatest = false): void
    {
        (new ReleaseModel())->insert([
            'project_id'    => $projectId,
            'version'       => $version,
            'roots'         => $roots,
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
    private function fetch(string $slug, ?string $from = null): array
    {
        $url = "api/{$slug}/latest.json" . ($from === null ? '' : '?from=' . rawurlencode($from));

        return json_decode($this->call('get', $url)->getJSON(), true);
    }

    // -- The case this exists for ---------------------------------------------

    public function testWarnsWhenASkippedReleaseCoveredADirectoryTheLatestDoesNot(): void
    {
        $id = $this->makeProject();
        $this->makeRelease($id, '1.1.0', 'app, public');
        $this->makeRelease($id, '1.2.0', 'app, public, vendor');
        $this->makeRelease($id, '1.3.0', 'app, public', true);

        $body = $this->fetch('my-app', '1.1.0');

        self::assertSame('1.3.0', $body['version']);
        self::assertSame(['1.2.0'], $body['skipped_versions']);
        self::assertSame(['vendor'], $body['missed_roots']);
    }

    /**
     * Nothing is skipped when the app is already on the release just before.
     */
    public function testSaysNothingWhenNoReleaseIsSteppedOver(): void
    {
        $id = $this->makeProject();
        $this->makeRelease($id, '1.2.0', 'app, public, vendor');
        $this->makeRelease($id, '1.3.0', 'app, public', true);

        $body = $this->fetch('my-app', '1.2.0');

        self::assertSame([], $body['skipped_versions']);
        self::assertSame([], $body['missed_roots']);
    }

    /**
     * A skipped release is harmless when the latest one rewrites the same
     * directories anyway — that is the common case, and warning about it would
     * train people to ignore the warning.
     */
    public function testDoesNotWarnWhenTheLatestReleaseCoversEverythingSkipped(): void
    {
        $id = $this->makeProject();
        $this->makeRelease($id, '1.1.0', 'app, public');
        $this->makeRelease($id, '1.2.0', 'app, public');
        $this->makeRelease($id, '1.3.0', 'app, public', true);

        $body = $this->fetch('my-app', '1.1.0');

        self::assertSame(['1.2.0'], $body['skipped_versions']);
        self::assertSame([], $body['missed_roots']);
    }

    public function testTheLatestReleaseCoveringVendorItselfIsNotAProblem(): void
    {
        $id = $this->makeProject();
        $this->makeRelease($id, '1.1.0', 'app, public');
        $this->makeRelease($id, '1.2.0', 'app, public, vendor');
        $this->makeRelease($id, '1.3.0', 'app, public, vendor', true);

        $body = $this->fetch('my-app', '1.1.0');

        self::assertSame([], $body['missed_roots']);
    }

    public function testReportsSeveralSkippedVersionsInOrder(): void
    {
        $id = $this->makeProject();
        $this->makeRelease($id, '1.1.0', 'app, public');
        $this->makeRelease($id, '1.3.0', 'app, public, vendor');
        $this->makeRelease($id, '1.2.0', 'app, public, extra');
        $this->makeRelease($id, '1.4.0', 'app, public', true);

        $body = $this->fetch('my-app', '1.1.0');

        // Ordered by version, not by the order rows came back from the table.
        self::assertSame(['1.2.0', '1.3.0'], $body['skipped_versions']);
        self::assertSame(['extra', 'vendor'], $body['missed_roots']);
    }

    // -- Staying quiet rather than guessing ------------------------------------

    /**
     * Without ?from= the server has no idea where the app is, and the response
     * keeps exactly the shape every deployed install already expects.
     */
    public function testTheResponseIsUnchangedWithoutTheParameter(): void
    {
        $id = $this->makeProject();
        $this->makeRelease($id, '1.1.0', 'app, public');
        $this->makeRelease($id, '1.2.0', 'app, public, vendor');
        $this->makeRelease($id, '1.3.0', 'app, public', true);

        $body = $this->fetch('my-app');

        self::assertArrayNotHasKey('skipped_versions', $body);
        self::assertArrayNotHasKey('missed_roots', $body);
        self::assertSame(
            ['version', 'changelog', 'date', 'zip_url', 'manifest_url'],
            array_keys($body)
        );
    }

    /**
     * A latest release from before scopes existed declares nothing, so every
     * comparison would be guesswork. Silence beats a wrong warning.
     */
    public function testStaysSilentWhenTheLatestReleaseDeclaresNoScope(): void
    {
        $id = $this->makeProject();
        $this->makeRelease($id, '1.2.0', 'app, public, vendor');
        $this->makeRelease($id, '1.3.0', null, true);

        $body = $this->fetch('my-app', '1.1.0');

        self::assertSame([], $body['missed_roots']);
    }

    /**
     * A skipped release from before scopes existed covered app + public by
     * convention — which the latest one covers too. Nothing to report.
     */
    public function testASkippedReleaseWithoutAScopeContributesNothing(): void
    {
        $id = $this->makeProject();
        $this->makeRelease($id, '1.2.0', null);
        $this->makeRelease($id, '1.3.0', 'app, public', true);

        $body = $this->fetch('my-app', '1.1.0');

        self::assertSame(['1.2.0'], $body['skipped_versions']);
        self::assertSame([], $body['missed_roots']);
    }

    public function testIgnoresAMalformedFromParameter(): void
    {
        $id = $this->makeProject();
        $this->makeRelease($id, '1.2.0', 'app, public, vendor');
        $this->makeRelease($id, '1.3.0', 'app, public', true);

        $body = $this->fetch('my-app', '../../etc/passwd');

        self::assertArrayNotHasKey('missed_roots', $body);
        self::assertSame('1.3.0', $body['version']);
    }
}
