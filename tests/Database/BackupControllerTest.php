<?php

namespace DRP\DeviceBackups\Tests\Database;

use DRP\DeviceBackups\Backup\BackupStore;
use DRP\DeviceBackups\Backup\ManualBackupRequests;
use DRP\DeviceBackups\Backup\Target;
use DRP\DeviceBackups\Controllers\BackupController;
use DRP\DeviceBackups\Tests\Support\PluginTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class BackupControllerTest extends PluginTestCase {
    public function testStatusFiltersTheResultsAndShowsRunCounts(): void {
        $store = new BackupStore();
        $run = $store->start();
        $store->record(['run_id' => $run, 'device_id' => 4, 'hostname' => 'one.example.test', 'status' => 'failed']);
        $store->record(['run_id' => $run, 'device_id' => 5, 'hostname' => 'two.example.test', 'status' => 'success']);
        $store->finish($run, true, 'not_sent');

        $view = (new BackupController())->status($this->request("/?run=$run&status=failed"));

        self::assertSame('device-backups::backup-status', $view->getName());
        $data = $view->getData();
        self::assertSame($run, $data['selectedRun']->id);
        self::assertSame(1, $data['runs']->items()[0]->failed);
        self::assertSame(1, $data['runs']->items()[0]->succeeded);
        self::assertSame(['one.example.test'], $data['results']->getCollection()->pluck('hostname')->all());
    }

    public function testDevicesAndHistoryIncludePendingRequestsForTheMatchingDevice(): void {
        $device = $this->device(['hostname' => 'test-edge.example.test']);
        $this->device(['hostname' => 'other.example.test']);
        self::assertTrue(Target::save($device, $this->enrollment()));
        $requests = new ManualBackupRequests();
        $id = $requests->enqueue($device->getKey());
        $controller = new BackupController();

        $view = $controller->devices($this->request('/?search=%20test-edge%20'), $requests);
        self::assertSame('device-backups::backup-devices', $view->getName());
        $data = $view->getData();
        self::assertSame([$device->getKey()], $data['devices']->getCollection()->modelKeys());
        self::assertTrue($data['devices']->items()[0]->backup_enrolled);
        self::assertSame($id, $data['pendingRequests'][$device->getKey()]->id);

        $view = $controller->history($device->getKey(), $requests);
        self::assertSame('device-backups::backup-device', $view->getName());
        $data = $view->getData();
        self::assertSame('device-backups', $data['plugin']->name);
        self::assertSame($device->hostname, $data['hostname']);
        self::assertSame($id, $data['manualRequest']->id);
        self::assertTrue($data['canStartBackup']);
    }

    public function testStartRequiresEnrollmentAndAvoidsDuplicateQueuedRequests(): void {
        $device = $this->device();
        $requests = new ManualBackupRequests();
        $controller = new BackupController();
        $request = $this->request('/?from=devices&search=edge&page=2', 'POST');

        config(['device-backups.archive_password' => null]);
        $response = $controller->start($request, $device, $requests);
        self::assertStringContainsString('search=edge&page=2', $response->getTargetUrl());
        self::assertStringContainsString('ARCHIVE_PASSWORD', $this->session->get('error'));

        config(['device-backups.archive_password' => 'test archive password']);
        $controller->start($request, $device, $requests);
        self::assertStringContainsString('save its SSH settings', $this->session->get('error'));
        self::assertNull($requests->pending($device->getKey()));

        self::assertTrue(Target::save($device, $this->enrollment()));
        $controller->start($request, $device, $requests);
        self::assertStringContainsString('queued', $this->session->get('success'));
        $queued = $requests->pending($device->getKey());
        self::assertNotNull($queued);

        $controller->start($request, $device, $requests);
        self::assertStringContainsString('already queued', $this->session->get('info'));
        self::assertSame($queued->id, $requests->pending($device->getKey())->id);
        self::assertSame(1, DB::table('device_backup_runs')->count());
    }

    public function testDownloadsVerifiedArchivesAndRejectsCorruptOrUnknownResults(): void {
        $device = $this->device(['sysName' => 'edge-1.example.test']);
        [$id, $archive] = $this->storedArchive($device);
        $controller = new BackupController();

        foreach ([$controller->latest($device->getKey()), $controller->download($device->getKey(), $id)] as $response) {
            self::assertSame('application/zip', $response->headers->get('Content-Type'));
            self::assertStringContainsString('edge-1.', $response->headers->get('Content-Disposition'));
            ob_start();
            try {
                $response->sendContent();
                self::assertSame(file_get_contents($archive), ob_get_contents());
            } finally {
                ob_end_clean();
            }
        }

        $this->assertHttpError(404, fn () => $controller->download($device->getKey(), $id + 1));
        DB::table('device_backup_chunks')->where('result_id', $id)->update(['payload' => 'damaged']);
        $this->assertHttpError(503, fn () => $controller->download($device->getKey(), $id));
    }

    public function testStatusAndDeviceListRedirectWhenBackupQueriesFail(): void {
        $controller = new BackupController();
        Schema::drop('device_backup_chunks');
        Schema::drop('device_backup_results');

        $response = $controller->status($this->request());
        self::assertStringEndsWith('/plugin/device-backups/settings', $response->getTargetUrl());
        self::assertStringContainsString('Could not load', $this->session->get('error'));

        $this->device();
        $response = $controller->devices($this->request(), new ManualBackupRequests());
        self::assertStringEndsWith('/plugin/device-backups/settings', $response->getTargetUrl());
    }
}
