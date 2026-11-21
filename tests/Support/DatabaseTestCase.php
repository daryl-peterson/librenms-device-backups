<?php

namespace DRP\DeviceBackups\Tests\Support;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

abstract class DatabaseTestCase extends TemporaryFilesTestCase {
    private Container $previousContainer;
    private ?Container $previousFacadeApplication;

    protected function setUp(): void {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();

        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new Repository([
            'database' => [
                'default' => 'device_backups',
                'connections' => [
                    'device_backups' => [
                        'driver' => 'sqlite',
                        'database' => ':memory:',
                        'foreign_key_constraints' => true,
                    ],
                ],
            ],
        ]));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $app->register(DatabaseServiceProvider::class);

        foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*.php') as $file) {
            (require $file)->up();
        }
    }

    protected function tearDown(): void {
        DB::purge('device_backups');
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }
}
