<?php

namespace DRP\DeviceBackups\Tests\Database;

use Dom\HTMLDocument;
use DRP\DeviceBackups\Backup\BackupStore;
use DRP\DeviceBackups\Controllers\BackupController;
use DRP\DeviceBackups\Tests\Support\PluginTestCase;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
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
        app('router')->getRoutes()->refreshNameLookups();
    }
}
