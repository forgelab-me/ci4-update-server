<?php

declare(strict_types=1);

namespace Config;

use Forgelabme\TrustedPublishing\Config\TrustedPublishing as BaseConfig;
use Forgelabme\TrustedPublishing\Providers\GithubActions;
use Forgelabme\TrustedPublishing\Providers\GitlabCi;

/**
 * Which CI systems may exchange an identity token for a publish token here.
 */
class TrustedPublishing extends BaseConfig
{
    // Null means this server's own base URL, which is what the panel tells
    // workflows to request as their audience.
    public ?string $audience = null;

    public array $providers = [
        GithubActions::class,
    ];

    public function __construct()
    {
        parent::__construct();

        // gitlab.com. A provider taking a constructor argument can't be
        // listed as a property default (PHP disallows `new` there), so it's
        // appended here instead. For a self-hosted instance, swap this for
        // new GitlabCi('https://gitlab.example.com') — issuer and JWKS are
        // per-installation, which is why GitlabCi takes the URL at all.
        $this->providers[] = new GitlabCi();
    }
}
