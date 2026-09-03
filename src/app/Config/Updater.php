<?php

declare(strict_types=1);

namespace Config;

use Forgelabme\Ci4Updater\Config\Updater as BaseUpdater;

/**
 * Self-update system configuration for this app.
 * Bump VERSION / DATE before each release, then run `php spark update:manifest`.
 */
class Updater extends BaseUpdater
{
    public const VERSION = '1.10.2';

    public const DATE = '2026-09-03';

    public const USER_AGENT = 'UpdateServerAdmin/1.0';

    // This app's layout is 'layouts/main' (plural), not the package default.
    public string $layout = 'layouts/main';

    public ?string $appName = 'Update Server';

    public array $allowedRoots = ['app', 'public', 'vendor'];

    // A release that ships vendor/ (dependencies moved) also ships these, so
    // the two never describe different dependency sets on disk. The publish
    // workflow passes them via `update:manifest --files` precisely when it
    // ships vendor — see .github/workflows/publish.yml.
    public array $allowedFiles = ['composer.json', 'composer.lock'];

    // Reuse the app's own app_settings table instead of a JSON file in writable/.
    public string $settingsClass = \App\Models\AppSettingModel::class;

    // List a public key here to require signed releases: from then on an
    // unsigned release is refused. Generate a pair with
    // `php spark updater:keygen` — see the package's docs/signing.md.
    public array $publicKeys = [APPPATH . 'Config/Keys/release-signing.pub'];
}
