<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;
use Forgelabme\Ci4Updater\Libraries\SettingsInterface;

/**
 * Generic app_settings key/value store.
 *
 * Implements ci4-updater's SettingsInterface so the package persists its own
 * settings (update_server_url, update_server_token, last applied version…)
 * in this table instead of writable/updater_settings.json — wired up via
 * Config\Updater::$settingsClass.
 */
class AppSettingModel extends Model implements SettingsInterface
{
    protected $table      = 'app_settings';
    protected $primaryKey = 'key';

    protected $allowedFields = ['key', 'value'];

    protected $useTimestamps = false;

    public function getSetting(string $key, mixed $default = null): mixed
    {
        $row = $this->find($key);

        return $row ? $row['value'] : $default;
    }

    public function setSetting(string $key, mixed $value): void
    {
        if ($this->find($key)) {
            $this->update($key, ['value' => $value]);
        } else {
            $this->insert(['key' => $key, 'value' => $value]);
        }
    }
}
