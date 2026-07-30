<?php

declare(strict_types=1);

use App\Models\ProjectModel;
use App\Models\ReleaseModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * GET /api/{slug}/latest.json — the public contract every ci4-updater
 * install depends on. Breaking its shape breaks every deployed app, so the
 * exact keys are asserted here, not just the status code.
 *
 * @internal
 */
final class ApiLatestTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';

    private function makeProject(string $slug, ?string $token = null): int
    {
        return (int) (new ProjectModel())->insert([
            'name'       => ucfirst($slug),
            'slug'       => $slug,
            'token'      => $token,
            'created_at' => '2026-01-01 00:00:00',
        ], true);
    }

    private function makeRelease(int $projectId, string $version, bool $isLatest = true): int
    {
        return (int) (new ReleaseModel())->insert([
            'project_id'    => $projectId,
            'version'       => $version,
            'changelog'     => 'Fixed things',
            'zip_path'      => '/tmp/release.zip',
            'manifest_path' => '/tmp/manifest.json',
            'is_latest'     => $isLatest ? 1 : 0,
            'released_at'   => '2026-06-01',
            'created_at'    => '2026-06-01 12:00:00',
        ], true);
    }

    public function testUnknownSlugReturns404(): void
    {
        $result = $this->get('api/nope/latest.json');

        $result->assertStatus(404);
        $this->assertSame('Project not found', $result->getJSON() ? json_decode($result->getJSON(), true)['error'] : null);
    }

    public function testProjectWithoutAnyReleaseReturns404(): void
    {
        $this->makeProject('empty-app');

        $result = $this->get('api/empty-app/latest.json');

        $result->assertStatus(404);
        $this->assertSame('No latest release found', json_decode($result->getJSON(), true)['error']);
    }

    public function testPublicProjectReturnsTheExpectedShape(): void
    {
        $id = $this->makeProject('my-app');
        $this->makeRelease($id, '1.2.0');

        $result = $this->get('api/my-app/latest.json');

        $result->assertStatus(200);
        $body = json_decode($result->getJSON(), true);

        // The contract: exactly these keys, nothing missing.
        $this->assertSame(
            ['version', 'changelog', 'date', 'zip_url', 'manifest_url'],
            array_keys($body)
        );
        $this->assertSame('1.2.0', $body['version']);
        $this->assertSame('Fixed things', $body['changelog']);
        $this->assertSame('2026-06-01', $body['date']);
        $this->assertStringEndsWith('/api/my-app/files/1.2.0/release.zip', $body['zip_url']);
        $this->assertStringEndsWith('/api/my-app/files/1.2.0/manifest.json', $body['manifest_url']);
    }

    public function testOnlyTheReleaseFlaggedLatestIsServed(): void
    {
        $id = $this->makeProject('my-app');
        $this->makeRelease($id, '1.0.0', false);
        $this->makeRelease($id, '2.0.0', true);
        $this->makeRelease($id, '1.5.0', false);

        $result = $this->get('api/my-app/latest.json');

        $result->assertStatus(200);
        $this->assertSame('2.0.0', json_decode($result->getJSON(), true)['version']);
    }

    public function testProtectedProjectRejectsMissingToken(): void
    {
        $id = $this->makeProject('private-app', 's3cret-token');
        $this->makeRelease($id, '1.0.0');

        $result = $this->get('api/private-app/latest.json');

        $result->assertStatus(401);
        $this->assertSame('Unauthorized', json_decode($result->getJSON(), true)['error']);
    }

    public function testProtectedProjectRejectsWrongToken(): void
    {
        $id = $this->makeProject('private-app', 's3cret-token');
        $this->makeRelease($id, '1.0.0');

        $result = $this->withHeaders(['Authorization' => 'Bearer wrong-token'])
            ->get('api/private-app/latest.json');

        $result->assertStatus(401);
    }

    public function testProtectedProjectAcceptsCorrectToken(): void
    {
        $id = $this->makeProject('private-app', 's3cret-token');
        $this->makeRelease($id, '3.1.4');

        $result = $this->withHeaders(['Authorization' => 'Bearer s3cret-token'])
            ->get('api/private-app/latest.json');

        $result->assertStatus(200);
        $this->assertSame('3.1.4', json_decode($result->getJSON(), true)['version']);
    }
}
