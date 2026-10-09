<?php

declare(strict_types=1);

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * Behind a reverse proxy every request arrives from the proxy's address, so
 * the per-IP limits on login and publishing only mean something once the app
 * is told which proxies to believe about the real client.
 *
 * `Config\App::$proxyIPs` is an array BaseConfig cannot fill from the
 * environment, and the Docker image has no .env — hence TRUSTED_PROXIES.
 *
 * @internal
 */
final class TrustedProxiesTest extends CIUnitTestCase
{
    /** @var array<string, string|null> */
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }

        $this->saved = [];

        parent::tearDown();
    }

    private function setEnv(string $key, ?string $value): void
    {
        $this->saved[$key] ??= $_SERVER[$key] ?? null;

        if ($value === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $value;
        }
    }

    private function clientIpSeenBy(App $config, string $remoteAddr, ?string $forwardedFor): string
    {
        // setGlobal() writes through to the shared superglobals service and
        // would leave every later test with a $_SERVER holding nothing but
        // REMOTE_ADDR — so the real one is put back whatever happens.
        $superglobals = service('superglobals');
        $before       = $superglobals->getServerArray();

        try {
            $request = new IncomingRequest($config, new SiteURI($config), null, new UserAgent());
            $request->setGlobal('server', ['REMOTE_ADDR' => $remoteAddr]);

            if ($forwardedFor !== null) {
                $request->setHeader('X-Forwarded-For', $forwardedFor);
            }

            return $request->getIPAddress();
        } finally {
            $superglobals->setServerArray($before);
        }
    }

    public function testNoProxiesAreTrustedByDefault(): void
    {
        $this->setEnv('TRUSTED_PROXIES', null);

        self::assertSame([], (new App())->proxyIPs);
    }

    public function testProxiesComeFromTheEnvironment(): void
    {
        $this->setEnv('TRUSTED_PROXIES', '172.16.0.0/12, 10.0.0.1');

        self::assertSame(
            ['172.16.0.0/12' => 'X-Forwarded-For', '10.0.0.1' => 'X-Forwarded-For'],
            (new App())->proxyIPs
        );
    }

    public function testTheHeaderCanBeChosen(): void
    {
        $this->setEnv('TRUSTED_PROXIES', '10.0.0.1');
        $this->setEnv('TRUSTED_PROXY_HEADER', 'X-Real-IP');

        self::assertSame(['10.0.0.1' => 'X-Real-IP'], (new App())->proxyIPs);
    }

    /**
     * The failure this exists for: with nothing configured, the client behind
     * the proxy is invisible and every request looks like the proxy's.
     */
    public function testWithoutTheSettingEveryClientLooksLikeTheProxy(): void
    {
        $this->setEnv('TRUSTED_PROXIES', null);

        self::assertSame('172.18.0.1', $this->clientIpSeenBy(new App(), '172.18.0.1', '203.0.113.9'));
    }

    public function testWithTheSettingTheRealClientIsSeen(): void
    {
        $this->setEnv('TRUSTED_PROXIES', '172.16.0.0/12');

        self::assertSame('203.0.113.9', $this->clientIpSeenBy(new App(), '172.18.0.1', '203.0.113.9'));
    }

    /**
     * Trusting a proxy must not mean trusting a header from anyone else: a
     * client reaching the app directly could otherwise pick its own address
     * and sidestep the limits altogether.
     */
    public function testAHeaderFromAnUntrustedPeerIsIgnored(): void
    {
        $this->setEnv('TRUSTED_PROXIES', '172.16.0.0/12');

        self::assertSame('198.51.100.7', $this->clientIpSeenBy(new App(), '198.51.100.7', '203.0.113.9'));
    }
}
