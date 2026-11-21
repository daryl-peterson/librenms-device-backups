<?php

namespace DRP\DeviceBackups\Tests\Database;

use DRP\DeviceBackups\PluginDb;
use DRP\DeviceBackups\Tests\Support\DatabaseTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DatabaseSetupTest extends DatabaseTestCase {
    public function testSavedSettingsNeedCurrentMigrationConfirmation(): void {
        $saved = ['database_connection' => ['database' => 'backups']];

        self::assertFalse(PluginDb::isReady(['database_migrations' => PluginDb::migrationVersion()]));
        self::assertFalse(PluginDb::isReady($saved + ['database_migrations' => false]));
        self::assertFalse(PluginDb::isReady($saved + ['database_migrations' => 'older-migrations']));
        self::assertTrue(PluginDb::isReady($saved + ['database_migrations' => PluginDb::migrationVersion()]));
    }

    public function testExistingInstallationsNeedAllMigrationRecords(): void {
        $saved = ['database_connection' => ['database' => 'backups']];
        self::assertFalse(PluginDb::isReady($saved));

        Schema::create('migrations', function (Blueprint $table): void {
            $table->string('migration');
        });
        $files = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');
        foreach ($files as $file) {
            DB::table('migrations')->insert(['migration' => basename($file, '.php')]);
        }
        self::assertTrue(PluginDb::isReady($saved));
        self::assertFalse(PluginDb::isReady($saved + ['database_migrations' => false]));

        DB::table('migrations')->where('migration', basename($files[0], '.php'))->delete();
        self::assertFalse(PluginDb::isReady($saved));
    }
}
