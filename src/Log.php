<?php

/**
 * LibreNMS Device Backups Log Class.
 *
 * @package     device-backups
 * @author      Daryl Peterson <@gmail.com>
 * @copyright   Copyright (c) 2026, Daryl Peterson
 * @license     https://opensource.org MIT License
 * @link        https://github.com/daryl-peterson/
 * @since       0.0.1
 */

namespace DRP\DeviceBackups;

use Illuminate\Support\Facades\Log as Logger;
use Monolog\Handler\StreamHandler;


/**
 * LibreNMS Device Backups Log Class.
 *
 * @package     device-backups
 * @author      Daryl Peterson <@gmail.com>
 * @copyright   Copyright (c) 2026, Daryl Peterson
 * @license     https://opensource.org MIT License
 * @link        https://github.com/daryl-peterson/
 * @since       0.0.1
 */
class Log {
    private static array $ignoredClasses = [self::class];

    // 1. Public API Methods
    public static function info(string $message, mixed $context = null): void {
        self::writeLog('INFO', $message, $context);
    }

    public static function debug(string $message, mixed $context = null): void {
        self::writeLog('DEBUG', $message, $context);
    }

    public static function error(string $message, mixed $context = null): void {
        self::writeLog('ERROR', $message, $context);
    }

    public static function warning(string $message, mixed $context = null): void {
        self::writeLog('WARNING', $message, $context);
    }

    public static function critical(string $message, mixed $context = null): void {
        self::writeLog('CRITICAL', $message, $context);
    }
    public static function read() {
        $path = self::getLogPath();

        // @codeCoverageIgnoreStart
        if (!file_exists($path)) {
            return null;
        }
        // @codeCoverageIgnoreEnd

        $result = file_get_contents($path);
        return $result;
    }

    public static function getLogPath(?string $channel = null) {
        // @codeCoverageIgnoreStart
        $root = Logger::getFacadeRoot();

        // Only call ->channel() if Log is backed by LogManager (standard full Laravel app)
        $logger = ($channel !== null && method_exists($root, 'channel'))
            ? $root->channel($channel)->getLogger()
            : $root->getLogger();

        if (! method_exists($logger, 'getHandlers')) {
            return null;
        }

        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof StreamHandler) {
                return $handler->getUrl();
            }
        }

        return null;
        // @codeCoverageIgnoreEnd
    }

    // 2. Centralized Writer and Tracer
    private static function writeLog(string $level, string $message, mixed $context = null): void {

        try {
            // Increase frame limit slightly since we added the internal 'writeLog' step
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);

            $callerLine = 'unknown';
            $callerFile = 'unknown';
            $callerClass = 'Global';
            $callerFunction = 'main';

            foreach ($trace as $index => $frame) {
                $frameClass = $frame['class'] ?? null;

                if ($frameClass && in_array($frameClass, self::$ignoredClasses, true)) {
                    continue;
                }

                $triggerFrame = $trace[$index - 1] ?? null;
                $callerLine = $triggerFrame['line'] ?? 'unknown';
                $callerFile = $triggerFrame['file'] ?? 'unknown';

                $callerClass = $frameClass ?? 'Global';
                $callerFunction = $frame['function'] ?? 'main';
                break;
            }

            $logEntry = sprintf(
                "\nClass   : %s\nMethod  : %s\nLine    : %s\nFile    : %s\nMessage : %s\n",
                $callerClass,
                $callerFunction,
                $callerLine,
                basename($callerFile),
                $message
            );

            if ($context === null) {
                Logger::log($level, $logEntry);
                return;
            }

            if (is_array($context) || is_object($context)) {
                $message .= PHP_EOL . print_r($context, true);
            }

            $logEntry = sprintf(
                "\nClass   : %s\nMethod  : %s\nLine    : %s\nFile    : %s\nMessage : %s\n",
                $callerClass,
                $callerFunction,
                $callerLine,
                basename($callerFile),
                $message
            );

            Logger::log($level, $logEntry);

            // @codeCoverageIgnoreStart
        } catch (\Throwable $th) {
            Logger::error($th->getMessage(), ['exception' => $th]);
            // @codeCoverageIgnoreEnd
        }
    }
}
