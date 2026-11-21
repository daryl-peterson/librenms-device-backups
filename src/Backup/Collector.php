<?php

namespace DRP\DeviceBackups\Backup;

use PharData;
use phpseclib4\Net\SSH2;

final class Collector {
    private const OUTPUT_LIMIT = 16 * 1024 ** 2;
    private const EXPORT_LIMIT = 64 * 1024 ** 2;
    private const TAR_LIMIT = 256 * 1024 ** 2;
    private const CLI_ERRORS = '/^\h*(?:%\h*)?(?:invalid (?:input|command)|incomplete command|ambiguous command|unrecognized command|authorization failed|permission denied|ERROR:|MINOR:|MAJOR:|CRITICAL:)|--more--/im';

    public static function capture(SSH2 $ssh, string $hostname, #[\SensitiveParameter] array $settings, string $path): void {
        $profile = $settings['profile'];
        if (in_array($profile, Target::SWITCHES, true)) {
            $configuration = self::switchConfig($ssh, $hostname, $profile, $settings['enable']);
            if (file_put_contents($path, $configuration, LOCK_EX) !== strlen($configuration)) {
                throw new BackupFailure('Could not stage the running configuration.');
            }
            return;
        }
        if (!in_array($profile, Target::SERVERS, true)) {
            throw new BackupFailure('Unsupported backup profile.');
        }

        self::serverArchive($ssh, self::archiveCommand($profile), $path);
        self::validateArchive($path, $profile);
    }

    private static function switchConfig(SSH2 $ssh, string $hostname, string $profile, #[\SensitiveParameter] ?string $enable): string {
        $stem = ($profile === 'timos' ? '\\*?[AB]:' : '') . preg_quote($hostname, '~');
        $prompt = "~^$stem#\\h*\\z~m";
        $ssh->setWindowSize(200, 24);
        $ssh->setTimeout(15);
        $ssh->openShell();
        $screen = self::readPrompt($ssh, "~^{$stem}[>#]\\h*\\z~m");
        if (str_ends_with(rtrim($screen), '>')) {
            $ssh->write("enable\n");
            $screen = self::readPrompt($ssh, "~^(?:$stem#|(?i:password:))\\h*\\z~m");
            if (preg_match('/password:\h*\z/i', $screen)) {
                if ($enable === null || $enable === '') {
                    throw new BackupFailure('This device needs an enable password.');
                }

                $ssh->write($enable . "\n");
                self::readPrompt($ssh, $prompt);
            }
        }

        [$pagination, $command] = match ($profile) {
            'timos' => ['environment no more', 'admin display-config'],
            'asa' => ['terminal pager 0', 'more system:running-config'],
            default => ['terminal length 0', 'show running-config'],
        };

        $ssh->write($pagination . "\n");
        if (preg_match(self::CLI_ERRORS, self::readPrompt($ssh, $prompt))) {
            throw new BackupFailure('Device rejected the pagination command.');
        }
        $ssh->write($command . "\n");
        $text = trim(preg_replace($prompt, '', self::readPrompt($ssh, $prompt, 180)));
        if (str_starts_with($text, $command . "\n")) {
            $text = ltrim(substr($text, strlen($command)));
        }

        return self::validateConfig($profile, $hostname, $text) . "\n";
    }

    private static function readPrompt(SSH2 $ssh, string $pattern, int $seconds = 30): string {
        $deadline = hrtime(true) + $seconds * 1_000_000_000;
        $output = '';
        while (hrtime(true) < $deadline) {
            $ssh->setTimeout(max(1, (int) ceil(($deadline - hrtime(true)) / 1_000_000_000)));
            $chunk = $ssh->read('', SSH2::READ_NEXT);
            if ($ssh->isTimeout()) {
                break;
            }
            if (!is_string($chunk) || $chunk === '') {
                throw new BackupFailure('Device closed the SSH shell before the expected prompt.');
            }

            $output .= str_replace("\r", '', $chunk);
            if (strlen($output) > self::OUTPUT_LIMIT) {
                throw new BackupFailure('Device configuration exceeded the output limit.');
            }
            if (preg_match($pattern, substr($output, -2048))) {
                return $output;
            }
        }

        throw new BackupFailure('Timed out waiting for the device CLI prompt.');
    }

    private static function validateConfig(string $profile, string $hostname, string $text): string {
        if (strlen($text) < 1000 || preg_match(self::CLI_ERRORS, $text)) {
            throw new BackupFailure('Configuration was too short or contained CLI errors or pagination.');
        }

        $hasInterfaces = preg_match('/^\h*interface\h+/m', $text);
        $hasHostname = preg_match('/^hostname\h+' . preg_quote($hostname, '/') . '\h*$/m', $text);
        $complete = match ($profile) {
            'timos' => str_contains($text, '# TiMOS') && str_contains($text, '# Finished'),
            'ios' => $hasInterfaces && $hasHostname && preg_match('/^end\h*\z/m', $text),
            'nxos' => $hasInterfaces && $hasHostname,
            'asa' => $hasInterfaces && preg_match('/^: end\h*$/m', $text),
            default => $hasInterfaces,
        };
        if (!$complete) {
            throw new BackupFailure('Expected configuration markers or hostname were missing.');
        }

        return $text;
    }

