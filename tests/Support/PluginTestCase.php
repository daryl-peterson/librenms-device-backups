<?php

namespace DRP\DeviceBackups\Tests\Support;

use DRP\DeviceBackups\Backup\Archives;
use DRP\DeviceBackups\Backup\BackupStore;
use DRP\DeviceBackups\PluginDb;
use Illuminate\Auth\Access\Gate;
use Illuminate\Auth\RequestGuard;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Providers\FoundationServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Pagination\PaginationState;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Schema;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\ValidationServiceProvider;
use Illuminate\View\ViewServiceProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

use const DRP\DeviceBackups\PLUGIN_NAME;

abstract class PluginTestCase extends DatabaseTestCase {
    protected Store $session;
    protected ?UserFixture $user;
    private ?ConnectionResolverInterface $previousResolver;
    private ?array $previousRecords;

    protected function setUp(): void {
        parent::setUp();

        $app = app();
        $this->previousResolver = Model::getConnectionResolver();
        Model::setConnectionResolver($app['db']);
        $this->previousRecords = PluginFixture::$records;
        PluginFixture::$records = null;

        $app['config']->set('database.connections.inventory', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => true,
        ]);
        $schema = Schema::connection('inventory');
        $schema->create('plugins', static function (Blueprint $table): void {
            $table->increments('plugin_id');
            $table->string('plugin_name');
            $table->unsignedInteger('version');
            $table->boolean('plugin_active');
            $table->text('settings')->nullable();
        });
        $schema->create('devices', static function (Blueprint $table): void {
            $table->increments('device_id');
            $table->string('hostname');
            $table->string('sysName')->nullable();
            $table->string('sysDescr')->nullable();
            $table->string('hardware')->nullable();
            $table->string('os')->nullable();
            $table->boolean('disabled')->default(false);
        });
        $schema->create('device_attribs', static function (Blueprint $table): void {
            $table->increments('id');
            $table->foreignId('device_id')->constrained('devices', 'device_id')->cascadeOnDelete();
            $table->string('attrib_type');
            $table->text('attrib_value');
            $table->unique(['device_id', 'attrib_type']);
        });

        $app['config']->set([
            'app.timezone' => 'UTC',
            'device-backups.archive_password' => 'test archive password',
            'device-backups.reserve_bytes' => 0,
            'view.paths' => [dirname(__DIR__, 2) . '/resources/views'],
            'view.compiled' => $this->path('storage'),
            'logging.default' => 'null',
            'logging.channels.null' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class],
        ]);
        $app->useStoragePath($this->path('storage'));
        mkdir($this->path('storage'), 0700);
        $app->instance('cache', new Repository(new ArrayStore()));
        $app->instance('encrypter', new Encrypter(Encrypter::generateKey('AES-256-CBC'), 'AES-256-CBC'));

        $this->user = new UserFixture(['id' => 1, 'role' => 'admin', 'hiddenDevice' => null]);
        $app->instance('auth', new RequestGuard(fn () => $this->user, Request::create('/')));
        $gate = new Gate($app, fn () => $this->user);
        $gate->define('view', static fn (UserFixture $user, DeviceFixture $device): bool => $user->can('view', $device));
        $app->instance(GateContract::class, $gate);

        $app->register(FilesystemServiceProvider::class);
        $app->register(ViewServiceProvider::class);
        $app['view']->addNamespace(PLUGIN_NAME, dirname(__DIR__, 2) . '/resources/views');
        $app->instance('translator', new Translator(new ArrayLoader(), 'en'));
        $app->register(ValidationServiceProvider::class);
        (new FoundationServiceProvider($app))->registerRequestValidation();

        $this->session = new Store('test', new ArraySessionHandler(60));
        $this->request();
        $app['redirect']->setSession($this->session);
        PaginationState::resolveUsing($app);

        $this->plugin([
            'database_connection' => ['database' => 'backups'],
            'database_migrations' => PluginDb::migrationVersion(),
        ]);
        require dirname(__DIR__, 2) . '/routes/web.php';
        $app['router']->getRoutes()->refreshNameLookups();
    }

    protected function tearDown(): void {
        app('db')->purge('inventory');
        MailFixture::$messages = [];
        MailFixture::$delivered = true;
        PluginFixture::$records = $this->previousRecords;
        if ($this->previousResolver === null) {
            Model::unsetConnectionResolver();
        } else {
            Model::setConnectionResolver($this->previousResolver);
        }
        (new Filesystem())->deleteDirectory($this->path('storage'));

        parent::tearDown();
    }

    protected function plugin(array $settings, bool $active = true): PluginFixture {
        return PluginFixture::query()->updateOrCreate(
            ['plugin_name' => PLUGIN_NAME, 'version' => 2],
            ['plugin_active' => $active, 'settings' => $settings],
        );
    }

    protected function request(string $uri = '/', string $method = 'GET', array $data = [], array $server = []): Request {
        $request = Request::create('http://localhost' . $uri, $method, $data, server: $server);
        $request->setLaravelSession($this->session);
        app()->instance('request', $request);

        return $request;
    }

    protected function device(array $attributes = []): DeviceFixture {
        return DeviceFixture::query()->create($attributes + ['os' => 'edgeos']);
    }

    protected function enrollment(array $overrides = []): array {
        return $overrides + [
            'profile' => 'edgeos',
            'user' => 'backup',
            'port' => 22,
            'fingerprint' => 'SHA256:' . str_repeat('a', 43),
            'password' => 'test SSH password',
            'prompt_name' => '',
            'enable' => null,
        ];
    }

    protected function storedArchive(DeviceFixture $device): array {
        $source = $this->path('device-config.txt');
        $archive = $this->path('device-config.zip');
        file_put_contents($source, 'Test device configuration');
        $checksum = Archives::zip($archive, $source, 'running-config.txt', 'test archive password');
        $store = new BackupStore();
        $run = $store->start();

        $id = $store->record([
            'run_id' => $run,
            'device_id' => $device->getKey(),
            'hostname' => $device->hostname,
            'status' => 'success',
            'sha256' => $checksum,
            'archive_bytes' => filesize($archive),
        ], $archive);
        $store->finish($run, false, 'not_sent');

        return [$id, $archive];
    }

    protected function assertHttpError(int $status, callable $action): void {
        try {
            $action();
            self::fail("Expected HTTP status $status.");
        } catch (HttpException $error) {
            self::assertSame($status, $error->getStatusCode());
        }
    }
}
