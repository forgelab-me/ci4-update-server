<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Rate-limits the CI-facing publish endpoints (token exchange and upload) by
 * IP address.
 *
 * Unlike Shield's auth-rates, which redirects a browser back to a login form,
 * these are JSON API routes with no page to return to — so a throttled
 * request gets a 429 body instead.
 */
class PublishRates implements FilterInterface
{
    /**
     * @param array|null $arguments
     *
     * @return ResponseInterface|void
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $throttler = service('throttler');

        // 20 requests/minute/IP: generous for a CI pipeline's own retries,
        // tight enough to blunt scripted probing of token verification or
        // upload handling.
        if ($throttler->check(md5($request->getIPAddress()) . '_publish', 20, MINUTE, 1) === false) {
            return service('response')
                ->setStatusCode(429)
                ->setJSON(['error' => 'Too many requests. Try again in ' . $throttler->getTokenTime() . ' seconds.']);
        }
    }

    /**
     * @param array|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
        // Nothing required.
    }
}
