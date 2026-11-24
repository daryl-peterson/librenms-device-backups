<?php

namespace DRP\DeviceBackups\Backup;

use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

final class BackupComparison {
    private const MAX_CONFIG_BYTES = 1024 * 1024;
    private const MAX_ARCHIVE_BYTES = 2 * self::MAX_CONFIG_BYTES;

    public static function supports(?object $latest, ?object $previous): bool {
        $profile = $latest->profile ?? null;

        return $profile !== null && $profile === ($previous->profile ?? null)
            && (in_array($profile, Target::SWITCHES, true) || isset(Target::CONFIG_FILES[$profile]));
    }

    public static function compare(
        BackupStore $store,
        object $previous,
        object $latest,
        #[\SensitiveParameter] string $password,
    ): string {
        if (!self::supports($latest, $previous)) {
            throw new BackupFailure('Comparison requires two text configuration backups from the same profile.');
        }

        Archives::verifyAvailable();
        $member = Target::archiveMember($latest->profile);
        $before = self::openConfig($store, $previous, $member, $password);
        try {
            $after = self::openConfig($store, $latest, $member, $password);
            try {
                $process = new Process([
                    'diff', '-u', '--label', 'Previous', '--label', 'Latest', '--',
                    stream_get_meta_data($before)['uri'], stream_get_meta_data($after)['uri'],
                ]);
                $process->setTimeout(5);
                try {
                    $exit = $process->run();
                } catch (RuntimeException) {
                    throw new BackupFailure('Could not run the configuration comparison.');
                }
                if (!in_array($exit, [0, 1], true)) {
                    throw new BackupFailure('Could not run the configuration comparison.');
                }

                return $process->getOutput();
            } finally {
                fclose($after);
            }
        } finally {
            fclose($before);
        }
    }

    /** @return resource */
    private static function openConfig(
        BackupStore $store,
        object $result,
        string $member,
        #[\SensitiveParameter] string $password,
    ) {
        if ($result->archive_bytes > self::MAX_ARCHIVE_BYTES) {
            throw new BackupFailure('This backup is too large to compare in the browser.');
        }

        $archive = tmpfile();
        if ($archive === false) {
            throw new BackupFailure('Could not prepare the backup for comparison.');
        }
        try {
            $store->copyVerifiedArchive($result, $archive);
            $zip = new ZipArchive();
            if (!fflush($archive) || $zip->open(stream_get_meta_data($archive)['uri'], ZipArchive::RDONLY) !== true) {
                throw new BackupFailure('Could not open the backup archive.');
            }
            try {
                $entry = $zip->statName($member);
                if ($zip->numFiles !== 1 || ($entry['encryption_method'] ?? null) !== ZipArchive::EM_AES_256) {
                    throw new BackupFailure('The backup archive does not contain the expected encrypted configuration.');
                }
                if ($entry['size'] > self::MAX_CONFIG_BYTES) {
                    throw new BackupFailure('This configuration is too large to compare in the browser.');
                }
                $zip->setPassword($password);
                $text = $zip->getFromName($member, self::MAX_CONFIG_BYTES + 1);
                if ($text === false || strlen($text) !== $entry['size']) {
                    throw new BackupFailure('Could not decrypt the configuration with the current backup password.');
                }
            } finally {
                $zip->close();
            }
        } finally {
            fclose($archive);
        }

        if (str_contains($text, "\0") || !mb_check_encoding($text, 'UTF-8')) {
            throw new BackupFailure('The configuration is not UTF-8 text.');
        }

        $text = str_replace("\r\n", "\n", $text);
        $config = tmpfile();
        if ($config === false) {
            throw new BackupFailure('Could not prepare the configuration for comparison.');
        }
        if (fwrite($config, $text) !== strlen($text) || !fflush($config)) {
            fclose($config);
            throw new BackupFailure('Could not prepare the configuration for comparison.');
        }

        return $config;
    }
}
