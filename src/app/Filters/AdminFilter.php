<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AdminFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! auth()->loggedIn()) {
            return redirect()->to(site_url('auth/login'));
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void {}
}

