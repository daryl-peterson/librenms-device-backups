<?php

namespace DRP\DeviceBackups\Tests\Unit;

use Closure;
use DRP\DeviceBackups\Backup\BackupFailure;
use DRP\DeviceBackups\Backup\Collector;
use DRP\DeviceBackups\Tests\Support\TemporaryFilesTestCase;
use PharData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Constraint\Constraint;
use phpseclib4\Net\SSH2;

final class CollectorTest extends TemporaryFilesTestCase {
    public function testCapturesIosConfigurationAfterEnteringEnableMode(): void {
        $configuration = "hostname router\ninterface GigabitEthernet0/1\n" . str_repeat("! example line\n", 80) . "end\n";
        $ssh = $this->shell(
            ['router>', 'Password:', 'router#', 'router#', "show running-config\r\n" . $configuration . 'router#'],
            ["enable\n", "fixture-enable\n", "terminal length 0\n", "show running-config\n"],
        );
        $path = $this->path('running-config.txt');

        Collector::capture($ssh, 'router', ['profile' => 'ios', 'enable' => 'fixture-enable'], $path);

        self::assertSame($configuration, file_get_contents($path));
    }

    public function testDetectsEdgeSwitchPromptAndAcceptsAShortConfiguration(): void {
        $configuration = "!Current Configuration:\nconfigure\ninterface 0/1\nexit\nexit\n";
        $ssh = $this->shell(
            ['(UBNT EdgeSwitch) #', '(UBNT EdgeSwitch) #', "show running-config\n" . $configuration . '(UBNT EdgeSwitch) #'],
            ["terminal length 0\n", "show running-config\n"],
        );
        $path = $this->path('running-config.txt');

        Collector::capture($ssh, '', ['profile' => 'edgeswitch', 'enable' => null], $path);

        self::assertSame($configuration, file_get_contents($path));
    }

    public function testRejectsPaginatedOutputWithoutPublishingAConfiguration(): void {
        $ssh = $this->shell(
            ['(UBNT EdgeSwitch) #', '(UBNT EdgeSwitch) #', "!Current Configuration:\ninterface 0/1\n--More--\n(UBNT EdgeSwitch) #"],
            ["terminal length 0\n", "show running-config\n"],
        );
        $path = $this->path('running-config.txt');

        $this->expectException(BackupFailure::class);
        $this->expectExceptionMessage('pagination');
        try {
            Collector::capture($ssh, '', ['profile' => 'edgeswitch', 'enable' => null], $path);
        } finally {
            self::assertFileDoesNotExist($path);
        }
    }

    public function testCapturesClassicAirFiberFromItsInteractivePrompt(): void {
        $configuration = "system.hostname=radio\nradio.1.status=enabled\n";
        $ssh = $this->shell(
            ['AF11FX# ', "cat /tmp/system.cfg\n" . $configuration . 'AF11FX# '],
            ["cat /tmp/system.cfg\n"],
        );
        $path = $this->path('system.cfg');

        Collector::capture($ssh, '', ['profile' => 'airfiber'], $path);

        self::assertSame($configuration, file_get_contents($path));
    }

    #[DataProvider('configurationFiles')]
    public function testReadsTheExpectedConfigFileWithoutChangingItsContents(
        string $profile,
        string $command,
        string $configuration,
    ): void {
        $ssh = $this->exec($command, $configuration);
        $path = $this->path('configuration');

        Collector::capture($ssh, '', ['profile' => $profile], $path);

        self::assertSame($configuration, file_get_contents($path));
    }

    public static function configurationFiles(): array {
        return [
            'EdgeRouter' => ['edgeos', 'cat /config/config.boot', "system {\n    host-name fixture\n}\n"],
            'airOS' => ['airos', 'cat /tmp/system.cfg', "system.hostname=radio\nnetconf.1.devname=eth0\n"],
        ];
    }

    public function testRejectsUnexpectedContentEvenWhenTheRemoteCommandSucceeds(): void {
        $ssh = $this->exec('cat /tmp/system.cfg', "Login page\n");

        $this->expectException(BackupFailure::class);
        $this->expectExceptionMessage('expected format');

        Collector::capture($ssh, '', ['profile' => 'airos'], $this->path('system.cfg'));
    }

    public function testRejectsOutputWhenTheRemoteCommandExitsUnsuccessfully(): void {
        $ssh = $this->exec('cat /config/config.boot', "system {\n    host-name fixture\n}\n", 1);

        $this->expectException(BackupFailure::class);
        $this->expectExceptionMessage('Remote export failed');

        Collector::capture($ssh, '', ['profile' => 'edgeos'], $this->path('config.boot'));
    }

    public function testValidatesTheDhcpConfigurationAndLeaseFile(): void {
        $data = $this->dhcpArchive(includeLeases: true);
        $ssh = $this->exec(self::stringContains('/bin/tar'), $data);
        $path = $this->path('configuration-and-state.tar.gz');

        Collector::capture($ssh, '', ['profile' => 'dhcp'], $path);

        self::assertSame($data, file_get_contents($path));
    }

    public function testRejectsADhcpArchiveWithoutTheLeaseFile(): void {
        $ssh = $this->exec(self::stringContains('/bin/tar'), $this->dhcpArchive(includeLeases: false));

        $this->expectException(BackupFailure::class);
        $this->expectExceptionMessage('missing required configuration or lease files');

        Collector::capture($ssh, '', ['profile' => 'dhcp'], $this->path('configuration-and-state.tar.gz'));
    }

    public function testRejectsCorruptedGzipOutput(): void {
        $data = $this->dhcpArchive(includeLeases: true);
        $data[-8] = chr(ord($data[-8]) ^ 1);
        $ssh = $this->exec(self::stringContains('/bin/tar'), $data);

        $this->expectException(BackupFailure::class);
        $this->expectExceptionMessage('gzip integrity');

        Collector::capture($ssh, '', ['profile' => 'dhcp'], $this->path('configuration-and-state.tar.gz'));
    }

    private function dhcpArchive(bool $includeLeases): string {
        $path = $this->path('fixture.tar');
        $tar = new PharData($path);
        $tar->addFromString('etc/dhcp/dhcpd.conf', "# fixture\n");
        if ($includeLeases) {
            $tar->addFromString('var/lib/dhcpd/dhcpd.leases', "# fixture lease data\n");
        }

        return gzencode(file_get_contents($path));
    }

    private function shell(array $output, array $commands): SSH2 {
        $ssh = $this->createMock(SSH2::class);
        $ssh->expects(self::once())->method('openShell');
        $ssh->expects(self::exactly(count($output)))->method('read')->willReturnOnConsecutiveCalls(...$output);
        $ssh->method('isTimeout')->willReturn(false);
        $commandIndex = 0;
        $ssh->expects(self::exactly(count($commands)))->method('write')
            ->willReturnCallback(static function (string $command) use ($commands, &$commandIndex): void {
                self::assertSame($commands[$commandIndex++], $command);
            });

        return $ssh;
    }

    private function exec(string|Constraint $command, string $output, int $exitStatus = 0): SSH2 {
        $ssh = $this->createMock(SSH2::class);
        $ssh->expects(self::once())->method('exec')->with($command, self::isInstanceOf(Closure::class))
            ->willReturnCallback(static function (string $command, Closure $receive) use ($output): ?string {
                $receive($output);
                return null;
            });
        $ssh->method('getExitStatus')->willReturn($exitStatus);
        $ssh->method('isTimeout')->willReturn(false);

        return $ssh;
    }
}
