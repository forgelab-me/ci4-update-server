<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Forgelabme\Ci4Updater\Libraries\MaintenanceWindow;

/**
 * The maintenance-window filter from `ci4-updater`: opt-in at the package
 * level, wired up in app/Config/Filters.php so this app's own routes — the
 * public API included — answer 503 while a self-update is writing files,
 * rather than being served through a half-written codebase.
 *
 * @internal
 */
final class MaintenanceFilterTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    /** Null so Shield's and Settings' tables are migrated too — admin/updates goes through both. */
    protected $namespace;

    protected function tearDown(): void
    {
        // A window left open by a failed assertion must not leak into the
        // next test — every other test in the suite assumes routes work.
        MaintenanceWindow::close();

        parent::tearDown();
    }

    public function testOrdinaryRequestsPassThroughWhenNoUpdateIsRunning(): void
    {
        $this->call('get', '/')->assertOK();
    }

    public function testEveryRouteAnswers503WhileAnUpdateIsWriting(): void
    {
        MaintenanceWindow::open('testing');

        $this->call('get', '/')->assertStatus(503);
    }

    /**
     * The one exemption that matters: an admin has to be able to reach the
     * panel to see the update finish, or the window would lock everyone out
     * of the only screen that can explain why.
     */
    public function testTheUpdatePanelStaysReachableDuringTheWindow(): void
    {
        MaintenanceWindow::open('testing');

        // Logged out, so this redirects — a 302, not the 503 every other
        // route gets. That redirect is the exemption working.
        $status = $this->call('get', 'admin/updates')->response()->getStatusCode();

        self::assertNotSame(503, $status);
    }

    public function testTheWindowClosesOnceLifted(): void
    {
        MaintenanceWindow::open('testing');
        MaintenanceWindow::close();

        $this->call('get', '/')->assertOK();
    }
}
