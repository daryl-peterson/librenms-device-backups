<?php

namespace DRP\DeviceBackups\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use DRP\DeviceBackups\Helper;
use DRP\DeviceBackups\Tests\Support\ServiceStatusCommand;
use Illuminate\Auth\GenericUser;
use Illuminate\Auth\RequestGuard;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Log\Logger as LaravelLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HelperTest extends TestCase {
    #[DataProvider('roles')]
    public function testOnlyAuthenticatedAdminsAreAllowed(?string $role, bool $expected): void {
        $user = $role === null ? null : new class (['role' => $role]) extends GenericUser {
            public function hasRole(string $role): bool {
                return $this->role === $role;
            }
        };
        $app = new Container();
        $app->instance('auth', new RequestGuard(static fn () => $user, Request::create('/')));
        $previousApp = Auth::getFacadeApplication();
        Auth::clearResolvedInstance('auth');
        Auth::setFacadeApplication($app);

        try {
            self::assertSame($expected, Helper::isAdmin());
        } finally {
            Auth::clearResolvedInstance('auth');
            Auth::setFacadeApplication($previousApp);
        }
    }

    public static function roles(): array {
        return [
            'guest' => [null, false],
            'normal user' => ['user', false],
            'admin' => ['admin', true],
        ];
    }

    #[DataProvider('durations')]
    public function testConvertsDurationsToSeconds(int $value, int $minutes, int $hours, int $days): void {
        self::assertSame($minutes, Helper::minutes($value));
        self::assertSame($hours, Helper::hours($value));
        self::assertSame($days, Helper::days($value));
    }

    public static function durations(): array {
        return [
            'positive' => [2, 120, 7_200, 172_800],
            'zero' => [0, 0, 0, 0],
            'negative' => [-2, -120, -7_200, -172_800],
            'multiple days in hours' => [49, 2_940, 176_400, 4_233_600],
        ];
    }

    #[DataProvider('dateFormats')]
    public function testGetDateFormatsTheCurrentTimeInThePhpTimezone(?string $customFormat, string $expectedFormat): void {
        $previousTimezone = date_default_timezone_get();
        $timezone = new DateTimeZone('Asia/Kathmandu');
        date_default_timezone_set($timezone->getName());

        try {
            $before = time();
            $date = $customFormat === null ? Helper::getDate() : Helper::getDate($customFormat);
            $after = time();
        } finally {
            date_default_timezone_set($previousTimezone);
        }

        $parsed = DateTimeImmutable::createFromFormat('!' . $expectedFormat, $date, $timezone);
        self::assertInstanceOf(DateTimeImmutable::class, $parsed);
        self::assertSame($date, $parsed->format($expectedFormat));
        self::assertSame($timezone->getOffset($parsed), $parsed->getOffset());
        self::assertGreaterThanOrEqual($before, $parsed->getTimestamp());
        self::assertLessThanOrEqual($after, $parsed->getTimestamp());
    }

    public static function dateFormats(): array {
        return [
            'default' => [null, 'Y-m-d H:i:s'],
            'custom with offset' => [DATE_ATOM, DATE_ATOM],
        ];
    }

    #[DataProvider('serviceOutputs')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testServiceStatusUsesSystemctlOutput(?string $output, bool $expected): void {
        ServiceStatusCommand::$output = $output;

        self::assertSame($expected, Helper::isServiceActive());
        self::assertSame(['systemctl is-active librenms.service'], ServiceStatusCommand::$commands);
    }

    public static function serviceOutputs(): array {
        return [
            'active' => ["active\n", true],
            'inactive' => ["inactive\n", false],
            'failed' => ["failed\n", false],
            'no output' => [null, false],
        ];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testServiceStatusLogsCommandExceptionsAndReturnsFalse(): void {
        ServiceStatusCommand::$output = new RuntimeException('systemctl is unavailable');
        $handler = new TestHandler();
        $logger = new Logger('helper-test', [$handler]);
        $app = new Container();
        $app->instance('log', new LaravelLogger($logger));
        Log::setFacadeApplication($app);

        self::assertFalse(Helper::isServiceActive());
        self::assertTrue($handler->hasErrorThatContains('systemctl is unavailable'));
    }
}
