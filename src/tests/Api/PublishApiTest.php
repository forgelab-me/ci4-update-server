<?php

declare(strict_types=1);

use App\Models\ProjectModel;
use App\Models\ReleaseModel;
use App\Models\TrustedPublisherModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Publishing a release from CI.
 *
 * The credential is high value — it publishes code that connected apps write
 * into app/ — so what it may *not* do matters as much as what it may.
 *
 * @internal
 */
final class PublishApiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    /**
     * Null so Shield's auth tables are migrated too: the token filter records
     * every attempt, including refused ones, in auth_token_logins.
     */
    protected $namespace;

    private function makeProject(string $slug = 'my-app'): int
    {
        return (int) (new ProjectModel())->insert([
            'name' => ucfirst($slug), 'slug' => $slug, 'created_at' => '2026-01-01 00:00:00',
        ], true);
    }

    // -- Matching a trusted publisher -----------------------------------------

    private function publisher(int $projectId, array $over = []): void
    {
        (new TrustedPublisherModel())->insert(array_merge([
            'project_id'          => $projectId,
            'user_id'             => 1,
            'provider'            => 'github',
            'repository'          => 'forgelab-me/my-app',
            'repository_owner_id' => '10387667',
            'environment'         => null,
            'created_at'          => '2026-01-01 00:00:00',
        ], $over));
    }

    /**
     * @param array<string, mixed> $over
     *
     * @return array<string, mixed>
     */
    private function claims(array $over = []): array
    {
        return array_merge([
            'repository'          => 'forgelab-me/my-app',
            'repository_owner_id' => '10387667',
            'environment'         => '',
        ], $over);
    }

    public function testAMatchingWorkflowIsRecognised(): void
    {
        $id = $this->makeProject();
        $this->publisher($id);

        self::assertNotNull((new TrustedPublisherModel())->match($id, $this->claims()));
    }

    /**
     * The whole reason the numeric id is stored. A repository name can be
     * renamed, or freed and claimed by someone else; the account id never is.
     * Matching on the name alone would mean losing the name loses the project.
     */
    public function testTheOwnerIdMustMatchEvenWhenTheNameDoes(): void
    {
        $id = $this->makeProject();
        $this->publisher($id);

        $impostor = $this->claims(['repository_owner_id' => '999999']);

        self::assertNull((new TrustedPublisherModel())->match($id, $impostor));
    }

    public function testTheRepositoryMustMatchEvenWhenTheOwnerIdDoes(): void
    {
        $id = $this->makeProject();
        $this->publisher($id);

        self::assertNull((new TrustedPublisherModel())->match($id, $this->claims([
            'repository' => 'forgelab-me/some-other-repo',
        ])));
    }

    /**
     * A configured environment is a requirement, not a preference — it is what
     * puts a protected environment's approval between a push and a publish.
     */
    public function testAConfiguredEnvironmentIsRequired(): void
    {
        $id = $this->makeProject();
        $this->publisher($id, ['environment' => 'release']);

        $publishers = new TrustedPublisherModel();

        self::assertNull($publishers->match($id, $this->claims()), 'no environment presented');
        self::assertNull($publishers->match($id, $this->claims(['environment' => 'staging'])));
        self::assertNotNull($publishers->match($id, $this->claims(['environment' => 'release'])));
    }

    public function testAPublisherOfAnotherProjectDoesNotMatch(): void
    {
        $mine   = $this->makeProject('my-app');
        $theirs = $this->makeProject('other-app');
        $this->publisher($theirs);

        self::assertNull((new TrustedPublisherModel())->match($mine, $this->claims()));
    }

    public function testClaimsWithoutARepositoryNeverMatch(): void
    {
        $id = $this->makeProject();
        $this->publisher($id);

        $publishers = new TrustedPublisherModel();

        self::assertNull($publishers->match($id, $this->claims(['repository' => ''])));
        self::assertNull($publishers->match($id, $this->claims(['repository_owner_id' => ''])));
    }

    // -- The exchange endpoint -------------------------------------------------

    public function testMintingRefusesWithoutABearerToken(): void
    {
        $this->makeProject();

        $r = $this->call('post', 'api/my-app/publish/token');

        $r->assertStatus(401);
    }

    public function testMintingRefusesGarbageInsteadOfAToken(): void
    {
        $this->makeProject();

        $r = $this->withHeaders(['Authorization' => 'Bearer not-a-jwt'])
            ->call('post', 'api/my-app/publish/token');

        // Rejected without ever reaching the trusted-publisher lookup.
        $r->assertStatus(401);
    }

    public function testMintingOnAnUnknownProjectIs404(): void
    {
        $r = $this->withHeaders(['Authorization' => 'Bearer x'])
            ->call('post', 'api/nope/publish/token');

        $r->assertStatus(404);
    }

    // -- The upload endpoint ---------------------------------------------------

    public function testUploadingRefusesWithoutAToken(): void
    {
        $this->makeProject();

        $r = $this->call('post', 'api/my-app/releases');

        // Shield's token filter answers before the controller is reached.
        self::assertContains($r->response()->getStatusCode(), [401, 403]);
    }

    /**
     * Nothing was published, so nothing must exist.
     */
    public function testARefusedUploadCreatesNoRelease(): void
    {
        $id = $this->makeProject();

        $this->call('post', 'api/my-app/releases');

        self::assertSame([], (new ReleaseModel())->getForProject($id));
    }

    // -- What the API may ask for ---------------------------------------------

    /**
     * The guarantee the whole design rests on: a credential that can publish
     * still cannot decide what apps are served. Promotion stays a human act in
     * the panel, so a stolen token can only add a release nobody is served.
     */
    public function testTheApiCanNeverPromoteToLatest(): void
    {
        // Even asked outright, in every shape a caller could try.
        foreach ([['set_latest' => 1], ['set_latest' => 'true'], ['is_latest' => 1], []] as $post) {
            self::assertFalse(
                \App\Controllers\PublishController::uploadMeta($post)['set_latest'],
                'the API must never set latest'
            );
        }
    }

    public function testUploadMetaPassesTheRestThrough(): void
    {
        $meta = \App\Controllers\PublishController::uploadMeta([
            'changelog'   => '  Fixed things  ',
            'released_at' => '2026-06-01',
            'required'    => '1',
        ]);

        self::assertSame('Fixed things', $meta['changelog']);
        self::assertSame('2026-06-01', $meta['released_at']);
        self::assertTrue($meta['required']);
    }

    public function testUploadMetaDefaultsTheDateToToday(): void
    {
        self::assertSame(date('Y-m-d'), \App\Controllers\PublishController::uploadMeta([])['released_at']);
    }

    // -- Immutability ----------------------------------------------------------

    /**
     * Published bytes are immutable: an app that already downloaded a version
     * must never get different files under the same name. Enforced in the
     * ingestor, so the admin form and the API are covered by one rule.
     */
    public function testAVersionCannotBePublishedTwice(): void
    {
        $id = $this->makeProject();

        (new ReleaseModel())->insert([
            'project_id' => $id, 'version' => '1.4.0',
            'zip_path' => '/tmp/a.zip', 'manifest_path' => '/tmp/a.json',
            'is_latest' => 0, 'released_at' => '2026-06-01', 'created_at' => '2026-06-01 12:00:00',
        ]);

        $zip = $this->zipWith('1.4.0');

        // isValid() calls is_uploaded_file(), false for anything a test
        // creates — so the guard is reached through a double that reports a
        // real upload while pointing at a file we built.
        $file = new class ($zip, 'release.zip', 'application/zip', filesize($zip), UPLOAD_ERR_OK) extends \CodeIgniter\HTTP\Files\UploadedFile {
            public function isValid(): bool
            {
                return true;
            }

            public function move(string $targetPath, ?string $name = null, bool $overwrite = false): bool
            {
                $name = $name ?? $this->getName();
                copy($this->getTempName(), rtrim($targetPath, '/\\') . DIRECTORY_SEPARATOR . $name);

                return true;
            }
        };

        $result = (new \App\Libraries\ReleaseIngestor(new ReleaseModel()))->ingest(
            (new ProjectModel())->find($id),
            $file,
            ['set_latest' => false]
        );

        self::assertFalse($result['success']);
        self::assertStringContainsString('already exists', $result['error']);
        self::assertCount(1, (new ReleaseModel())->getForProject($id));
    }

    /**
     * A token scoped to one project must not reach another. The check happens
     * before the archive is even looked at, so no upload is needed to reach it.
     */
    public function testATokenScopedToAnotherProjectIsRefused(): void
    {
        $this->makeProject('my-app');
        $other = $this->makeProject('other-app');

        $users = new \CodeIgniter\Shield\Models\UserModel();
        $users->save(new \CodeIgniter\Shield\Entities\User([
            'username' => 'ci', 'email' => 'ci@example.com', 'password' => 'sup3r-s3cret-pass',
        ]));
        $token = $users->findById($users->getInsertID())
            ->generateAccessToken('test', ['publish:my-app'])->raw_token;

        $r = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->call('post', 'api/other-app/releases');

        $r->assertStatus(403);
        self::assertSame([], (new ReleaseModel())->getForProject($other));
    }

    private function zipWith(string $version): string
    {
        $dir = WRITEPATH . 'tmp/pub-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $path = $dir . '/release.zip';

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('manifest.json', json_encode([
            'version' => $version, 'roots' => ['app'], 'files' => ['app/x.php' => 'hash'],
        ]));
        $zip->addFromString('app/x.php', '<?php');
        $zip->close();

        return $path;
    }
}
