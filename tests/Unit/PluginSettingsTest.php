<?php

namespace DRP\DeviceBackups\Tests\Unit;

use DRP\DeviceBackups\PluginSettings;
use Illuminate\Container\Container;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\TestCase;

final class PluginSettingsTest extends TestCase {
    public function testASavedDatabaseRequiresAnArrayOfConnectionSettings(): void {
        self::assertFalse(PluginSettings::hasSavedDatabase([]));
        self::assertFalse(PluginSettings::hasSavedDatabase(['database_connection' => null]));
        self::assertFalse(PluginSettings::hasSavedDatabase(['database_connection' => 'backups']));
        self::assertTrue(PluginSettings::hasSavedDatabase(['database_connection' => ['database' => 'backups']]));
    }

    public function testResolvesSavedDatabaseCredentialsAndRejectsCorruptPasswords(): void {
        $encrypter = new Encrypter(Encrypter::generateKey('AES-256-CBC'), 'AES-256-CBC');
        $app = new Container();
        $app->instance('encrypter', $encrypter);
        $previousApp = Crypt::getFacadeApplication();
        Crypt::clearResolvedInstance('encrypter');
        Crypt::setFacadeApplication($app);

        try {
            $connection = [
                'database' => 'backups',
                'host' => 'database.example.test',
                'port' => 3307,
                'username' => 'backup_user',
            ];
            $settings = ['database_connection' => $connection + [
                'encrypted_password' => $encrypter->encryptString('test password'),
            ]];

            self::assertSame(
                $connection + ['password' => 'test password'],
                PluginSettings::databaseSettings($settings),
            );

            $settings['database_connection']['encrypted_password'] = 'invalid ciphertext';
            $this->expectException(DecryptException::class);
            PluginSettings::databaseSettings($settings);
        } finally {
            Crypt::clearResolvedInstance('encrypter');
            Crypt::setFacadeApplication($previousApp);
        }
    }
}
