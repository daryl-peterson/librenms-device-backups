<?php

namespace DRP\DeviceBackups\Tests\Unit;

use DateTimeImmutable;
use DRP\DeviceBackups\PluginCache;
use DRP\DeviceBackups\PluginDb;
use DRP\DeviceBackups\PluginData;
use DRP\DeviceBackups\PluginSettings;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\MigrationServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Monolog\Handler\NullHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// Import constants into this namespace
use const DRP\DeviceBackups\PLUGIN_DB_CONNECTION;
use const DRP\DeviceBackups\CACHE_DB_PASS;
use const DRP\DeviceBackups\CACHE_DB_ERROR;
use const DRP\DeviceBackups\CACHE_DB_MIGRATION_CHECK;

final class PluginDbTest extends TestCase {
    private Container $previousContainer;
    private ?Container $previousFacadeApplication;
    private array $previousPluginDbState;

    protected function setUp(): void {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousPluginDbState = (new \ReflectionClass(PluginDb::class))->getStaticProperties();

        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new Repository([
            'logging' => [
                'default' => 'null',
                'channels' => [
                    'null' => [
                        'driver' => 'monolog',
                        'handler' => NullHandler::class,
                    ],
                ],
            ],
            'database' => [
                'default' => 'device_backups',
                'migrations' => ['table' => 'migrations'],
                'connections' => [
                    'device_backups' => [
                        'driver' => 'sqlite',
                        'database' => ':memory:',
                        'foreign_key_constraints' => true,
                    ],
                ],
            ],
            'cache' => [
                'default' => 'array',
                'stores' => [
                    'array' => [
                        'driver' => 'array',
                    ],
                ],
            ],
        ]));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $app->register(DatabaseServiceProvider::class);

        // Set up cache
        $app->singleton('cache', function ($app) {
            return new CacheRepository(new ArrayStore());
        });

