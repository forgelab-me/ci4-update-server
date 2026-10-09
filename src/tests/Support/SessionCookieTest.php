<?php

declare(strict_types=1);

use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Cookie;

/**
 * The session cookie is the admin's credential. A deployment that forces HTTPS
 * should not also let that cookie travel over plain HTTP — and a development
 * setup over http:// must still be able to hold a session.
 *
 * @internal
 */
final class SessionCookieTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        Factories::reset('config');

        parent::tearDown();
    }

    private function cookieWhenHttpsForced(bool $forced): Cookie
    {
        $app = new App();
        $app->forceGlobalSecureRequests = $forced;
        Factories::injectMock('config', 'App', $app);

        return new Cookie();
    }

    public function testTheCookieIsSecureWhenHttpsIsForced(): void
    {
        self::assertTrue($this->cookieWhenHttpsForced(true)->secure);
    }

    public function testTheCookieIsLeftAloneWhenHttpsIsNotForced(): void
    {
        self::assertFalse($this->cookieWhenHttpsForced(false)->secure);
    }

    public function testTheCookieStaysHttpOnlyAndSameSiteLaxEitherWay(): void
    {
        foreach ([true, false] as $forced) {
            $cookie = $this->cookieWhenHttpsForced($forced);

            self::assertTrue($cookie->httponly);
            self::assertSame('Lax', $cookie->samesite);
        }
    }
}
