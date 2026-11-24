<?php

namespace DRP\DeviceBackups\Tests\Database;

use DateTimeImmutable;
use Dom\HTMLDocument;
use DRP\DeviceBackups\Backup\Archives;
use DRP\DeviceBackups\Backup\BackupStore;
use DRP\DeviceBackups\Backup\ManualBackupRequests;
use DRP\DeviceBackups\Controllers\BackupController;
use DRP\DeviceBackups\Helper;
use DRP\DeviceBackups\Tests\Support\PluginTestCase;
use DRP\DeviceBackups\Tests\Support\UserFixture;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\View;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

final class BackupAttemptsTest extends PluginTestCase {
    public function testSearchMatchesEverySearchFieldAndTreatsWildcardsLiterally(): void {
        $store = new BackupStore();
        $run = $store->start();
        $store->record([
            'run_id' => $run, 'device_id' => 1, 'hostname' => 'edge-01.example.test',
            'profile' => 'edgeos', 'status' => 'failed', 'error' => 'Transfer reached 50%_done!',
        ]);
        $store->record([
            'run_id' => $run, 'device_id' => 2, 'hostname' => 'edge-02.example.test',
            'profile' => 'ios', 'status' => 'failed', 'error' => 'Transfer reached 50XXdone!',
        ]);

        foreach (['EDGE-01', '  EdgeOS  ', '50%_DONE!'] as $search) {
            $results = $this->backupStatus(['search' => $search])->getData()['results'];
            self::assertSame([1], $results->getCollection()->pluck('device_id')->all());
        }
        self::assertCount(0, $this->backupStatus(['search' => 'missing'])->getData()['results']->items());
    }

    public function testMalformedSearchAndSortUseDefaults(): void {
        $data = $this->backupStatus(['search' => ['edge'], 'sort' => ['device'], 'direction' => 'invalid'])->getData();

        self::assertSame('', $data['search']);
        self::assertSame('started', $data['sort']);
        self::assertSame('desc', $data['direction']);
        self::assertSame('started', $this->backupStatus(['sort' => 'archive'])->getData()['sort']);
    }

    #[DataProvider('sortColumns')]
    public function testSortColumnsUseTheCorrespondingDatabaseValues(string $column, array $ascending): void {
        $store = new BackupStore();
        $older = $store->start();
        $newer = $store->start();
        DB::table('device_backup_runs')->where('id', $older)->update(['started_at' => '2026-01-01 10:00:00']);
        DB::table('device_backup_runs')->where('id', $newer)->update(['started_at' => '2026-01-02 10:00:00']);
        $store->record([
            'run_id' => $newer, 'device_id' => 1, 'hostname' => 'a-edge',
            'profile' => 'timos', 'status' => 'success', 'error' => 'Z details',
        ]);
        $store->record([
            'run_id' => $older, 'device_id' => 2, 'hostname' => 'z-edge',
            'profile' => 'ios', 'status' => 'failed', 'error' => 'A details',
        ]);

        $forward = $this->backupStatus(['sort' => $column, 'direction' => 'asc'])->getData()['results'];
        $reverse = $this->backupStatus(['sort' => $column, 'direction' => 'desc'])->getData()['results'];

        self::assertSame($ascending, $forward->getCollection()->pluck('device_id')->all());
        self::assertSame(array_reverse($ascending), $reverse->getCollection()->pluck('device_id')->all());
    }

    public static function sortColumns(): array {
        return [
            'device' => ['device', [1, 2]],
            'run started' => ['started', [2, 1]],
            'profile' => ['profile', [2, 1]],
            'status' => ['status', [2, 1]],
            'details' => ['details', [2, 1]],
        ];
    }

    public function testSearchSortAndRunFiltersApplyBeforePagination(): void {
        $store = new BackupStore();
        $otherRun = $store->start();
        $run = $store->start();
        for ($device = 1; $device <= 27; $device++) {
            $store->record([
                'run_id' => $run, 'device_id' => $device, 'hostname' => sprintf('edge-%02d', $device),
                'profile' => 'ios', 'status' => 'failed',
            ]);
        }
        $store->record(['run_id' => $otherRun, 'device_id' => 28, 'hostname' => 'edge-00', 'profile' => 'ios', 'status' => 'failed']);
        $store->record(['run_id' => $run, 'device_id' => 29, 'hostname' => 'edge-99', 'profile' => 'ios', 'status' => 'success']);

        $query = ['run' => $run, 'status' => 'failed', 'search' => 'IOS', 'sort' => 'device', 'direction' => 'asc'];
        $page = $this->backupStatus($query)->getData()['results'];
        self::assertSame(range(1, 25), $page->getCollection()->pluck('device_id')->all());
        $nextUrl = parse_url($page->nextPageUrl());
        parse_str($nextUrl['query'], $nextQuery);
        self::assertSame(array_map('strval', $query + ['page' => 2]), $nextQuery);
        self::assertSame('backup-attempts', $nextUrl['fragment']);
        self::assertSame([26, 27], $this->backupStatus($nextQuery)->getData()['results']->getCollection()->pluck('device_id')->all());
        $descending = $this->backupStatus(array_replace($query, ['direction' => 'desc']))->getData()['results'];
        self::assertSame(range(27, 3), $descending->getCollection()->pluck('device_id')->all());
    }

