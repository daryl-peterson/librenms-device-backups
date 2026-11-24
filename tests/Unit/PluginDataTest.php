<?php

namespace DRP\DeviceBackups\Tests\Unit;

use DRP\DeviceBackups\PluginData;
use DRP\DeviceBackups\PluginSettings;
use DRP\DeviceBackups\Tests\Support\PluginFixture;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

use const DRP\DeviceBackups\PLUGIN_NAME;
use const DRP\DeviceBackups\PLUGIN_TITLE;

final class PluginDataTest extends TestCase {
    private const SETTINGS = ['backup_report_email' => 'backups@example.test'];

    private ?Container $previousApp;
    private array $previousRecords;
    private Router $router;

    protected function setUp(): void {
        parent::setUp();

        $this->previousRecords = PluginFixture::$records;
        PluginFixture::$records = [
            new PluginFixture(PLUGIN_NAME, 1, []),
            new PluginFixture('other-plugin', 2, []),
            new PluginFixture(PLUGIN_NAME, 2, self::SETTINGS),
        ];

        $app = new Container();
        $this->router = new Router(new Dispatcher($app), $app);
        $this->router->get('/settings', static fn () => '')->name(PLUGIN_NAME . '.settings');
        $this->router->getRoutes()->refreshNameLookups();
        $app->instance('router', $this->router);
        $app->instance('cache', new Repository(new ArrayStore()));

        $this->previousApp = Facade::getFacadeApplication();
        Facade::clearResolvedInstance('router');
        Facade::clearResolvedInstance('cache');
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void {
        Facade::clearResolvedInstance('router');
        Facade::clearResolvedInstance('cache');
        Facade::setFacadeApplication($this->previousApp);
        PluginFixture::$records = $this->previousRecords;

        parent::tearDown();
    }

    public function testGetData(): void {
        $data = PluginData::getData();

        self::assertSame(PLUGIN_NAME, $data['info']['name']);
        self::assertSame(PLUGIN_TITLE, $data['info']['title']);
        self::assertFalse($data['dbIsReady']);
        self::assertSame(self::SETTINGS, $data['settings']);
    }

    public function testGetInstance(): void {
        $instance = PluginData::getInstance();

        self::assertSame(PLUGIN_NAME . '.settings', $instance->menu);
        self::assertTrue($instance->hasMenuRoute);

        $this->router->get('/backups', static fn () => '')->name(PLUGIN_NAME . '.backups.status');
        $this->router->getRoutes()->refreshNameLookups();

        $updated = PluginData::getInstance();
        self::assertNotSame($instance, $updated);
        self::assertSame(PLUGIN_NAME . '.backups.status', $updated->menu);
    }

    public function testGetSettings(): void {
        self::assertSame(self::SETTINGS, PluginData::getSettings());
    }

    public function testGetSettingValue(): void {
        $settings = new PluginSettings();

        self::assertSame(self::SETTINGS['backup_report_email'], $settings->get('backup_report_email'));
        self::assertNull($settings->get('nonexistent_key'));
    }
}
