<?php

namespace DRP\DeviceBackups\Tests\Unit;

use DRP\DeviceBackups\Backup\BackupSchedule;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

final class BackupScheduleTest extends TestCase {
    public function testBuildsTheCronExpressionForMultipleDays(): void {
        $schedule = new BackupSchedule(true, [0, 1, 4], '06:35', 'America/Denver');

        self::assertSame('35 6 * * 0,1,4', $schedule->expression());
        $next = $schedule->nextRun();
        self::assertNotNull($next);
        self::assertSame('America/Denver', $next->getTimezone()->getName());
        self::assertSame('06:35', $next->format('H:i'));
        self::assertContains((int) $next->format('w'), [0, 1, 4]);
    }

    public function testSavedPauseOverridesTheLegacySchedule(): void {
        $previous = Container::getInstance();
        $container = new Container();
        $container->instance('config', new Repository(['device-backups' => ['scheduled' => true]]));
        Container::setInstance($container);

        try {
            $legacy = BackupSchedule::fromSettings([]);
            self::assertTrue($legacy->enabled);
            self::assertSame('0 18 * * 5', $legacy->expression());

            $paused = BackupSchedule::fromSettings([BackupSchedule::SETTINGS_KEY => [
                'enabled' => false, 'days' => [2], 'time' => '00:05', 'timezone' => 'UTC',
            ]]);
            self::assertNull($paused->nextRun());
            self::assertSame('5 0 * * 2', $paused->expression());
        } finally {
            Container::setInstance($previous);
        }
    }
}
