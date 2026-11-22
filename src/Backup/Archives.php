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
    public static function verifyAvailable(): void {
        if (
            !class_exists(ZipArchive::class) || !ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256)
            || !ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, false)
        ) {
            throw new BackupFailure('PHP ZIP with AES-256 encryption and decryption is required.');
        }
    }

    public static function checkSpace(string $root, int $reserve): void {
        $free = disk_free_space($root);
        if ($free === false || $free < $reserve) {
            throw new BackupFailure('Backup storage reached its free-space reserve.');
        }
    }

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
