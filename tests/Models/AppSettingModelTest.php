<?php

declare(strict_types=1);

use App\Models\AppSettingModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Forgelabme\Ci4Updater\Libraries\SettingsInterface;

/**
 * @internal
 */
final class AppSettingModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';

    private AppSettingModel $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = new AppSettingModel();
    }

    /**
     * ci4-updater resolves this class from Config\Updater::$settingsClass and
     * type-hints SettingsInterface. If the contract drifts, the update panel
     * dies at runtime rather than here — so assert it explicitly.
     */
    public function testSatisfiesTheUpdaterSettingsContract(): void
    {
        $this->assertInstanceOf(SettingsInterface::class, $this->settings);
    }

    public function testGetSettingReturnsTheDefaultWhenKeyIsAbsent(): void
    {
        $this->assertNull($this->settings->getSetting('does_not_exist'));
        $this->assertSame('fallback', $this->settings->getSetting('does_not_exist', 'fallback'));
    }

    public function testSetSettingCreatesThenReadsBack(): void
    {
        $this->settings->setSetting('update_server_url', 'https://updates.example.com/api/my-app');

        $this->assertSame(
            'https://updates.example.com/api/my-app',
            $this->settings->getSetting('update_server_url')
        );
    }

    public function testSetSettingUpdatesInPlaceWithoutDuplicating(): void
    {
        $this->settings->setSetting('update_server_token', 'first');
        $this->settings->setSetting('update_server_token', 'second');

        $this->assertSame('second', $this->settings->getSetting('update_server_token'));
        $this->seeNumRecords(1, 'app_settings', ['key' => 'update_server_token']);
    }

    public function testStoresAnEmptyValue(): void
    {
        $this->settings->setSetting('update_server_url', 'https://example.com');
        $this->settings->setSetting('update_server_url', '');

        $this->assertSame('', $this->settings->getSetting('update_server_url'));
    }
}
