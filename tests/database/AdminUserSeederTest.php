<?php

declare(strict_types=1);

use App\Database\Seeds\AdminUserSeeder;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * `php spark db:seed AdminUserSeeder` is the documented way to create the
 * first admin, and re-running it is the documented lockout recovery path —
 * so both the create and the re-run behaviour are pinned here.
 *
 * $namespace is null so Shield's auth tables get migrated too, not just the
 * app's own.
 *
 * @internal
 */
final class AdminUserSeederTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace;

    private const EMAIL  = 'admin@example.com';
    private const HASH_A = '$2y$12$T4ncrT1Pv/I1H4VY.bZYUOK0om6lSDUeC./HDsPv6lF64WXEZaQZe';
    private const HASH_B = '$2y$12$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv(self::EMAIL, self::HASH_A);
    }

    protected function tearDown(): void
    {
        $this->clearEnv();
        parent::tearDown();
    }

    private function setEnv(string $email, string $hash): void
    {
        $_ENV['ADMIN_EMAIL']            = $_SERVER['ADMIN_EMAIL'] = $email;
        $_ENV['ADMIN_PASSWORD_HASH']    = $_SERVER['ADMIN_PASSWORD_HASH'] = $hash;
        putenv('ADMIN_EMAIL=' . $email);
        putenv('ADMIN_PASSWORD_HASH=' . $hash);
    }

    private function clearEnv(): void
    {
        foreach (['ADMIN_EMAIL', 'ADMIN_PASSWORD_HASH'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
    }

    /**
     * The seeder echoes progress; swallow it so PHPUnit's strict output
     * checking stays happy, and return it for assertions.
     */
    private function runSeeder(): string
    {
        ob_start();

        try {
            $this->seed(AdminUserSeeder::class);
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    public function testCreatesTheAdminAccountFromEnv(): void
    {
        $output = $this->runSeeder();

        $this->seeNumRecords(1, 'users', []);
        $this->seeInDatabase('auth_identities', [
            'secret' => self::EMAIL,
            'secret2' => self::HASH_A,
        ]);
        $this->assertStringContainsString('created', $output);
    }

    public function testDerivesTheUsernameFromTheEmail(): void
    {
        $this->runSeeder();

        $this->seeInDatabase('users', ['username' => 'admin', 'active' => 1]);
    }

    /**
     * Re-running must not create a second account — and must re-sync the
     * password hash from .env, which is what makes it a recovery path.
     */
    public function testReRunningIsIdempotentAndResyncsThePassword(): void
    {
        $this->runSeeder();

        $this->setEnv(self::EMAIL, self::HASH_B);
        $output = $this->runSeeder();

        $this->seeNumRecords(1, 'users', []);
        $this->seeInDatabase('auth_identities', [
            'secret'  => self::EMAIL,
            'secret2' => self::HASH_B,
        ]);
        $this->assertStringContainsString('updated', $output);
    }

    public function testReRunningReactivatesADisabledAccount(): void
    {
        $this->runSeeder();

        /** @var UserModel $users */
        $users = model(UserModel::class);
        $user  = $users->findByCredentials(['email' => self::EMAIL]);
        $user->active = 0;
        $users->save($user);

        $this->runSeeder();

        $this->seeInDatabase('users', ['username' => 'admin', 'active' => 1]);
    }

    public function testThrowsWhenTheCredentialsAreMissingFromEnv(): void
    {
        $this->clearEnv();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ADMIN_EMAIL and ADMIN_PASSWORD_HASH must be set');

        $this->runSeeder();
    }
}
