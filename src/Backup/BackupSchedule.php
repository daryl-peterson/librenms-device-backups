<?php

namespace DRP\DeviceBackups\Backup;

use Cron\CronExpression;
use DateTimeInterface;

/**
 * LibreNMS Device Backups Schedule Utility.
 *
 * @package     librenms-device-backups
 * @author      Daryl Peterson <daryl.peterson@gmail.com>
 * @copyright   Copyright (c) 2026, Daryl Peterson
 * @license     https://opensource.org MIT License
 * @link        https://github.com/daryl-peterson/
 * @since       0.0.1
 */
final readonly class BackupSchedule {
    public const SETTINGS_KEY = 'backup_schedule';

    public const WEEKDAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        0 => 'Sunday',
    ];

    /** @param list<int> $days */
    public function __construct(
        public bool $enabled,
        public array $days,
        public string $time,
        public string $timezone,
    ) {
    }

    /**
     * Create a BackupSchedule instance from the given settings array.
     *
     * @param array $settings
     * @return self
     *
     * @since 0.0.1
     */
    public static function fromSettings(array $settings): self {
        $schedule = $settings[self::SETTINGS_KEY] ?? null;
        if (!is_array($schedule)) {
            return new self((bool) config('device-backups.scheduled'), [5], '18:00', 'America/Chicago');
        }

        return new self($schedule['enabled'], $schedule['days'], $schedule['time'], $schedule['timezone']);
    }

    /**
     * Get the cron expression for the backup schedule.
     *
     * @return string
     *
     * @since 0.0.1
     */
    public function expression(): string {
        [$hours, $minutes] = explode(':', $this->time);

        return (int) $minutes . ' ' . (int) $hours . ' * * ' . implode(',', $this->days);
    }

    /**
     * Get the next run date for the backup schedule.
     *
     * @return DateTimeInterface|null
     *
     * @since 0.0.1
     */
    public function nextRun(): ?DateTimeInterface {
        return $this->enabled
            ? (new CronExpression($this->expression()))->getNextRunDate('now', 0, false, $this->timezone)
            : null;
    }
}
