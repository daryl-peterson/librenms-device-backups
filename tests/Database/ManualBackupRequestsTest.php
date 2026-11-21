<?php

namespace DRP\DeviceBackups\Tests\Database;

use DRP\DeviceBackups\Backup\BackupStore;
use DRP\DeviceBackups\Backup\ManualBackupRequests;
use DRP\DeviceBackups\Console\ProcessManualBackups;
use DRP\DeviceBackups\Tests\Support\DatabaseTestCase;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class ManualBackupRequestsTest extends DatabaseTestCase {
    public function testPicksUpQueuedRequestsInOrderAndClaimsEachRequestOnce(): void {
        $requests = new ManualBackupRequests();
        $first = $requests->enqueue(12);
        $second = $requests->enqueue(18);

        self::assertSame($first, $requests->next()->id);
        self::assertFalse($requests->claim($first, 18));
        self::assertTrue($requests->claim($first, 12));
        self::assertFalse($requests->claim($first, 12));
        self::assertSame($second, $requests->next()->id);
        self::assertSame('running', $requests->pending(12)->status);
        self::assertSame('queued', $requests->pendingFor([18])->get(18)->status);
        self::assertFalse($requests->pendingFor([18])->has(12));

        (new BackupStore())->finish($first, false, 'sent');
        self::assertNull($requests->pending(12));
        self::assertSame('success', $requests->latest(12)->status);
    }

    public function testFailureCannotOverwriteARequestClaimedByAnotherWorker(): void {
        $requests = new ManualBackupRequests();
        $first = $requests->enqueue(12);
        self::assertTrue($requests->claim($first, 12));

        $requests->fail($first, 12, false, 'Preflight failed');
        self::assertSame('running', $requests->latest(12)->status);

        $requests->fail($first, 12, true, 'Connection unavailable');
        $failure = $requests->latest(12);
        self::assertSame('failed', $failure->status);
        self::assertSame('not_sent', $failure->email_status);
        self::assertSame('Connection unavailable', $failure->request_error);
    }

    public function testOnlyStaleManualRunsAreExpired(): void {
        $requests = new ManualBackupRequests();
        $store = new BackupStore();
        $interrupted = $requests->enqueue(12);
        $recent = $requests->enqueue(18);
        $scheduled = $store->start();
        self::assertTrue($requests->claim($interrupted, 12));
        self::assertTrue($requests->claim($recent, 18));
        DB::table('device_backup_runs')->whereIn('id', [$interrupted, $scheduled])
            ->update(['started_at' => now('UTC')->subHours(2)]);

        $requests->expireInterrupted();

        self::assertSame('failed', $requests->latest(12)->status);
        self::assertNull($requests->pending(12));
        self::assertSame('running', $requests->latest(18)->status);
        self::assertSame('running', $store->findRun($scheduled)->status);
    }

    public function testQueueCheckDoesNotProcessOrExpireRequests(): void {
        $requests = new ManualBackupRequests();
        $running = $requests->enqueue(12);
        $queued = $requests->enqueue(18);
        self::assertTrue($requests->claim($running, 12));
        DB::table('device_backup_runs')->where('id', $running)
            ->update(['started_at' => now('UTC')->subHours(2)]);

        [$status, $output] = $this->checkQueue($requests);

        self::assertSame(ProcessManualBackups::SUCCESS, $status);
        self::assertStringContainsString('The manual backup queue is readable.', $output);
        self::assertSame('running', $requests->latest(12)->status);
        self::assertTrue($requests->isQueued($queued, 18));
    }

    public function testQueueCheckReportsMissingManualRequestMigration(): void {
        $migration = dirname(__DIR__, 2) . '/database/migrations/2026_11_21_000001_add_manual_requests_to_device_backup_runs.php';
        (require $migration)->down();

        [$status, $output] = $this->checkQueue(new ManualBackupRequests());

        self::assertSame(ProcessManualBackups::FAILURE, $status);
        self::assertStringContainsString('Run the Device Backups migrations', $output);
    }

    private function checkQueue(ManualBackupRequests $requests): array {
        $command = new ProcessManualBackups();
        $input = new ArrayInput(['--check' => true], $command->getDefinition());
        $output = new BufferedOutput();
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, $output));

        return [$command->handle($requests), $output->fetch()];
    }
}
