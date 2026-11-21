<?php

namespace DRP\DeviceBackups\Backup;

use phpseclib4\Net\SSH2;

final class SshConnection {
    public static function host(string $host): string {
        $host = trim($host, '[]');
        if (!preg_match('/\A[a-zA-Z0-9_.:-]+\z/', $host) || (str_contains($host, ':') && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))) {
            throw new BackupFailure('The SSH host must be a hostname or IP address.');
        }

        return str_contains($host, ':') ? "[$host]" : $host;
    }

    public static function fingerprint(SSH2 $connection): string {
        $publicKey = $connection->getServerPublicHostKey();
        $encoded = explode(' ', $publicKey ?? '', 2)[1] ?? '';
        $key = base64_decode($encoded, true);
        if (!$key) {
            throw new BackupFailure('Could not verify the SSH server host key.');
        }

        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $key, true)), '=');
    }

    public static function login(SSH2 $connection, string $fingerprint, string $user, #[\SensitiveParameter] string $password): void {
        if (!self::validFingerprint($fingerprint) || !hash_equals($fingerprint, self::fingerprint($connection))) {
            throw new SshFailure('host_key_mismatch', 'SSH host key did not match the configured fingerprint.');
        }
        if (!$connection->login($user, $password)) {
            throw new SshFailure('authentication_failed', 'SSH authentication failed.');
        }
    }

    public static function validFingerprint(string $fingerprint): bool {
        return preg_match('/\ASHA256:[a-zA-Z0-9+\/]{43}\z/', $fingerprint) === 1;
    }
}
