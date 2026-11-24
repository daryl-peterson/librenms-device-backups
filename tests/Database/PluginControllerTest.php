<?php

namespace DRP\DeviceBackups\Tests\Database;

use Closure;
use DRP\DeviceBackups\Controllers\PluginController;
use DRP\DeviceBackups\PluginDb;
use DRP\DeviceBackups\PluginSettings;
use DRP\DeviceBackups\Tests\Support\MailFixture;
use DRP\DeviceBackups\Tests\Support\PluginTestCase;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

final class PluginControllerTest extends PluginTestCase {
    private const CONNECTION = [
        'host' => 'backups.example.test', 'port' => 3307, 'database' => 'backups',
        'username' => 'backups_user', 'password' => 'test database password',
    ];
    private const JSON = ['HTTP_ACCEPT' => 'application/json'];

    public function testSettingsRedactTheDecryptedDatabasePassword(): void {
        $this->saveDatabaseSettings();
        $controller = new PluginController();
        self::assertSame('device-backups::page', $controller->index()->getName());
        $view = $controller->settings();
        self::assertSame('device-backups::settings', $view->getName());
        self::assertTrue($view->getData()['databaseSaved']);
        self::assertTrue($view->getData()['databaseReady']);
        self::assertArrayNotHasKey('password', $view->getData()['database']);
    }

    public function testConnectionValidationAndSuccessReturnJson(): void {
        $this->saveDatabaseSettings();
        $verified = [];
        $controller = $this->controller(static function (array $connection) use (&$verified): void {
            $verified[] = $connection;
        });

        $response = $controller->testConnection($this->request('/', 'POST', ['port' => 0], self::JSON));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $verified);