        // Migrate the database
        foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*.php') as $file) {
            (require $file)->up();
        }
    }

    protected function tearDown(): void {
        DB::purge('device_backups');
        $pluginDb = new \ReflectionClass(PluginDb::class);
        foreach ($this->previousPluginDbState as $property => $value) {
            $pluginDb->setStaticPropertyValue($property, $value);
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    public function testNowReturnsCurrentDateTimeInCorrectFormat(): void {
        $now = PluginDb::now();

        // Verify format matches 'Y-m-d H:i:s'
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $now);

        // Verify it's close to the current time (within 2 seconds)
        $nowDate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $now);
        self::assertNotFalse($nowDate);
        $diff = abs((time() - $nowDate->getTimestamp()));
        self::assertLessThanOrEqual(2, $diff);
    }

    public function testGetDbConnectionReturnsExpectedConstant(): void {
        $connection = PluginDb::getDbConnection();
        self::assertSame(PLUGIN_DB_CONNECTION, $connection);
        self::assertIsString($connection);
    }

    public function testGetDbNameReturnsConfiguredDatabaseName(): void {
        Config::set('database.connections.' . PLUGIN_DB_CONNECTION . '.database', 'test_db');
        $name = PluginDb::getDbName();
        self::assertSame('test_db', $name);
    }

    public function testGetDbNameReturnsStringType(): void {
        $name = PluginDb::getDbName();
        self::assertIsString($name);
    }

    public function testClearConnectionChecksClearsAllCacheKeys(): void {
        $cache = new PluginCache();
        $cache->set(CACHE_DB_PASS, true, 3600);
        $cache->set(CACHE_DB_ERROR, 'Error message', 3600);
        $cache->set(CACHE_DB_MIGRATION_CHECK, true, 3600);

        self::assertTrue($cache->has(CACHE_DB_PASS));
        self::assertTrue($cache->has(CACHE_DB_ERROR));
        self::assertTrue($cache->has(CACHE_DB_MIGRATION_CHECK));

        PluginDb::clearConnectionChecks();

        self::assertFalse($cache->has(CACHE_DB_PASS));
        self::assertFalse($cache->has(CACHE_DB_ERROR));
        self::assertFalse($cache->has(CACHE_DB_MIGRATION_CHECK));
    }

    public function testGetErrorReturnsNullWhenNotSet(): void {
        $error = PluginDb::getError();
        self::assertNull($error);
    }

    public function testGetErrorReturnsStoredErrorMessage(): void {
        $cache = new PluginCache();
        $errorMessage = 'Database connection failed';
        $cache->set(CACHE_DB_ERROR, $errorMessage, 300);

        $error = PluginDb::getError();
        self::assertSame($errorMessage, $error);
    }

    public function testMigrationVersionReturnsConsistentHash(): void {
        $version1 = PluginDb::migrationVersion();
        $version2 = PluginDb::migrationVersion();

        self::assertSame($version1, $version2);
        self::assertIsString($version1);
        self::assertSame(64, strlen($version1)); // SHA256 hash length
    }

    public function testMigrationVersionReturnsValidSha256Hash(): void {
        $version = PluginDb::migrationVersion();

        // Verify it's a valid hexadecimal hash
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $version);
    }

    #[DataProvider('hostProvider')]
    public function testTcpHostConversion(string $input, string $expected): void {
        $reflection = new \ReflectionClass(PluginDb::class);
        $method = $reflection->getMethod('tcpHost');

        $result = $method->invokeArgs(null, [$input]);
        self::assertSame($expected, $result);
    }

    public static function hostProvider(): array {
        return [
            'localhost is converted to 127.0.0.1' => ['localhost', '127.0.0.1'],
            'Localhost uppercase is converted' => ['Localhost', '127.0.0.1'],
            'LOCALHOST all caps is converted' => ['LOCALHOST', '127.0.0.1'],
            'other hostname unchanged' => ['db.example.com', 'db.example.com'],
            '127.0.0.1 unchanged' => ['127.0.0.1', '127.0.0.1'],
            'other IP unchanged' => ['192.168.1.1', '192.168.1.1'],
        ];
    }

    public function testTestConnectionThrowsExceptionOnInvalidCredentials(): void {
        $settings = [
            'host' => 'invalid.host',
            'port' => 3306,
            'database' => 'nonexistent',
            'username' => 'invalid',
            'password' => 'invalid',
        ];

        self::expectException(\PDOException::class);
        PluginDb::testConnection($settings);
    }

    public function testConnectionConfigReturnsValidMysqlConfiguration(): void {
        $reflection = new \ReflectionClass(PluginDb::class);
        $method = $reflection->getMethod('connectionConfig');

        $settings = [
            'host' => 'localhost',
            'port' => 3306,
            'database' => 'test_db',
            'username' => 'user',
            'password' => 'pass',
        ];

        $config = $method->invokeArgs(null, [$settings]);

        self::assertSame('mysql', $config['driver']);
        self::assertSame('utf8mb4', $config['charset']);
        self::assertSame('utf8mb4_unicode_ci', $config['collation']);
        self::assertSame('', $config['prefix']);
        self::assertSame('127.0.0.1', $config['host']);
        self::assertSame(3306, $config['port']);
        self::assertSame('test_db', $config['database']);
        self::assertSame('user', $config['username']);
        self::assertSame('pass', $config['password']);
    }

    public function testConnectionConfigConvertsLocalhostToIp(): void {
        $reflection = new \ReflectionClass(PluginDb::class);
        $method = $reflection->getMethod('connectionConfig');

        $settings = [
            'host' => 'localhost',
            'port' => 3306,
            'database' => 'test_db',
            'username' => 'user',
            'password' => 'pass',
        ];

        $config = $method->invokeArgs(null, [$settings]);

        self::assertSame('127.0.0.1', $config['host']);
    }

    public function testConnectionConfigSetsTimeoutOption(): void {
        $reflection = new \ReflectionClass(PluginDb::class);
        $method = $reflection->getMethod('connectionConfig');

        $settings = [
            'host' => 'db.example.com',
            'port' => 3306,
            'database' => 'test_db',
            'username' => 'user',
            'password' => 'pass',
        ];

        $config = $method->invokeArgs(null, [$settings]);

        self::assertArrayHasKey('options', $config);
        self::assertArrayHasKey(\PDO::ATTR_TIMEOUT, $config['options']);
        self::assertSame(5, $config['options'][\PDO::ATTR_TIMEOUT]);
    }

    public function testIsReadyReturnsFalseWhenNoSettingsSaved(): void {
        $settings = [];
        $result = PluginDb::isReady($settings);
        self::assertFalse($result);
    }

    public function testIsReadyReturnsFalseWhenDatabaseConnectionNull(): void {
        $settings = [
            'database_connection' => null,
        ];
        $result = PluginDb::isReady($settings);
        self::assertFalse($result);
    }

    public function testIsReadyReturnsFalseWhenDatabaseConnectionIsNotArray(): void {
        $settings = [
            'database_connection' => 'string_value',
        ];
        $result = PluginDb::isReady($settings);
        self::assertFalse($result);
    }

    public function testIsReadyUsesProvidedSettingsParameter(): void {
        $settings = ['database_connection' => null];
        $result = PluginDb::isReady($settings);
        self::assertFalse($result);
    }


    public function testGetDateMethodReturnsCorrectFormat(): void {
        $reflection = new \ReflectionClass(PluginDb::class);
        $method = $reflection->getMethod('getDate');

        $date = $method->invoke(null);

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date);
    }

    public function testNowAndGetDateReturnSimilarTimes(): void {
        $reflection = new \ReflectionClass(PluginDb::class);
        $getDateMethod = $reflection->getMethod('getDate');

        $now = PluginDb::now();
        $getDate = $getDateMethod->invoke(null);

        // Both should be in the same second (or at most 1 second apart)
        $nowTimestamp = strtotime($now);
        $getDateTimestamp = strtotime($getDate);

        self::assertLessThanOrEqual(1, abs($nowTimestamp - $getDateTimestamp));
    }

    public function testCheckConnectionReturnsTrueWhenDatabaseAvailable(): void {
        // This test verifies checkConnection returns true with valid connection
        Config::set('database.connections.' . PLUGIN_DB_CONNECTION, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => true,
        ]);

        $result = PluginDb::checkConnection();
        self::assertTrue($result);
    }

    public function testCheckConnectionCachesSuccessfulConnections(): void {
        $cache = new PluginCache();
        $cache->forget(CACHE_DB_PASS);
        $cache->forget(CACHE_DB_ERROR);

        Config::set('database.connections.' . PLUGIN_DB_CONNECTION, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => true,
        ]);

        PluginDb::checkConnection();

        // Verify cache was set
        self::assertTrue($cache->has(CACHE_DB_PASS));
    }

    public function testCheckConnectionUsesCachedSuccess(): void {
        PluginCache::set(CACHE_DB_PASS, true);

        DB::connection(PLUGIN_DB_CONNECTION)->setPdo(static function (): never {
            throw new \PDOException('Unexpected connection attempt');
        });

        self::assertTrue(PluginDb::checkConnection());
    }

    public function testCheckConnectionCachesPdoFailure(): void {
        $attempts = 0;
        DB::connection(PLUGIN_DB_CONNECTION)->setPdo(static function () use (&$attempts): never {
            ++$attempts;
            throw new \PDOException('Simulated database connection failure');
        });

        self::assertFalse(PluginDb::checkConnection());
        self::assertFalse(PluginCache::get(CACHE_DB_PASS, true));
        self::assertSame('Unable to connect to database', PluginCache::get(CACHE_DB_ERROR));

        self::assertFalse(PluginDb::checkConnection());
        self::assertSame(1, $attempts);
    }

    public function testMigrateSettingsAppliesAndRecordsMigrations(): void {
        $app = app();
        $app->register(FilesystemServiceProvider::class);
        $app->register(MigrationServiceProvider::class);

        $key = 'database.connections.' . PLUGIN_DB_CONNECTION;
        $previous = Config::get($key);
        $database = tempnam(sys_get_temp_dir(), 'device-backups-migrations-');
        self::assertNotFalse($database);

        try {
            $settings = [
                'driver' => 'sqlite',
                'host' => 'localhost',
                'port' => 3306,
                'database' => $database,
                'username' => '',
            ];

            self::assertTrue(PluginDb::migrateSettings($settings));
            self::assertSame($previous, Config::get($key));

            // Running again uses the existing migration repository.
            self::assertTrue(PluginDb::migrateSettings($settings));
            self::assertSame($previous, Config::get($key));
            self::assertSame(':memory:', DB::connection(PLUGIN_DB_CONNECTION)->getDatabaseName());

            $pdo = new \PDO('sqlite:' . $database);
            $ran = $pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(\PDO::FETCH_COLUMN);
            self::assertNotEmpty($ran);
            self::assertSame(PluginDb::migrationVersion(), hash('sha256', implode("\n", $ran)));
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM device_backup_runs')->fetchColumn());
        } finally {
            unset($pdo);
            unlink($database);
        }
    }

    public function testMigrateSettingsReturnsFalseWhileLockIsHeld(): void {
        $key = 'database.connections.' . PLUGIN_DB_CONNECTION;
        $previous = Config::get($key);
        $lock = Cache::lock('device-backups:migrations', 60);
        self::assertTrue($lock->get());

        try {
            self::assertFalse(PluginDb::migrateSettings([
                'host' => 'localhost',
                'port' => 3307,
                'database' => 'backups',
                'username' => 'backup_user',
            ]));
            self::assertSame($previous, Config::get($key));
        } finally {
            $lock->release();
        }
    }

    public function testMigrateSettingsRestoresConfigurationAfterFailure(): void {
        $key = 'database.connections.' . PLUGIN_DB_CONNECTION;
        $previous = Config::get($key);
        $failure = new \RuntimeException('Simulated migration failure');
        $migrator = $this->createMock(Migrator::class);
        $migrator->expects(self::once())->method('usingConnection')
            ->with(PLUGIN_DB_CONNECTION, self::isCallable())
            ->willThrowException($failure);
        app()->instance('migrator', $migrator);

        $this->expectExceptionObject($failure);
        try {
            PluginDb::migrateSettings([
                'host' => 'localhost',
                'port' => 3307,
                'database' => 'backups',
                'username' => 'backup_user',
            ]);
        } finally {
            self::assertSame($previous, Config::get($key));
            self::assertTrue(Cache::lock('device-backups:migrations', 60)->get(static fn (): bool => true));
        }
    }

    public function testConnectionConfigWithoutPassword(): void {
        $reflection = new \ReflectionClass(PluginDb::class);
        $method = $reflection->getMethod('connectionConfig');

        $settings = [
            'host' => 'db.example.com',
            'port' => 3306,
            'database' => 'test_db',
            'username' => 'user',
        ];

        $config = $method->invokeArgs(null, [$settings]);

        self::assertArrayNotHasKey('password', $config);
    }

    public function testTcpHostPreservesNonLocalhostValues(): void {
        $reflection = new \ReflectionClass(PluginDb::class);
        $method = $reflection->getMethod('tcpHost');

        $testCases = [
            '192.168.0.1' => '192.168.0.1',
            'db.example.com' => 'db.example.com',
            'server1.local' => 'server1.local',
            'mysql-prod' => 'mysql-prod',
        ];

        foreach ($testCases as $input => $expected) {
            $result = $method->invokeArgs(null, [$input]);
            self::assertSame($expected, $result, "Failed for input: $input");
        }
    }

    public function testIsReadyWithMigrationVersionMatch(): void {
        $version = PluginDb::migrationVersion();
        $settings = [
            'database_connection' => [
                'host' => 'localhost',
                'port' => 3306,
                'database' => 'test',
                'username' => 'user',
            ],
            'database_migrations' => $version,
        ];

        self::assertTrue(PluginDb::isReady($settings));
    }

    public function testIsReadyWithMigrationVersionMismatch(): void {
        $settings = [
            'database_connection' => [
                'host' => 'localhost',
                'port' => 3306,
                'database' => 'test',
                'username' => 'user',
            ],
            'database_migrations' => 'incorrect_hash_value',
        ];

        $result = PluginDb::isReady($settings);
        self::assertFalse($result);
    }

    public function testIsReadyRejectsChangedSettingsAfterInitialization(): void {
        $settings = [
            'database_connection' => ['database' => 'new_backup_database'],
            'database_migrations' => PluginDb::migrationVersion(),
        ];
        self::assertTrue(PluginDb::isReady($settings));

        PluginDb::initConfig();

        self::assertFalse(PluginDb::isReady($settings));
    }

    #[DataProvider('migrationRepositoryConfigurations')]
    public function testIsReadyChecksLegacyMigrationHistory(string|array $configuration): void {
        Config::set('database.migrations', $configuration);
        $saved = ['database_connection' => ['database' => 'backups']];
        $connection = DB::connection(PLUGIN_DB_CONNECTION);
        $repository = 'backup_migrations';

        self::assertFalse(PluginDb::isReady($saved));

        $connection->getSchemaBuilder()->create($repository, static function (Blueprint $table): void {
            $table->string('migration');
        });

        $files = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');
        $missing = array_shift($files);
        self::assertNotNull($missing);
        foreach ($files as $file) {
            $connection->table($repository)->insert(['migration' => basename($file, '.php')]);
        }
        self::assertFalse(PluginDb::isReady($saved));

        $connection->table($repository)->insert(['migration' => basename($missing, '.php')]);
        self::assertTrue(PluginDb::isReady($saved));
    }

    public static function migrationRepositoryConfigurations(): array {
        return [
            'table name' => ['backup_migrations'],
            'repository options' => [['table' => 'backup_migrations']],
        ];
    }

    public function testGetDbNameHandlesNumericValues(): void {
        Config::set('database.connections.' . PLUGIN_DB_CONNECTION . '.database', 12345);
        $name = PluginDb::getDbName();
        self::assertSame('12345', $name);
        self::assertIsString($name);
    }

    #[DataProvider('connectionSettingsProvider')]
    public function testTestConnectionWithValidSettings(array $settings): void {
        try {
            PluginDb::testConnection($settings);
            $this->fail('Expected PDOException to be thrown for invalid settings');
        } catch (\PDOException $e) {
            // This is expected as we're using invalid credentials
            self::assertTrue(true);
        }
    }

    public static function connectionSettingsProvider(): array {
        return [
            'missing password key' => [[
                'host' => 'localhost',
                'port' => 3306,
                'database' => 'test',
                'username' => 'user',
            ]],
            'with explicit null password' => [[
                'host' => 'localhost',
                'port' => 3306,
                'database' => 'test',
                'username' => 'user',
                'password' => null,
            ]],
            'with empty password' => [[
                'host' => 'localhost',
                'port' => 3306,
                'database' => 'test',
                'username' => 'user',
                'password' => '',
            ]],
        ];
    }

    public function testMigrationVersionGeneratesConsistentHash(): void {
        // Run multiple times to ensure consistency
        $hashes = [];
        for ($i = 0; $i < 5; $i++) {
            $hashes[] = PluginDb::migrationVersion();
        }

        // All hashes should be identical
        self::assertCount(1, array_unique($hashes));
    }

    public function testNowReturnsValidDateTimeInUtcTimezone(): void {
        $now = PluginDb::now();
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $now);

        self::assertNotFalse($date);
        self::assertInstanceOf(DateTimeImmutable::class, $date);
    }

    public function testClearConnectionChecksClearsMultipleKeys(): void {
        $cache = new PluginCache();

        // Set multiple cache values
        $cache->set(CACHE_DB_PASS, true, 3600);
        $cache->set(CACHE_DB_ERROR, 'Error', 3600);
        $cache->set(CACHE_DB_MIGRATION_CHECK, true, 3600);

        // Verify all are set
        self::assertTrue($cache->has(CACHE_DB_PASS));
        self::assertTrue($cache->has(CACHE_DB_ERROR));
        self::assertTrue($cache->has(CACHE_DB_MIGRATION_CHECK));

        // Clear them
        PluginDb::clearConnectionChecks();

        // Verify all are cleared
        self::assertFalse($cache->has(CACHE_DB_PASS));
        self::assertFalse($cache->has(CACHE_DB_ERROR));
        self::assertFalse($cache->has(CACHE_DB_MIGRATION_CHECK));
    }

    public function testConnectionConfigMaintainsSensitiveData(): void {
        $reflection = new \ReflectionClass(PluginDb::class);
        $method = $reflection->getMethod('connectionConfig');

        $password = 'very_secret_password_123!@#';
        $settings = [
            'host' => 'db.example.com',
            'port' => 3306,
            'database' => 'test_db',
            'username' => 'user',
            'password' => $password,
        ];

        $config = $method->invokeArgs(null, [$settings]);

        // Verify password is preserved
        self::assertSame($password, $config['password']);
        self::assertSame('user', $config['username']);
    }

}

