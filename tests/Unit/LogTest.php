<?php

namespace DRP\DeviceBackups\Tests\Unit;

use DRP\DeviceBackups\Log;
use DRP\DeviceBackups\Tests\Support\TemporaryFilesTestCase;
use Illuminate\Container\Container;
use Illuminate\Log\Logger as LaravelLogger;
use Illuminate\Support\Facades\Log as LogFacade;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * LibreNMS Device Backups Log Test.
 *
 * @package     device-backups
 * @author      Daryl Peterson <@gmail.com>
 * @license     https://opensource.org MIT License
 * @link        https://github.com/daryl-peterson/
 * @since       0.0.1
 */
final class LogTest extends TemporaryFilesTestCase {

    #[DataProvider('logLevels')]
    public function testWritesTheMessageAtTheRequestedLevel(string $method, Level $level, ?array $context): void {
        $logger = new Logger('device-backups-test');
        $logger->pushHandler(new StreamHandler($this->path('log.txt'), Level::Debug));

        $app = new Container();
        $app->instance('log', new LaravelLogger($logger));

        $previousApp = LogFacade::getFacadeApplication();
        LogFacade::clearResolvedInstance('log');
        LogFacade::setFacadeApplication($app);

        try {
            $message = "$method log message";
            Log::$method($message, $context);
            $contents = Log::read();

            self::assertIsString($contents);
            self::assertStringContainsString('device-backups-test.' . $level->getName() . ':', $contents);
            self::assertStringContainsString('Message : ' . $message, $contents);
            if ($context !== null) {
                self::assertStringContainsString('[backup_id] => 42', $contents);
            }
        } finally {
            LogFacade::clearResolvedInstance('log');
            LogFacade::setFacadeApplication($previousApp);
            $logger->close();
        }
    }

    public static function logLevels(): array {
        return [
            'debug' => ['debug', Level::Debug, null],
            'info with context' => ['info', Level::Info, ['backup_id' => 42]],
            'warning' => ['warning', Level::Warning, null],
            'error' => ['error', Level::Error, null],
            'critical' => ['critical', Level::Critical, null],
        ];
    }
}
