<?php

namespace DRP\DeviceBackups\Tests\Database;

use DRP\DeviceBackups\Backup\BackupSchedule;
use DRP\DeviceBackups\Controllers\BackupScheduleController;
use DRP\DeviceBackups\Middleware\RequireBackupDatabase;
use DRP\DeviceBackups\PluginSettings;
use DRP\DeviceBackups\Tests\Support\PluginTestCase;
use DRP\DeviceBackups\Tests\Support\UserFixture;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class BackupScheduleControllerTest extends PluginTestCase {
    public function testEditingAndSavingAScheduleSortsDaysAndSupportsPausing(): void {
        $controller = new BackupScheduleController();
        $data = [
            'enabled' => '1', 'days' => ['5', '1'], 'time' => '22:15', 'timezone' => 'UTC',
        ];
        $response = $controller->update($this->request('/', 'POST', $data));
        self::assertStringEndsWith('/backups/schedule', $response->getTargetUrl());
        self::assertSame('Backup schedule saved.', $this->session->get('success'));

        $view = $controller->edit();
        self::assertSame('device-backups::backup-schedule', $view->getName());
        self::assertSame([1, 5], $view->getData()['schedule']->days);
        self::assertSame('22:15', $view->getData()['schedule']->time);
        self::assertNotNull($view->getData()['nextRun']);

        $controller->update($this->request('/', 'POST', ['enabled' => '0'] + $data));
        self::assertFalse(BackupSchedule::fromSettings((new PluginSettings())->all())->enabled);
        self::assertSame('Scheduled backups are paused.', $this->session->get('success'));
    }

    public function testInvalidScheduleIsRejected(): void {
        $this->expectException(ValidationException::class);
        (new BackupScheduleController())->update($this->request('/', 'POST', [
            'enabled' => '1', 'days' => [1, 1], 'time' => '25:00', 'timezone' => 'UTC',
        ]));
    }

    public function testDatabaseFailureReturnsTheSubmittedSchedule(): void {
        DB::connection('inventory')->statement(<<<'SQL'
            CREATE TRIGGER reject_schedule BEFORE UPDATE ON plugins
            BEGIN SELECT RAISE(ABORT, 'test schedule write failure'); END
            SQL);
        $data = ['enabled' => '1', 'days' => [1], 'time' => '22:15', 'timezone' => 'UTC'];
        (new BackupScheduleController())->update($this->request('/', 'POST', $data));
        self::assertSame('Could not save the backup schedule.', $this->session->get('error'));
        self::assertSame('22:15', $this->session->getOldInput('time'));
    }

    public function testMiddlewareRequiresAdminAndReturnsDatabaseReadinessErrors(): void {
        $middleware = new RequireBackupDatabase();
        $next = static fn () => new Response('ready');
        $request = $this->request();
        self::assertSame('ready', $middleware->handle($request, $next)->getContent());

        $this->plugin([], false);
        $this->assertHttpError(404, fn () => $middleware->handle($request, $next));

        $this->plugin([]);
        $response = $middleware->handle($request, $next);
        self::assertStringEndsWith('/plugin/device-backups/settings', $response->getTargetUrl());
        $json = $this->request('/', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(503, $middleware->handle($json, $next)->getStatusCode());

        app('auth')->setUser(new UserFixture(['id' => 1, 'role' => 'user']));
        $this->assertHttpError(403, fn () => $middleware->handle($request, $next));
        $this->assertHttpError(403, fn () => (new BackupScheduleController())->edit());
    }
}
