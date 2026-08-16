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
    public const VERSION = '1.5.0';

    public const DATE = '2026-08-07';

    public const USER_AGENT = 'UpdateServerAdmin/1.0';

    // This app's layout is 'layouts/main' (plural), not the package default.
    public string $layout = 'layouts/main';

    public ?string $appName = 'Update Server';

    // Reuse the app's own app_settings table instead of a JSON file in writable/.
    public string $settingsClass = \App\Models\AppSettingModel::class;
}