    private static function archiveCommand(string $profile): string {
        if (str_ends_with($profile, '-helper')) {
            $helper = $profile === 'kea-helper' ? 'kea' : 'dhcp';
            return "sudo -n -- /usr/local/sbin/netmonitor-$helper-backup";
        }

        $paths = [];
        $optional = [];
        if (str_contains($profile, 'dhcp')) {
            $paths = ['etc/dhcp', 'var/lib/dhcpd'];
            $optional = ['etc/sysconfig/dhcpd', 'etc/systemd/system/dhcpd.service', 'etc/systemd/system/dhcpd.service.d', 'usr/lib/systemd/system/dhcpd.service'];
        }
        if (str_contains($profile, 'dns')) {
            array_push($paths, 'etc/named.conf', 'var/named');
            array_push($optional, 'etc/named*', 'etc/rndc.conf', 'etc/rndc.key', 'etc/sysconfig/named', 'etc/logrotate.d/named',
                'etc/systemd/system/named.service', 'etc/systemd/system/named.service.d', 'usr/lib/systemd/system/named.service');
        }
        if ($profile === 'dns-old') {
            array_push($optional, 'etc/init.d/named', 'usr/local/sbin/named', 'usr/local/sbin/rndc', 'usr/local/sbin/named-checkconf', 'usr/local/sbin/named-checkzone');
        }
        if ($profile === 'kea') {
            $paths = ['etc/kea', 'var/lib/kea'];
        }

        $script = "set -eu\ncd /\nset -- " . implode(' ', $paths) . "\n";
        if ($optional) {
            $script .= 'for p in ' . implode(' ', $optional) . "; do\n"
                . 'if [ -e "$p" ] || [ -L "$p" ]; then set -- "$@" "$p"; fi' . "\ndone\n";
        }

        $flags = str_ends_with($profile, '-old') ? '--numeric-owner' : '--acls --xattrs --selinux --numeric-owner';
        $exclude = str_contains($profile, 'dns') ? " --exclude='var/named/data/named.run' --exclude='var/named/data/named.run-*'" : '';
        $script .= "/usr/bin/env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin /bin/tar $flags$exclude -czf - -- " . '"$@"';

        return '/bin/sh -c ' . escapeshellarg($script);
    }

    private static function serverArchive(SSH2 $ssh, string $command, string $path): void {
        $file = fopen($path, 'wb');
        if (!$file) {
            throw new BackupFailure('Could not stage the server export.');
        }

        $bytes = 0;
        $deadline = hrtime(true) + 240_000_000_000;
        try {
            $ssh->enableQuietMode();
            $ssh->setTimeout(20);
            $ssh->exec($command, static function (string $chunk) use ($file, &$bytes, $deadline): void {
                $bytes += strlen($chunk);
                if ($bytes > self::EXPORT_LIMIT || hrtime(true) > $deadline) {
                    throw new BackupFailure('Server export exceeded its size or time limit.');
                }
                if (fwrite($file, $chunk) !== strlen($chunk)) {
                    throw new BackupFailure('Could not write the server export.');
                }
            });

            if (!$bytes || $ssh->isTimeout() || $ssh->getExitStatus() !== 0) {
                throw new BackupFailure('Server export failed, was empty, or timed out.');
            }
        } finally {
            fclose($file);
        }
    }

    private static function validateArchive(string $path, string $profile): void {
        $stream = fopen($path, 'rb');
        $gzip = inflate_init(ZLIB_ENCODING_GZIP);
        if (!$stream || !$gzip) {
            throw new BackupFailure('Could not read the gzip export.');
        }

        $size = 0;
        try {
            while (!feof($stream)) {
                $input = fread($stream, 4096);
                if ($input === false) {
                    throw new BackupFailure('Could not read the gzip export.');
                }
                if ($input === '') {
                    break;
                }
                $output = @inflate_add($gzip, $input);
                if ($output === false || ($size += strlen($output)) > self::TAR_LIMIT) {
                    throw new BackupFailure('Server export failed gzip integrity or size validation.');
                }
            }
            if (inflate_get_status($gzip) !== ZLIB_STREAM_END || inflate_get_read_len($gzip) !== filesize($path)) {
                throw new BackupFailure('Server export had an incomplete or invalid gzip trailer.');
            }
        } finally {
            fclose($stream);
        }

        $required = [];
        if (str_contains($profile, 'dhcp')) {
            array_push($required, 'etc/dhcp/dhcpd.conf', 'var/lib/dhcpd/dhcpd.leases');
        }
        if (str_contains($profile, 'dns')) {
            array_push($required, 'etc/named.conf', 'var/named');
        }
        if (str_starts_with($profile, 'kea')) {
            array_push($required, 'etc/kea/kea-dhcp4.conf', 'var/lib/kea/kea-leases4.csv');
        }

        $tar = new PharData($path);
        foreach ($required as $member) {
            if (!isset($tar[$member])) {
                throw new BackupFailure('Server archive is missing required configuration or lease files.');
            }
        }
    }
}
