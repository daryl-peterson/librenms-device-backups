<?php

namespace DRP\DeviceBackups\Tests\Unit;

use DRP\DeviceBackups\Backup\BackupFailure;
use DRP\DeviceBackups\Backup\SshConnection;
use DRP\DeviceBackups\Backup\SshFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use phpseclib4\Net\SSH2;

final class SshConnectionTest extends TestCase {
    private const HOST_KEY = 'fixture SSH public key';

    #[DataProvider('validHosts')]
    public function testNormalizesSshAddresses(string $input, string $expected): void {
        self::assertSame($expected, SshConnection::host($input));
    }

    public static function validHosts(): array {
        return [
            'DNS' => ['switch.example.test', 'switch.example.test'],
            'IPv4' => ['192.0.2.10', '192.0.2.10'],
            'IPv6' => ['2001:db8::10', '[2001:db8::10]'],
            'bracketed IPv6' => ['[2001:db8::10]', '[2001:db8::10]'],
        ];
    }

    #[DataProvider('invalidHosts')]
    public function testRejectsMalformedSshAddresses(string $host): void {
        $this->expectException(BackupFailure::class);
        SshConnection::host($host);
    }

    public static function invalidHosts(): array {
        return [[''], ['ssh://switch.example.test'], ['switch.example.test:22'], ["host name"]];
    }

    public function testMatchingHostKeyAllowsPasswordLogin(): void {
        $ssh = $this->createMock(SSH2::class);
        $ssh->expects(self::once())->method('getServerPublicHostKey')->willReturn(self::publicKey());
        $ssh->expects(self::once())->method('login')->with('operator', 'fixture-password')->willReturn(true);

        SshConnection::login($ssh, self::fingerprint(), 'operator', 'fixture-password');
    }

    public function testHostKeyMismatchNeverSendsThePassword(): void {
        $ssh = $this->createMock(SSH2::class);
        $ssh->expects(self::once())->method('getServerPublicHostKey')->willReturn(self::publicKey());
        $ssh->expects(self::never())->method('login');

        try {
            SshConnection::login($ssh, 'SHA256:' . str_repeat('A', 43), 'operator', 'fixture-password');
            self::fail('A different host key must stop the login.');
        } catch (SshFailure $e) {
            self::assertSame('host_key_mismatch', $e->status);
        }
    }

    public function testRejectedPasswordReportsAuthenticationFailure(): void {
        $ssh = $this->createStub(SSH2::class);
        $ssh->method('getServerPublicHostKey')->willReturn(self::publicKey());
        $ssh->method('login')->willReturn(false);

        try {
            SshConnection::login($ssh, self::fingerprint(), 'operator', 'incorrect-password');
            self::fail('A rejected password must fail the login.');
        } catch (SshFailure $e) {
            self::assertSame('authentication_failed', $e->status);
        }
    }

    private static function publicKey(): string {
        return 'ssh-ed25519 ' . base64_encode(self::HOST_KEY);
    }

    private static function fingerprint(): string {
        return 'SHA256:' . rtrim(base64_encode(hash('sha256', self::HOST_KEY, true)), '=');
    }
}
