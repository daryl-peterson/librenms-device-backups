<?php

namespace DRP\DeviceBackups\Tests\Unit;

use DRP\DeviceBackups\Backup\DeviceSshStatus;
use DRP\DeviceBackups\Backup\Target;
use DRP\DeviceBackups\Tests\Support\DeviceFixture;
use PHPUnit\Framework\TestCase;

final class DeviceSshStatusTest extends TestCase {
    public function testLeavesOldSshChecksBehindWhenEnrollmentChanges(): void {
        $device = new DeviceFixture(attributes: [Target::ATTRIBUTE => 'old encrypted enrollment']);
        $check = [
            'revision' => Target::revision($device),
            'status' => 'connected',
            'checked_at' => '2026-11-21 12:00:00',
        ];
        $device->attributes[DeviceSshStatus::ATTRIBUTE] = json_encode($check, JSON_THROW_ON_ERROR);

        self::assertSame($check, DeviceSshStatus::get($device));

        $device->hostname = 'replacement.example.test';
        self::assertNull(DeviceSshStatus::get($device));

        $device->hostname = 'router.example.test';
        $device->attributes[Target::ATTRIBUTE] = 'new encrypted enrollment';
        self::assertNull(DeviceSshStatus::get($device));
    }

    public function testIgnoresAnUnreadableStoredStatus(): void {
        $device = new DeviceFixture(attributes: [
            Target::ATTRIBUTE => 'encrypted enrollment',
            DeviceSshStatus::ATTRIBUTE => 'bad json',
        ]);

        self::assertNull(DeviceSshStatus::get($device));
    }
}
