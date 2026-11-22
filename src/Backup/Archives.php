<?php

namespace DRP\DeviceBackups\Backup;

use ZipArchive;

/**
 * LibreNMS Device Backups Archives Utility.
 *
 * @package     librenms-device-backups
 * @author      Daryl Peterson <daryl.peterson@gmail.com>
 * @copyright   Copyright (c) 2026, Daryl Peterson
 * @license     https://opensource.org MIT License
 * @link        https://github.com/daryl-peterson/
 * @since       0.0.1
 */
final class Archives {
    /**
     * Verify that the required ZIP capabilities are available.
     *
     * @throws BackupFailure If the required ZIP capabilities are not available.
     *
     * @since 0.0.1
     */
    public static function verifyAvailable(): void {
        if (
            !class_exists(ZipArchive::class) || !ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256)
            || !ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, false)
        ) {
            throw new BackupFailure('PHP ZIP with AES-256 encryption and decryption is required.');
        }
    }

    /**
     * Check if there is enough free space in the given directory.
     *
     * @param string $root The root directory to check.
     * @param int $reserve The minimum required free space in bytes.
     *
     * @throws BackupFailure If the free space is below the reserve.
     *
     * @since 0.0.1
     */
    public static function checkSpace(string $root, int $reserve): void {
        $free = disk_free_space($root);
        if ($free === false || $free < $reserve) {
            throw new BackupFailure('Backup storage reached its free-space reserve.');
        }
    }

    /**
     * Create an encrypted ZIP archive of the given source file.
     *
     * @param string $target The target ZIP file path.
     * @param string $source The source file to include in the ZIP.
     * @param string $member The name of the file inside the ZIP archive.
     * @param string $password The password for AES-256 encryption.
     *
     * @return string The SHA-256 checksum of the created ZIP archive.
     *
     * @throws BackupFailure If the ZIP creation or verification fails.
     *
     * @since 0.0.1
     */
    public static function zip(string $target, string $source, string $member, #[\SensitiveParameter] string $password): string {
        $partial = $target . '.partial';
        $zip = new ZipArchive();
        $sourceHash = hash_file('sha256', $source);
        $opened = false;

        try {
            if ($sourceHash === false || $zip->open($partial, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new BackupFailure('Could not open an encrypted backup archive for writing.');
            }
            $opened = true;
            if (!$zip->addFile($source, $member) || !$zip->setEncryptionName($member, ZipArchive::EM_AES_256, $password)) {
                throw new BackupFailure('Could not add the capture using AES-256 encryption.');
            }
            if (!$zip->close()) {
                throw new BackupFailure('Could not finalize the encrypted backup archive.');
            }
            $opened = false;

            if ($zip->open($partial, ZipArchive::RDONLY) !== true) {
                throw new BackupFailure('Could not reopen the encrypted backup archive.');
            }
            $opened = true;
            $zip->setPassword($password);
            $entry = $zip->statName($member);
            if ($zip->numFiles !== 1 || ($entry['encryption_method'] ?? null) !== ZipArchive::EM_AES_256) {
                throw new BackupFailure('Encrypted ZIP entry verification failed.');
            }
            $stream = $zip->getStream($member);
            if ($stream === false) {
                throw new BackupFailure('Could not decrypt the archive for readback verification.');
            }
            try {
                $hash = hash_init('sha256');
                hash_update_stream($hash, $stream);
                if (!hash_equals($sourceHash, hash_final($hash))) {
                    throw new BackupFailure('Encrypted ZIP readback checksum did not match the capture.');
                }
            } finally {
                fclose($stream);
            }
            $zip->close();
            $opened = false;

            $checksum = hash_file('sha256', $partial);
            if ($checksum === false || !rename($partial, $target)) {
                throw new BackupFailure('Could not publish the encrypted archive.');
            }
            return $checksum;
        } finally {
            if ($opened) {
                $zip->close();
            }
            if (is_file($partial)) {
                unlink($partial);
            }
        }
    }
}
