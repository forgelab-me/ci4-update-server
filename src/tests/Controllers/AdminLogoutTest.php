<?php

declare(strict_types=1);

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Logging out changes state, so it is a POST behind the CSRF filter. As a GET,
 * any page the admin happens to open could end their session with nothing more
 * than an <img src="/auth/logout">.
 *
 * @internal
 */
final class AdminLogoutTest extends CIUnitTestCase
{
    use AuthenticationTesting;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    /** Null so Shield's tables (users, identities) are migrated too. */
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

    public function testLoggingOutIsAPostThatEndsTheSession(): void
    {
        $admin = $this->admin();

        $r = $this->actingAs($admin)->call('post', 'auth/logout', [csrf_token() => csrf_hash()]);

        $r->assertRedirectTo(site_url('auth/login'));
        self::assertFalse(auth()->loggedIn());
    }

    public function testALogoutPostWithoutACsrfTokenIsRefused(): void
    {
        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);

        $this->actingAs($this->admin())->call('post', 'auth/logout');
    }

    /**
     * The point: following a link, or loading an image, must not log anyone out.
     */
    public function testALogoutGetDoesNotEndTheSession(): void
    {
        $admin = $this->admin();

        try {
            $status = $this->actingAs($admin)->call('get', 'auth/logout')->response()->getStatusCode();
        } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
            $status = 404;
        }

        self::assertSame(404, $status);
        self::assertTrue(auth()->loggedIn());
    }

    public function testThePageOffersLogoutAsAFormWithAToken(): void
    {
        $html = $this->actingAs($this->admin())->call('get', 'dashboard')->getBody();

        self::assertMatchesRegularExpression('#<form method="POST" action="[^"]*auth/logout"[^>]*>\s*<input type="hidden" name="' . preg_quote(csrf_token(), '#') . '"#', $html);
        self::assertStringNotContainsString('href="' . site_url('auth/logout') . '"', $html);
    }
}