    public function testAttemptsRenderSortableHeadingsAndLocalTime(): void {
        $this->registerViewFixtures();
        $this->session->put('preferences.timezone', 'America/Chicago');
        $store = new BackupStore();
        $run = $store->start();
        DB::table('device_backup_runs')->where('id', $run)->update(['started_at' => '2026-11-24 00:00:01']);
        $store->record(['run_id' => $run, 'device_id' => 1, 'hostname' => 'edge-01', 'profile' => 'ios', 'status' => 'failed']);
        $query = ['run' => $run, 'status' => 'failed', 'search' => 'IOS', 'sort' => 'device', 'direction' => 'asc', 'runs_page' => 2];

        $view = $this->backupStatus($query);
        $document = HTMLDocument::createFromString($view->render(), LIBXML_NOERROR);
        $attempts = $document->querySelector('#backup-attempts');
        self::assertSame('IOS', $attempts->querySelector('input[type=search]')->getAttribute('value'));
        self::assertSame(
            ['Device', 'Run started', 'Profile', 'Status', 'Details', 'Archive'],
            array_map(static fn ($heading) => trim($heading->textContent), iterator_to_array($attempts->querySelectorAll('th'))),
        );
        self::assertCount(5, $attempts->querySelectorAll('th a'));
        self::assertCount(6, $attempts->querySelectorAll('tbody td'));
        self::assertStringContainsString('2026-11-23 18:00:01', $attempts->querySelector('tbody')->textContent);

        $heading = $attempts->querySelector('th[aria-sort=ascending] a');
        self::assertSame('Device', trim($heading->textContent));
        $sortUrl = parse_url($heading->getAttribute('href'));
        parse_str($sortUrl['query'], $sortQuery);
        self::assertSame(array_map('strval', array_replace($query, ['direction' => 'desc'])), $sortQuery);
        self::assertSame('backup-attempts', $sortUrl['fragment']);

        $empty = $this->backupStatus(['search' => 'missing'])->render();
        self::assertStringContainsString('<td colspan="6">No backup attempts found.</td>', $empty);

        $history = app('view')->make('device-backups::partials.backup-results', [
            'showDevice' => false,
            'results' => $store->results(device: 1),
        ])->render();
        $historyDocument = HTMLDocument::createFromString($history, LIBXML_NOERROR);
        self::assertCount(0, $historyDocument->querySelectorAll('th a'));
        self::assertSame('Run', trim($historyDocument->querySelector('th')->textContent));
    }

    public function testDeviceHistorySortsRunNumbersAndShowsViewerTime(): void {
        $this->registerViewFixtures();
        $this->session->put('preferences.timezone', 'America/Chicago');
        $device = $this->device(['hostname' => 'edge-01']);
        $store = new BackupStore();
        $first = $store->start();
        $second = $store->start();
        DB::table('device_backup_runs')->where('id', $first)->update(['started_at' => '2026-11-24 00:00:01']);
        $result = ['device_id' => $device->getKey(), 'hostname' => $device->hostname, 'status' => 'failed'];
        $store->record($result + ['run_id' => $first]);
        $store->record($result + ['run_id' => $second]);
        $this->request('/?sort=run&direction=asc');
        $view = (new BackupController())->history($device->getKey(), new ManualBackupRequests());

        self::assertSame([$first, $second], $view->getData()['results']->getCollection()->pluck('run_id')->all());
        $document = HTMLDocument::createFromString($view->with('errors', new ViewErrorBag())->render(), LIBXML_NOERROR);
        $table = $document->querySelector('#backup-attempts table');
        self::assertCount(6, $table->querySelectorAll('th a'));
        self::assertStringContainsString('2026-11-23 18:00:01', $table->querySelector('tbody tr')->textContent);
        $runHeading = $table->querySelector('th[aria-sort=ascending] a');
        self::assertSame('Run', trim($runHeading->textContent));
        self::assertSame(route('device-backups.backups.history', [
            'device' => $device->getKey(), 'sort' => 'run', 'direction' => 'desc',
        ]) . '#backup-attempts', $runHeading->getAttribute('href'));
    }

    public function testLocalTimePreservesTheInstantAndFallsBackToAppTimezone(): void {
        config(['app.timezone' => 'America/Chicago']);
        $timestamp = new DateTimeImmutable('2026-07-24T01:00:00+02:00');
        self::assertSame('2026-07-23 18:00:00', Helper::localTime($timestamp));
        self::assertSame('—', Helper::localTime(null));

        $this->session->put('preferences.timezone', 'America/New_York');
        self::assertSame('2026-07-23 19:00:00 EDT', Helper::localTime($timestamp, 'Y-m-d H:i:s T'));
    }

