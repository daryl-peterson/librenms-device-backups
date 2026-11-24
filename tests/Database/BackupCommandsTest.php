<?php

namespace DRP\DeviceBackups\Tests\Database;

use DRP\DeviceBackups\Backup\ManualBackupRequests;
use DRP\DeviceBackups\Backup\Target;
use DRP\DeviceBackups\Console\ConfigureBackup;
use DRP\DeviceBackups\Console\ExportBackup;
use DRP\DeviceBackups\Console\ProcessManualBackups;
use DRP\DeviceBackups\Console\RunBackups;
use DRP\DeviceBackups\PluginDb;
use DRP\DeviceBackups\Tests\Support\MailFixture;
use DRP\DeviceBackups\Tests\Support\PluginTestCase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class BackupCommandsTest extends PluginTestCase {
    public function testCommandsRequireAReadyDatabase(): void {
        $this->plugin([]);
        $arguments = [
            ConfigureBackup::class => ['device' => '1'],
            ExportBackup::class => ['result' => '1', 'path' => $this->path('export.zip')],
            ProcessManualBackups::class => [],
            RunBackups::class => [],
        ];
        foreach ($arguments as $command => $input) {
            $this->assertCommand($command, $input, Command::FAILURE, PluginDb::SETUP_REQUIRED);
        }
    }

    public function testConfigureValidatesInputAndPersistsThenRemovesEnrollment(): void {
        $device = $this->device(['os' => 'ios', 'hostname' => 'edge.example.test']);
        $id = (string) $device->getKey();

        $this->assertCommand(ConfigureBackup::class, ['device' => '0'], Command::FAILURE, 'valid LibreNMS device ID');
        $this->assertCommand(ConfigureBackup::class, ['device' => $id, '--profile' => 'unknown'], Command::FAILURE, 'supported --profile');
        $this->assertCommand(ConfigureBackup::class, ['device' => $id], Command::FAILURE, 'interactively');

        $fingerprint = $this->enrollment()['fingerprint'];
        $this->assertCommand(ConfigureBackup::class, ['device' => $id], Command::FAILURE, 'Check the SSH credentials', [
            'backup', '0', $fingerprint, 'test-password', 'edge', '',
        ]);
        self::assertNull(Target::revision($device));

        $this->assertCommand(ConfigureBackup::class, ['device' => $id], Command::SUCCESS, 'enrolled with the ios profile', [
            'backup', '2222', $fingerprint, 'test-password', 'edge', 'test-enable',
        ]);
        self::assertSame('edge', Target::get($device)['prompt_name']);
        self::assertSame('test-password', Target::get($device)['password']);
        self::assertSame(2222, Target::get($device)['port']);

        $this->assertCommand(ConfigureBackup::class, ['device' => $id, '--disable' => true], Command::SUCCESS, 'removed');
        self::assertNull(Target::revision($device));
    }

    public function testExportValidatesTheIdAndProtectsExistingFiles(): void {
        [$id, $source] = $this->storedArchive($this->device());
        $path = $this->path('export.zip');

        $this->assertCommand(ExportBackup::class, ['result' => '0', 'path' => $path], Command::FAILURE, 'valid backup result ID');
        $this->assertCommand(ExportBackup::class, ['result' => (string) ($id + 1), 'path' => $path], Command::FAILURE, 'No successful backup');
        self::assertFileDoesNotExist($path);

        $input = ['result' => (string) $id, 'path' => $path];
        $this->assertCommand(ExportBackup::class, $input, Command::SUCCESS, 'checksum verified');
        self::assertSame(file_get_contents($source), file_get_contents($path));
        $this->assertCommand(ExportBackup::class, $input, Command::FAILURE, 'Choose a new filename');

        Schema::drop('device_backup_chunks');
        $this->assertCommand(ExportBackup::class, [
            'result' => (string) $id, 'path' => $this->path('other.zip'),
        ], Command::FAILURE, 'Could not export');
    }

    public function testQueueCheckDistinguishesReadableMissingAndUnqueryableSchemas(): void {
        $input = ['--check' => true];
        $this->assertCommand(ProcessManualBackups::class, $input, Command::SUCCESS, 'queue is readable');

        Schema::drop('device_backup_chunks');
        Schema::drop('device_backup_results');
        Schema::drop('device_backup_runs');
        $this->assertCommand(ProcessManualBackups::class, $input, Command::FAILURE, 'queue schema is missing');

        DB::connection()->setPdo(static function (): never {
            throw new \PDOException('Simulated database access failure');
        });
        $this->assertCommand(ProcessManualBackups::class, $input, Command::FAILURE, 'Could not access the manual backup queue');
    }

    public function testQueueWorkerDispatchesRequestsAndRecordsTheOutcome(): void {
        $device = $this->device();
        self::assertTrue(Target::save($device, $this->enrollment(['port' => 0])));
        $requests = new ManualBackupRequests();
        $id = $requests->enqueue($device->getKey());

        $this->assertCommand(ProcessManualBackups::class, [], Command::FAILURE, 'Failed: 1');
        self::assertSame('failed', $requests->latest($device->getKey())->status);
        self::assertSame('Invalid device backup enrollment.', DB::table('device_backup_results')->value('error'));

        $this->assertCommand(ProcessManualBackups::class, [], Command::SUCCESS, '');
        $this->assertCommand(RunBackups::class, [
            'device' => (string) $device->getKey(), '--request' => (string) $id,
        ], Command::SUCCESS, '');
        self::assertSame(1, DB::table('device_backup_results')->count());
    }

    public function testRunValidatesOptionsAndChecksEnrollmentWithoutDialingDevices(): void {
        $this->assertCommand(RunBackups::class, ['device' => '-1'], Command::FAILURE, 'valid device ID');
        $this->assertCommand(RunBackups::class, ['device' => '1', '--scheduled' => true], Command::FAILURE, 'without a device ID');
        $this->assertCommand(RunBackups::class, ['--request' => '1', '--check' => true], Command::FAILURE, 'manual request ID');
        $this->assertCommand(RunBackups::class, ['--check' => true], Command::FAILURE, 'No enabled, enrolled devices');

        $device = $this->device();
        self::assertTrue(Target::save($device, $this->enrollment(['port' => 0])));
        $this->assertCommand(RunBackups::class, ['--check' => true], Command::FAILURE, 'could not be loaded');

        self::assertTrue(Target::save($device, $this->enrollment()));
        config(['device-backups.archive_password' => '']);
        $this->assertCommand(RunBackups::class, ['--check' => true], Command::FAILURE, 'ARCHIVE_PASSWORD');
        config(['device-backups.archive_password' => 'test archive password']);

        $this->assertCommand(RunBackups::class, ['--check' => true, '--scheduled' => true], Command::SUCCESS, '1 device(s) enrolled');
        self::assertSame([], MailFixture::$messages);
        self::assertSame(0, DB::table('device_backup_runs')->count());

        Schema::drop('device_backup_chunks');
        $this->assertCommand(RunBackups::class, ['--check' => true], Command::FAILURE, 'tables are missing');
    }

    public function testScheduledRunReportsFailuresAndRecordsMailStatus(): void {
        $settings = ['database_connection' => ['database' => 'backups'], 'database_migrations' => PluginDb::migrationVersion()];
        $this->plugin($settings + ['backup_report_email' => 'reports@example.test']);
        $device = $this->device(['hostname' => 'edge.example.test']);
        self::assertTrue(Target::save($device, $this->enrollment(['port' => 0])));

        $this->assertCommand(RunBackups::class, ['--scheduled' => true], Command::FAILURE, 'Email: sent');
        $report = MailFixture::$messages[0];
        self::assertSame('reports@example.test', $report['recipient']);
        self::assertStringStartsWith('[ACTION REQUIRED]', $report['subject']);
        self::assertStringContainsString('FAILED edge.example.test', $report['body']);
        self::assertSame('sent', DB::table('device_backup_runs')->value('email_status'));

        MailFixture::$delivered = false;
        $this->assertCommand(RunBackups::class, ['--scheduled' => true], Command::FAILURE, 'Email: failed');
        self::assertSame('failed', DB::table('device_backup_runs')->latest('id')->value('email_status'));
    }

    public function testRunKeepsQueuedRequestsWhenAnotherProcessHoldsTheLock(): void {
        $device = $this->device();
        self::assertTrue(Target::save($device, $this->enrollment()));
        $requests = new ManualBackupRequests();
        $id = $requests->enqueue($device->getKey());
        $storage = storage_path('app/private/device-backups');
        mkdir($storage, 0700, true);
        $handle = fopen($storage . '/run.lock', 'c');
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        try {
            $this->assertCommand(RunBackups::class, [], Command::FAILURE, 'Another Device Backups run is active');
            $this->assertCommand(RunBackups::class, [
                'device' => (string) $device->getKey(), '--request' => (string) $id,
            ], Command::SUCCESS, '');
            self::assertTrue($requests->isQueued($id, $device->getKey()));
        } finally {
            fclose($handle);
        }
    }

    private function assertCommand(string $commandClass, array $arguments, int $status, string $output, array $answers = []): void {
        $console = new Application();
        $command = new $commandClass();
        $command->setLaravel(app());
        $console->addCommand($command);
        $tester = new CommandTester($command);
        $tester->setInputs($answers);
        $exitCode = $tester->execute($arguments, ['interactive' => $answers !== []]);
        $display = $tester->getDisplay();
        self::assertSame($status, $exitCode, $display);
        if ($output === '') {
            self::assertSame('', $display);
        } else {
            self::assertStringContainsString($output, $display);
        }
    }
}
