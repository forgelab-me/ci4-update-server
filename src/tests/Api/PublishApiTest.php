<?php

declare(strict_types=1);

use App\Models\ProjectModel;
use App\Models\ReleaseModel;
use App\Models\TrustedPublisherModel;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Forgelabme\TrustedPublishing\Identity;
use Forgelabme\TrustedPublishing\Jwks\CachedJwks;
use Forgelabme\TrustedPublishing\Providers\GithubActions;
use Forgelabme\TrustedPublishing\Providers\GitlabCi;
use Forgelabme\TrustedPublishing\ScopedTokens;

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
    private function identity(array $over = []): Identity
    {
        $claims = array_merge([
            'repository'          => 'forgelab-me/my-app',
            'repository_owner_id' => '10387667',
            'environment'         => '',
            'workflow'            => '',
        ], $over);

        return new Identity(
            provider: 'github',
            repository: $claims['repository'],
            ownerId: $claims['repository_owner_id'],
            environment: $claims['environment'],
            workflow: $claims['workflow'],
        );
    }

    public function testAMatchingWorkflowIsRecognised(): void
    {
        $id = $this->makeProject();
        $this->publisher($id);

        self::assertNotNull((new TrustedPublisherModel())->match($id, $this->identity()));
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

        $impostor = $this->identity(['repository_owner_id' => '999999']);

        self::assertNull((new TrustedPublisherModel())->match($id, $impostor));
    }

    public function testTheRepositoryMustMatchEvenWhenTheOwnerIdDoes(): void
    {
        $id = $this->makeProject();
        $this->publisher($id);

        self::assertNull((new TrustedPublisherModel())->match($id, $this->identity([
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

        self::assertNull($publishers->match($id, $this->identity()), 'no environment presented');
        self::assertNull($publishers->match($id, $this->identity(['environment' => 'staging'])));
        self::assertNotNull($publishers->match($id, $this->identity(['environment' => 'release'])));
    }

    /**
     * Pinned one level lower than environment: not just which repository and
     * environment, but which pipeline file is trusted to run in them.
     */
    public function testAConfiguredWorkflowIsRequired(): void
    {
        $id = $this->makeProject();
        $this->publisher($id, ['workflow' => 'forgelab-me/my-app/.github/workflows/release.yml']);

        $publishers = new TrustedPublisherModel();

        self::assertNull($publishers->match($id, $this->identity()), 'no workflow presented');
        self::assertNull($publishers->match($id, $this->identity(['workflow' => 'some/other/workflow.yml'])));
        self::assertNotNull($publishers->match($id, $this->identity([
            'workflow' => 'forgelab-me/my-app/.github/workflows/release.yml',
        ])));
    }

    public function testAPublisherOfAnotherProjectDoesNotMatch(): void
    {
        $mine   = $this->makeProject('my-app');
        $theirs = $this->makeProject('other-app');
        $this->publisher($theirs);

        self::assertNull((new TrustedPublisherModel())->match($mine, $this->identity()));
    }

    public function testClaimsWithoutARepositoryNeverMatch(): void
    {
        $id = $this->makeProject();
        $this->publisher($id);

        $publishers = new TrustedPublisherModel();

        self::assertNull($publishers->match($id, $this->identity(['repository' => ''])));
        self::assertNull($publishers->match($id, $this->identity(['repository_owner_id' => ''])));
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

    /**
     * A refusal has to name its cause. A release archive shipping vendor/ is
     * large enough to hit PHP's own limits, and an upload stopped there looks
     * exactly like a corrupt archive from the outside.
     */
    public function testAnUploadRefusedByPhpSaysWhy(): void
    {
        $id  = $this->makeProject();
        $zip = $this->zipWith('1.4.0');

        $tooBig = new \CodeIgniter\HTTP\Files\UploadedFile(
            $zip,
            'release.zip',
            'application/zip',
            filesize($zip),
            UPLOAD_ERR_INI_SIZE
        );

        $result = (new \App\Libraries\ReleaseIngestor(new ReleaseModel()))->ingest(
            (new ProjectModel())->find($id),
            $tooBig,
            []
        );

        self::assertFalse($result['success']);
        self::assertStringContainsString('upload_max_filesize', $result['error']);
    }

    /**
     * post_max_size exceeded, or a proxy that rewrote the request: the field is
     * there but PHP never registered an upload.
     */
    public function testAnUploadThatNeverArrivedSaysSo(): void
    {
        $id  = $this->makeProject();
        $zip = $this->zipWith('1.4.0');

        $notAnUpload = new \CodeIgniter\HTTP\Files\UploadedFile(
            $zip,
            'release.zip',
            'application/zip',
            filesize($zip),
            UPLOAD_ERR_OK
        );

        $result = (new \App\Libraries\ReleaseIngestor(new ReleaseModel()))->ingest(
            (new ProjectModel())->find($id),
            $notAnUpload,
            []
        );

        self::assertFalse($result['success']);
        self::assertStringContainsString('post_max_size', $result['error']);
    }

    // -- Expired tokens --------------------------------------------------------

    /**
     * Shield refuses an expired token but never deletes it, and Trusted
     * Publishing mints one per workflow run. Left alone they bury the
     * long-lived tokens an admin actually needs to find and revoke.
     */
    public function testMintingClearsOutTheExpiredTokensItLeftBehind(): void
    {
        $user = $this->ciUser();

        $user->generateAccessToken('run 41', ['publish:my-app'], Time::now()->subMinutes(30));
        $user->generateAccessToken('run 42', ['publish:my-app'], Time::now()->subMinutes(5));
        $user->generateAccessToken('laptop', ['publish:my-app']);
        $user->generateAccessToken('other project', ['publish:other-app'], Time::now()->subMinutes(5));

        self::assertSame(2, ScopedTokens::purgeExpired('publish:my-app'));

        // The one without an expiry stays: expired is not the same as unused.
        self::assertSame(['laptop'], array_column(ScopedTokens::withScope('publish:my-app'), 'name'));

        // And another project's expired token is none of our business.
        self::assertCount(1, ScopedTokens::withScope('publish:other-app'));
    }

    public function testPurgingLeavesTokensThatHaveNotExpiredYet(): void
    {
        $user = $this->ciUser();
        $user->generateAccessToken('long run', ['publish:my-app'], Time::now()->addMinutes(15));

        self::assertSame(0, ScopedTokens::purgeExpired('publish:my-app'));
        self::assertCount(1, ScopedTokens::withScope('publish:my-app'));
    }

    /**
     * The whole exchange, end to end: a token signed by a key the server
     * fetches from GitHub's JWKS, a matching trusted publisher, and a scoped
     * credential coming back — with the previous run's dead token swept up on
     * the way through.
     */
    public function testAWorkflowGetsAScopedTokenAndTheDeadOnesGo(): void
    {
        $id   = $this->makeProject();
        $user = $this->ciUser();
        $this->publisher($id, ['user_id' => $user->id]);

        $stale = $user->generateAccessToken('run 41', ['publish:my-app'], Time::now()->subMinutes(30));

        $r = $this->withHeaders(['Authorization' => 'Bearer ' . $this->githubToken()])
            ->call('post', 'api/my-app/publish/token');

        $r->assertStatus(200);

        $body = json_decode($r->getJSON(), true);
        self::assertSame('publish:my-app', $body['scope']);
        self::assertSame(900, $body['expires_in']);
        self::assertNotEmpty($body['token']);

        $live = ScopedTokens::withScope('publish:my-app');
        self::assertCount(1, $live, 'the expired token from the previous run is gone');
        self::assertNotSame('run 41', $live[0]['name']);
        self::assertNull((new \CodeIgniter\Shield\Models\UserIdentityModel())->find($stale->id));
    }

    public function testATokenMintedForAnotherAudienceIsRefused(): void
    {
        $id   = $this->makeProject();
        $user = $this->ciUser();
        $this->publisher($id, ['user_id' => $user->id]);

        $jwt = $this->githubToken(['aud' => 'https://someone-elses-server.example.com']);

        $r = $this->withHeaders(['Authorization' => 'Bearer ' . $jwt])
            ->call('post', 'api/my-app/publish/token');

        $r->assertStatus(401);
        self::assertStringContainsString('different audience', $r->getJSON());
    }

    /**
     * The other configured provider, exercised end to end: a token signed by
     * a key the server fetches from GitLab's JWKS, matching a publisher
     * trusted with provider=gitlab, and a scoped credential coming back.
     */
    public function testAGitlabWorkflowGetsAScopedToken(): void
    {
        $id   = $this->makeProject();
        $user = $this->ciUser();
        $this->publisher($id, [
            'user_id'  => $user->id,
            'provider' => 'gitlab',
        ]);

        $r = $this->withHeaders(['Authorization' => 'Bearer ' . $this->gitlabToken()])
            ->call('post', 'api/my-app/publish/token');

        $r->assertStatus(200);

        $body = json_decode($r->getJSON(), true);
        self::assertSame('publish:my-app', $body['scope']);
    }

    /**
     * Which provider verifies a token is picked from its own (unverified)
     * `iss` claim, precisely so a token from neither configured CI system is
     * refused immediately — without a live fetch of every provider's JWKS on
     * the way to finding out none of them signed it.
     */
    public function testATokenFromAnUnknownIssuerIsRefusedWithoutTryingEveryProvider(): void
    {
        $this->makeProject();

        $b64u    = static fn (string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
        $header  = $b64u((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $b64u((string) json_encode(['iss' => 'https://issuer.example.com']));

        $r = $this->withHeaders(['Authorization' => 'Bearer ' . "{$header}.{$payload}.sig"])
            ->call('post', 'api/my-app/publish/token');

        $r->assertStatus(401);
        self::assertStringContainsString('not issued by a CI system this server trusts', $r->getJSON());
    }

    /**
     * A signature from any other key is worthless, however well-formed the
     * claims are.
     */
    public function testATokenSignedByAnotherKeyIsRefused(): void
    {
        $id   = $this->makeProject();
        $user = $this->ciUser();
        $this->publisher($id, ['user_id' => $user->id]);

        $impostor = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($impostor, $pem);

        $jwt = \Firebase\JWT\JWT::encode($this->githubClaims(), $pem, 'RS256', 'github-test-key');

        $r = $this->withHeaders(['Authorization' => 'Bearer ' . $jwt])
            ->call('post', 'api/my-app/publish/token');

        $r->assertStatus(401);
    }

    private function ciUser(): \CodeIgniter\Shield\Entities\User
    {
        $users = new \CodeIgniter\Shield\Models\UserModel();
        $users->save(new \CodeIgniter\Shield\Entities\User([
            'username' => 'ci' . bin2hex(random_bytes(3)),
            'email'    => bin2hex(random_bytes(4)) . '@example.com',
            'password' => 'sup3r-s3cret-pass',
        ]));

        return $users->findById($users->getInsertID());
    }

    /**
     * @return array<string, mixed>
     */
    private function githubClaims(array $over = []): array
    {
        return array_merge([
            'iss'                 => GithubActions::ISSUER,
            'aud'                 => rtrim(base_url(), '/'),
            'sub'                 => 'repo:forgelab-me/my-app:ref:refs/tags/v1.0.0',
            'repository'          => 'forgelab-me/my-app',
            'repository_owner_id' => '10387667',
            'environment'         => '',
            'iat'                 => time() - 10,
            'exp'                 => time() + 300,
        ], $over);
    }

    /**
     * A token GitHub would have signed — except the key is ours, and the JWKS
     * the server would fetch is seeded into its cache.
     */
    private function githubToken(array $over = []): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $details = openssl_pkey_get_details($key);

        $b64u = static fn (string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

        // Seeded where the package would have cached what it fetched from
        // GitHub, so the real verification path runs against our own key.
        service('cache')->save(CachedJwks::cacheKey(new GithubActions()), ['keys' => [[
            'kty' => 'RSA',
            'kid' => 'github-test-key',
            'use' => 'sig',
            'alg' => 'RS256',
            'n'   => $b64u($details['rsa']['n']),
            'e'   => $b64u($details['rsa']['e']),
        ]]], 60);

        return \Firebase\JWT\JWT::encode($this->githubClaims($over), $pem, 'RS256', 'github-test-key');
    }

    /**
     * @return array<string, mixed>
     */
    private function gitlabClaims(array $over = []): array
    {
        return array_merge([
            'iss'               => 'https://gitlab.com',
            'aud'               => rtrim(base_url(), '/'),
            'project_path'      => 'forgelab-me/my-app',
            'namespace_id'      => '10387667',
            'environment'       => '',
            'ci_config_ref_uri' => 'gitlab.com/forgelab-me/my-app//.gitlab-ci.yml@refs/heads/main',
            'iat'               => time() - 10,
            'exp'               => time() + 300,
        ], $over);
    }

    /**
     * A token GitLab would have signed — except the key is ours, and the JWKS
     * the server would fetch is seeded into its cache.
     */
    private function gitlabToken(array $over = []): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $details = openssl_pkey_get_details($key);

        $b64u = static fn (string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

        service('cache')->save(CachedJwks::cacheKey(new GitlabCi()), ['keys' => [[
            'kty' => 'RSA',
            'kid' => 'gitlab-test-key',
            'use' => 'sig',
            'alg' => 'RS256',
            'n'   => $b64u($details['rsa']['n']),
            'e'   => $b64u($details['rsa']['e']),
        ]]], 60);

        return \Firebase\JWT\JWT::encode($this->gitlabClaims($over), $pem, 'RS256', 'gitlab-test-key');
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
