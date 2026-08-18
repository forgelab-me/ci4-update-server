<?php

declare(strict_types=1);

namespace Config;

use Forgelabme\TrustedPublishing\Config\TrustedPublishing as BaseConfig;
use Forgelabme\TrustedPublishing\Providers\GithubActions;

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
}