        $data = self::CONNECTION;
        unset($data['password']);
        $response = $controller->testConnection($this->request('/', 'POST', $data, self::JSON));
        self::assertSame(200, $response->getStatusCode());
        self::assertEquals([self::CONNECTION], $verified);
    }

    #[DataProvider('connectionErrors')]
    public function testConnectionErrorsReturnReadableMessages(int $code, string $message): void {
        $controller = $this->controller(static function () use ($code): never {
            throw new PDOException('Internal database details', $code);
        });
        $response = $controller->testConnection($this->request('/', 'POST', self::CONNECTION, self::JSON));
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString($message, $response->getContent());
        self::assertStringNotContainsString('Internal database details', $response->getContent());
    }

    public static function connectionErrors(): array {
        return [
            'rejected credentials' => [1045, 'rejected the login'],
            'database denied' => [1044, 'does not have access'],
            'unknown database' => [1049, 'does not exist'],
            'connection refused' => [2002, 'Could not reach'],
            'unknown driver error' => [0, 'Could not connect'],
        ];
    }

    public function testSavingSettingsEncryptsPasswordAndResetsMigrationStatusOnlyWhenChanged(): void {
        $this->saveDatabaseSettings();
        $controller = $this->controller();
        $controller->saveSettings($this->request('/', 'POST', self::CONNECTION + [
            'backup_report_email' => 'reports@example.test',
            'backups_per_device' => '7',
        ]));
        $settings = (new PluginSettings())->all();
        self::assertSame(PluginDb::migrationVersion(), $settings['database_migrations']);
        self::assertSame('reports@example.test', $settings['backup_report_email']);
        self::assertSame(7, $settings['backups_per_device']);

        $changed = array_replace(self::CONNECTION, ['password' => 'changed password', 'port' => 3308]);
        $controller->saveSettings($this->request('/', 'POST', $changed));
        $settings = (new PluginSettings())->all();
        self::assertSame(false, $settings['database_migrations']);
        self::assertSame(7, $settings['backups_per_device']);
        self::assertArrayNotHasKey('password', $settings['database_connection']);
        self::assertSame('changed password', Crypt::decryptString($settings['database_connection']['encrypted_password']));
        self::assertStringContainsString('settings saved', $this->session->get('success'));
    }

    public function testRetentionRejectsNegativeLimitsAndAcceptsUnlimited(): void {
        $this->saveDatabaseSettings();
        $controller = $this->controller();
        $controller->saveSettings($this->request('/', 'POST', self::CONNECTION + ['backups_per_device' => '-1']));
        self::assertTrue($this->session->has('error'));
        self::assertSame('-1', $this->session->getOldInput('backups_per_device'));
        self::assertArrayNotHasKey('backups_per_device', (new PluginSettings())->all());

        $controller->saveSettings($this->request('/', 'POST', self::CONNECTION + ['backups_per_device' => '0']));
        self::assertSame(0, (new PluginSettings())->all()['backups_per_device']);
    }

    public function testFailedSaveDoesNotFlashThePassword(): void {
        $this->saveDatabaseSettings();
        DB::connection('inventory')->statement(<<<'SQL'
            CREATE TRIGGER reject_plugin_update BEFORE UPDATE ON plugins
            BEGIN SELECT RAISE(ABORT, 'test settings write failure'); END
            SQL);
        $controller = $this->controller();
        $response = $controller->saveSettings($this->request('/', 'POST', self::CONNECTION + [
            'backup_report_email' => 'reports@example.test',
        ]));

        self::assertStringEndsWith('/plugin/device-backups/settings', $response->getTargetUrl());
        self::assertStringContainsString('Could not save', $this->session->get('error'));
        self::assertArrayNotHasKey('password', $this->session->get('_old_input'));
        self::assertSame('backups', $this->session->getOldInput('database'));
    }

    public function testReportEmailValidatesAndUsesTheProvidedAddress(): void {
        $controller = new PluginController();
        $request = fn (string $email) => $this->request('/', 'POST', ['backup_report_email' => $email], self::JSON);
        self::assertSame(422, $controller->testReportEmail($request('invalid'))->getStatusCode());
        self::assertSame([], MailFixture::$messages);

        $recipient = 'testing@example.test';
        self::assertSame(200, $controller->testReportEmail($request($recipient))->getStatusCode());
        self::assertSame($recipient, MailFixture::$messages[0]['recipient']);

        MailFixture::$delivered = false;
        self::assertSame(422, $controller->testReportEmail($request($recipient))->getStatusCode());
    }

    public function testMigrationRequiresDatabaseSettingsAndRespectsAnExistingLock(): void {
        $this->plugin([]);
        $controller = $this->controller();
        $request = $this->request('/', 'POST', ['database_action' => 'migrate']);
        $controller->saveSettings($request);
        self::assertStringContainsString('Test and save', $this->session->get('error'));

        $this->saveDatabaseSettings(encryptedPassword: 'invalid ciphertext');
        $controller->saveSettings($request);
        self::assertStringContainsString('saved database password could not be read', $this->session->get('error'));

        $this->saveDatabaseSettings();
        $lock = Cache::lock('device-backups:migrations', 60);
        self::assertTrue($lock->get());
        try {
            $controller->saveSettings($request);
            self::assertStringContainsString('Migrations did not complete', $this->session->get('error'));
        } finally {
            $lock->release();
        }
    }

    public function testMigrationConfirmationUpdatesTheSavedSettings(): void {
        $this->saveDatabaseSettings(migrations: false);
        $migrator = $this->createMock(Migrator::class);
        $repository = $this->createStub(MigrationRepositoryInterface::class);
        $repository->method('getRan')->willReturn(array_map(
            static fn (string $file) => basename($file, '.php'),
            glob(dirname(__DIR__, 2) . '/database/migrations/*.php'),
        ));
        $migrator->method('getRepository')->willReturn($repository);
        $migrator->method('repositoryExists')->willReturn(true);
        $migrator->method('usingConnection')->willReturnCallback(
            static fn (string $connection, callable $migrate) => $migrate(),
        );
        $migrator->expects(self::once())->method('run');
        app()->instance('migrator', $migrator);

        $this->controller()->saveSettings($this->request('/', 'POST', ['database_action' => 'migrate']));
        self::assertTrue(PluginDb::isReady());
        self::assertStringContainsString('Migrations are up to date', $this->session->get('success'));
    }

    private function saveDatabaseSettings(?string $encryptedPassword = null, bool $migrations = true): void {
        $database = self::CONNECTION;
        $database['encrypted_password'] = $encryptedPassword ?? Crypt::encryptString($database['password']);
        unset($database['password']);
        $this->plugin([
            'database_connection' => $database,
            'database_migrations' => $migrations ? PluginDb::migrationVersion() : false,
        ]);
    }

    private function controller(?Closure $verify = null): PluginController {
        return new class ($verify ?? static function (): void {}) extends PluginController {
            public function __construct(private readonly Closure $verify) {
                parent::__construct();
            }

            protected function verifyDatabaseConnection(array $database): void {
                ($this->verify)($database);
            }
        };
    }
}
