<?php

namespace DRP\DeviceBackups\Backup;

use phpseclib4\Net\SSH2;

/**
 * LibreNMS Device Backups SSH Connection.
 * Manages SSH connections to devices for backups.
 *
 * @package     librenms-device-backups
 * @author      Daryl Peterson <daryl.peterson@gmail.com>
 * @copyright   Copyright (c) 2026, Daryl Peterson
 * @license     https://opensource.org MIT License
 * @link        https://github.com/daryl-peterson/
 * @since       0.0.1
 */
final class SshConnection {

    /**
     * Validate and format the SSH host.
     *
     * @param string $host
     * @return string
     *
     * @since 0.0.1
     */
    public static function host(string $host): string {
        $host = trim($host, '[]');
        if (!preg_match('/\A[a-zA-Z0-9_.:-]+\z/', $host) || (str_contains($host, ':') && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))) {
            throw new BackupFailure('The SSH host must be a hostname or IP address.');
        }

        return str_contains($host, ':') ? "[$host]" : $host;
    }

    /**
     * Get the fingerprint of the SSH server host key.
     *
     * @param SSH2 $connection
     * @return string
     *
     * @since 0.0.1
     */
    public static function fingerprint(SSH2 $connection): string {
        $publicKey = $connection->getServerPublicHostKey();
        $encoded = explode(' ', $publicKey ?? '', 2)[1] ?? '';
        $key = base64_decode($encoded, true);
        if (!$key) {
            throw new BackupFailure('Could not verify the SSH server host key.');
        }

        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $key, true)), '=');
    }

    /**
     * Attempt to login to the SSH server using the provided credentials and fingerprint.
     *
     * @param SSH2 $connection
     * @param string $fingerprint
     * @param string $user
     * @param string $password
     * @return void
     *
     * @since 0.0.1
     */
    public static function login(SSH2 $connection, string $fingerprint, string $user, #[\SensitiveParameter] string $password): void {
        if (!self::validFingerprint($fingerprint) || !hash_equals($fingerprint, self::fingerprint($connection))) {
            throw new SshFailure('host_key_mismatch', 'SSH host key did not match the configured fingerprint.');
        }
        if (!$connection->login($user, $password)) {
            throw new SshFailure('authentication_failed', 'SSH authentication failed.');
        }
    }

    /**
     * Validate the format of the SSH fingerprint.
     *
     * @param string $fingerprint
     * @return bool
     *
     * @since 0.0.1
     */
    public static function validFingerprint(string $fingerprint): bool {
        return preg_match('/\ASHA256:[a-zA-Z0-9+\/]{43}\z/', $fingerprint) === 1;
    }
}
