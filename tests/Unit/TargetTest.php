<?php

namespace DRP\DeviceBackups\Tests\Unit;

use DRP\DeviceBackups\Backup\Target;
use DRP\DeviceBackups\Tests\Support\DeviceFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TargetTest extends TestCase {
    #[DataProvider('detectedDevices')]
    public function testSuggestsTheAppropriateBackupProfile(DeviceFixture $device, ?string $expected): void {
        self::assertSame($expected, Target::profile($device));
    }

    public static function detectedDevices(): array {
        return [
            'Cisco IOS XE' => [new DeviceFixture(os: 'iosxe'), 'ios'],
            'Dell PowerConnect' => [new DeviceFixture(os: 'powerconnect'), 'dell'],
            'EdgeRouter' => [new DeviceFixture(os: 'edgeos'), 'edgeos'],
            'EdgeSwitch' => [new DeviceFixture(os: 'edgeswitch', hardware: 'ES-24-250W'), 'edgeswitch'],
            'airOS' => [new DeviceFixture(os: 'airos'), 'airos'],
            'classic airFiber' => [new DeviceFixture(os: 'airos-af'), 'airfiber'],
            'airFiber 60' => [new DeviceFixture(os: 'airos-af60'), null],
            'UniFi reported as EdgeSwitch' => [new DeviceFixture(os: 'edgeswitch', sysDescr: 'USW-48'), null],
            'Linux needs an explicit server profile' => [new DeviceFixture(os: 'linux'), null],
        ];
    }

    #[DataProvider('archiveProfiles')]
    public function testSelectsArchiveMemberAndCliHostnameRequirement(string $profile, string $member, bool $needsHostname): void {
        self::assertContains($profile, Target::profiles());
        self::assertSame($member, Target::archiveMember($profile));
        self::assertSame($needsHostname, Target::needsCliHostname($profile));
    }

    public static function archiveProfiles(): array {
        return [
            ['ios', 'running-config.txt', true],
            ['edgeswitch', 'running-config.txt', false],
            ['edgeos', 'config.boot', false],
            ['airos', 'system.cfg', false],
            ['airfiber', 'system.cfg', false],
            ['dhcp', 'configuration-and-state.tar.gz', false],
        ];
    }

    public function testDerivesPromptNameAndHonorsAnOverride(): void {
        $device = new DeviceFixture(hostname: 'inventory.example.test', sysName: 'detected.example.test');

        self::assertSame('detected', Target::promptName($device, []));
        self::assertSame('custom', Target::promptName($device, ['prompt_name' => 'custom']));
        $device->sysName = null;
        self::assertSame('inventory', Target::promptName($device, []));
    }
}