    public function testComparisonShowsEscapedChangesFromTheTwoLatestRetainedBackups(): void {
        $this->registerViewFixtures();
        $device = $this->device();
        $store = new BackupStore();
        $this->recordConfig($store, $device->getKey(), "hostname expired\n");
        $previous = $this->recordConfig($store, $device->getKey(), "hostname old\n");
        $store->record([
            'run_id' => $store->start(), 'device_id' => $device->getKey(),
            'hostname' => $device->hostname, 'profile' => 'ios', 'status' => 'failed',
        ]);
        $latest = $this->recordConfig($store, $device->getKey(), "hostname <script>\n", 2);
        $this->recordConfig($store, $device->getKey() + 1, "hostname other-device\n");
        $controller = new BackupController();

        $history = $controller->history($device->getKey(), new ManualBackupRequests());
        $historyHtml = $history->with('errors', new ViewErrorBag())->render();
        self::assertStringContainsString(route('device-backups.backups.compare', ['device' => $device->getKey()]), $historyHtml);
        self::assertStringContainsString('Compare Latest with Previous', $historyHtml);

        $response = $controller->compare($device->getKey());
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $html = $response->getContent();
        self::assertStringContainsString("Run #$previous", $html);
        self::assertStringContainsString("Run #$latest", $html);
        self::assertStringContainsString('-hostname old', $html);
        self::assertStringContainsString('+hostname &lt;script&gt;', $html);
        self::assertStringNotContainsString('+hostname <script>', $html);
        self::assertStringNotContainsString('hostname expired', $html);
        self::assertStringNotContainsString('hostname other-device', $html);
    }

    public function testComparisonHandlesInsufficientBackupsAndIdenticalConfigurations(): void {
        $this->registerViewFixtures();
        $device = $this->device();
        $store = new BackupStore();
        $controller = new BackupController();
        $this->recordConfig($store, $device->getKey(), "hostname same\r\n");
        $this->assertHttpError(404, fn () => $controller->compare($device->getKey()));

        $this->recordConfig($store, $device->getKey(), "hostname same\n");
        $response = $controller->compare($device->getKey());
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('No configuration changes.', $response->getContent());
    }

    public function testComparisonRequiresDeviceVisibility(): void {
        $device = $this->device();
        $this->user = new UserFixture(['id' => 2, 'role' => 'admin', 'hiddenDevice' => $device->getKey()]);

        $this->expectException(AuthorizationException::class);
        (new BackupController())->compare($device->getKey());
    }

    public function testComparisonDoesNotShowConfigurationWhenDecryptionFails(): void {
        $this->registerViewFixtures();
        $device = $this->device();
        $store = new BackupStore();
        $this->recordConfig($store, $device->getKey(), "hostname previous\n");
        $this->recordConfig($store, $device->getKey(), "hostname latest\n");
        $controller = new BackupController();
        config(['device-backups.archive_password' => 'wrong password']);
        $response = $controller->compare($device->getKey());
        self::assertSame(422, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertStringContainsString('Could not decrypt the configuration', $response->getContent());
        self::assertStringNotContainsString('hostname latest', $response->getContent());
        self::assertStringNotContainsString('hostname previous', $response->getContent());
    }

    private function recordConfig(BackupStore $store, int $device, string $configuration, int $archiveLimit = 0): int {
        $run = $store->start();
        $source = $this->path("config-$run.txt");
        $archive = $this->path("archive-$run.zip");
        file_put_contents($source, $configuration);
        $checksum = Archives::zip($archive, $source, 'running-config.txt', config('device-backups.archive_password'));
        $store->record([
            'run_id' => $run, 'device_id' => $device, 'hostname' => 'edge.example.test',
            'profile' => 'ios', 'status' => 'success', 'sha256' => $checksum, 'archive_bytes' => filesize($archive),
        ], $archive, $archiveLimit);

        return $run;
    }

    private function backupStatus(array $query): View {
        return (new BackupController())->status($this->request('/?' . http_build_query($query)));
    }

    private function registerViewFixtures(): void {
        $path = $this->path('storage/views');
        mkdir($path . '/layouts', 0700, true);
        file_put_contents($path . '/layouts/librenmsv1.blade.php', "@yield('content')");
        app('view')->getFinder()->prependLocation($path);
        $paginationPath = dirname((new ReflectionClass(Paginator::class))->getFileName()) . '/resources/views';
        app('view')->addNamespace('pagination', $paginationPath);

        app()->instance('session', $this->session);
        app('router')->get('/plugin/{plugin}', static fn () => '')->name('plugin.page');
        app('router')->get('/device/{device}', static fn () => '')->name('device.overview');
        app('router')->getRoutes()->refreshNameLookups();
    }
}
