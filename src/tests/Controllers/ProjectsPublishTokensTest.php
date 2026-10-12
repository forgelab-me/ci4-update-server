<?php

declare(strict_types=1);

use App\Models\ProjectModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Forgelabme\TrustedPublishing\ScopedTokens;

/**
 * A publish token is scoped `publish:{slug}` — to a name, not to a project.
 * Deleting or renaming a project therefore has to take its tokens with it:
 * left alive, they would belong to whichever project next uses the slug.
 *
 * @internal
 */
final class ProjectsPublishTokensTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    /** Null so Shield's tables (users, tokens) are migrated too. */
    protected $namespace;

    protected function tearDown(): void
    {
        // actingAs() logs the user into the shared authenticator; left there, it
        // would make every later test in the run start out logged in.
        auth()->logout();

        parent::tearDown();
    }

    private function admin(): User
    {
        $users = new UserModel();
        $users->save(new User([
            'username' => 'admin' . bin2hex(random_bytes(3)),
            'email'    => bin2hex(random_bytes(4)) . '@example.com',
            'password' => 'sup3r-s3cret-pass',
        ]));

        return $users->findById($users->getInsertID());
    }

    private function makeProject(string $slug): int
    {
        return (int) (new ProjectModel())->insert([
            'name' => ucfirst($slug), 'slug' => $slug, 'created_at' => '2026-01-01 00:00:00',
        ], true);
    }

    /**
     * A real POST, CSRF token included — the filter is part of what is being
     * exercised, so it stays on.
     *
     * @param array<string, mixed> $data
     */
    private function post(User $as, string $uri, array $data = []): \CodeIgniter\Test\TestResponse
    {
        return $this->actingAs($as)->call('post', $uri, $data + [csrf_token() => csrf_hash()]);
    }

    /** @return list<string> the names of the live tokens carrying the scope */
    private function tokensFor(string $slug): array
    {
        return array_column(ScopedTokens::withScope('publish:' . $slug), 'name');
    }

    public function testDeletingAProjectRevokesItsPublishTokens(): void
    {
        $admin = $this->admin();
        $id    = $this->makeProject('my-app');
        $other = $this->makeProject('other-app');
        $admin->generateAccessToken('ci', ['publish:my-app']);
        $admin->generateAccessToken('ci', ['publish:other-app']);

        $this->post($admin, "projects/{$id}/delete")->assertRedirect();

        self::assertSame([], $this->tokensFor('my-app'));
        self::assertSame(['ci'], $this->tokensFor('other-app'), 'another project\'s tokens are none of its business');
        self::assertNotNull((new ProjectModel())->find($other));
    }

    /**
     * The scenario this exists for, end to end: a token outliving its project
     * must not work against the next project that takes the same slug.
     */
    public function testAnOldTokenDoesNotWorkOnAProjectThatReusesTheSlug(): void
    {
        $admin = $this->admin();
        $id    = $this->makeProject('my-app');
        $raw   = $admin->generateAccessToken('ci', ['publish:my-app'])->raw_token;

        $this->post($admin, "projects/{$id}/delete");
        $this->makeProject('my-app');

        $r = $this->withHeaders(['Authorization' => 'Bearer ' . $raw])->call('post', 'api/my-app/releases');

        $r->assertStatus(401);
    }

    public function testRenamingAProjectRevokesTheTokensOfItsOldSlug(): void
    {
        $admin = $this->admin();
        $id    = $this->makeProject('my-app');
        $admin->generateAccessToken('ci', ['publish:my-app']);

        $this->post($admin, "projects/{$id}/update", [
            'name' => 'My App', 'slug' => 'renamed-app', 'token' => '',
        ])->assertRedirect();

        self::assertSame('renamed-app', (new ProjectModel())->find($id)['slug']);
        self::assertSame([], $this->tokensFor('my-app'));
    }

    public function testEditingOtherFieldsLeavesTheTokensAlone(): void
    {
        $admin = $this->admin();
        $id    = $this->makeProject('my-app');
        $admin->generateAccessToken('ci', ['publish:my-app']);

        $this->post($admin, "projects/{$id}/update", [
            'name' => 'A better name', 'slug' => 'my-app', 'token' => '',
        ])->assertRedirect();

        self::assertSame('A better name', (new ProjectModel())->find($id)['name']);
        self::assertSame(['ci'], $this->tokensFor('my-app'));
    }

    /**
     * Normalisation can turn what was typed into nothing, and a project with
     * no slug has no API address — nor a sensible upload directory.
     */
    public function testAnEmptySlugIsRefusedAndNothingChanges(): void
    {
        $admin = $this->admin();
        $id    = $this->makeProject('my-app');
        $admin->generateAccessToken('ci', ['publish:my-app']);

        $this->post($admin, "projects/{$id}/update", [
            'name' => 'My App', 'slug' => '!!!', 'token' => '',
        ])->assertRedirect();

        self::assertSame('my-app', (new ProjectModel())->find($id)['slug']);
        self::assertSame(['ci'], $this->tokensFor('my-app'));
    }

    public function testASlugAnotherProjectHoldsIsRefusedAndNothingChanges(): void
    {
        $admin = $this->admin();
        $id    = $this->makeProject('my-app');
        $this->makeProject('taken');
        $admin->generateAccessToken('ci', ['publish:my-app']);

        $this->post($admin, "projects/{$id}/update", [
            'name' => 'My App', 'slug' => 'taken', 'token' => '',
        ])->assertRedirect();

        self::assertSame('my-app', (new ProjectModel())->find($id)['slug']);
        self::assertSame(['ci'], $this->tokensFor('my-app'));
    }
}
