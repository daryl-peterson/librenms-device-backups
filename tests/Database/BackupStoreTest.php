<?php

namespace DRP\DeviceBackups\Tests\Database;

use DRP\DeviceBackups\Backup\Archives;
use DRP\DeviceBackups\Backup\BackupFailure;
use DRP\DeviceBackups\Backup\BackupStore;
use DRP\DeviceBackups\Tests\Support\DatabaseTestCase;
use Illuminate\Support\Facades\DB;

final class BackupStoreTest extends DatabaseTestCase {
    public function testStoresAndExportsAnEncryptedArchiveAcrossMultipleChunks(): void {
        $store = new BackupStore();
        [$archive, $metadata] = $this->backupFixture($store);
        $id = $store->record($metadata, $archive);
        $export = $this->path('export.zip');

        self::assertGreaterThan(1, DB::table('device_backup_chunks')->where('result_id', $id)->count());
        self::assertSame($id, $store->findDownload(12)?->id);
        self::assertNull($store->findDownload(18, $id));
        $store->export($id, $export);
        self::assertSame(file_get_contents($archive), file_get_contents($export));
    }

    public function testBadChecksumRollsBackTheResultAndItsChunks(): void {
        $store = new BackupStore();
        [$archive, $metadata] = $this->backupFixture($store);
        $metadata['sha256'] = str_repeat('0', 64);

        $this->expectException(BackupFailure::class);
        $this->expectExceptionMessage('changed while being stored');
        try {
            $store->record($metadata, $archive);
        } finally {
            self::assertSame(0, DB::table('device_backup_results')->count());
            self::assertSame(0, DB::table('device_backup_chunks')->count());
        }
    }

    public function testRetentionKeepsTheNewestArchivesAndPreservesAttemptHistory(): void {
        $store = new BackupStore();
        [$archive, $metadata] = $this->backupFixture($store);
        $oldest = $store->record($metadata, $archive);
        $older = $store->record($metadata, $archive);
        $newer = $store->record($metadata, $archive);
        $otherDevice = $store->record(array_replace($metadata, ['device_id' => 18]), $archive);
        $failed = $store->record(array_replace($metadata, [
            'status' => 'failed', 'sha256' => null, 'archive_bytes' => null,
        ]), archiveLimit: 2);
        self::assertNotNull($store->findDownload(12, $oldest));

        $newest = $store->record($metadata, $archive, archiveLimit: 2);

        self::assertSame($newest, $store->findDownload(12)?->id);
        self::assertNotNull($store->findDownload(12, $newer));
        self::assertNotNull($store->findDownload(18, $otherDevice));
        self::assertNull($store->findDownload(12, $oldest));
        self::assertNull($store->findDownload(12, $older));
        self::assertSame(0, DB::table('device_backup_chunks')->whereIn('result_id', [$oldest, $older])->count());
        self::assertSame([$newest, $failed, $newer, $older, $oldest], $store->results(device: 12)->getCollection()->pluck('id')->all());
        self::assertSame([$newest, $newer, $failed, $older, $oldest], $store->results(device: 12, sort: 'archive')->getCollection()->pluck('id')->all());
        self::assertSame(5, $store->runs()->items()[0]->succeeded);
    }

    public function testUnverifiedArchiveDoesNotExpireAnOlderBackup(): void {
        $store = new BackupStore();
        [$archive, $metadata] = $this->backupFixture($store);
        $previous = $store->record($metadata, $archive);
        $metadata['sha256'] = str_repeat('0', 64);

        $this->expectException(BackupFailure::class);
        try {
            $store->record($metadata, $archive, archiveLimit: 1);
        } finally {
            self::assertSame($previous, $store->findDownload(12)?->id);
            self::assertSame(1, DB::table('device_backup_results')->count());
            self::assertGreaterThan(0, DB::table('device_backup_chunks')->where('result_id', $previous)->count());
        }
    }

    public function testCorruptedChunksAreNeverPublishedAsAnExport(): void {
        $store = new BackupStore();
        [$archive, $metadata] = $this->backupFixture($store);
        $id = $store->record($metadata, $archive);
        DB::table('device_backup_chunks')->where('result_id', $id)->where('sequence', 0)->update(['payload' => 'damaged']);

        $export = $this->path('export.zip');
        $this->expectException(BackupFailure::class);
        $this->expectExceptionMessage('failed checksum verification');
        try {
            $store->export($id, $export);
        } finally {
            self::assertFileDoesNotExist($export);
            self::assertSame([], glob($this->path('.*.partial')));
        }
    }

    private function backupFixture(BackupStore $store): array {
        $run = $store->start();
        $source = $this->path('configuration.txt');
        $archive = $this->path('encrypted.zip');
        file_put_contents($source, random_bytes(120000));
        $checksum = Archives::zip($archive, $source, 'running-config.txt', 'fixture-password');

        return [$archive, [
            'run_id' => $run,
            'device_id' => 12,
            'hostname' => 'router.example.test',
            'profile' => 'ios',
            'status' => 'success',
            'sha256' => $checksum,
            'archive_bytes' => filesize($archive),
        ]];
    }
}
