<?php

namespace DRP\DeviceBackups\Backup;

use PharData;
use phpseclib4\Net\SSH2;

/**
 * LibreNMS Device Backups Collector.
 * Collects and captures device configurations for backups.
 *
 * @package     librenms-device-backups
 * @author      Daryl Peterson <@gmail.com>
 * @copyright   Copyright (c) 2026, Daryl Peterson
 * @license     https://opensource.org MIT License
 * @link        https://github.com/daryl-peterson/
 * @since       0.0.1
 */
final class Collector {
    private const OUTPUT_LIMIT = 16 * 1024 ** 2;
    private const EXPORT_LIMIT = 64 * 1024 ** 2;
    private const TAR_LIMIT = 256 * 1024 ** 2;
    private const CLI_ERRORS = '/^\h*(?:%\h*)?(?:invalid (?:input|command)|incomplete command|ambiguous command|unrecognized command|authorization failed|permission denied|ERROR:|MINOR:|MAJOR:|CRITICAL:)|--more--/im';

    /**
     * Capture the configuration of a device.
     *
     * @param SSH2 $ssh
     * @param string $hostname
     * @param array $settings
     * @param string $path
     * @throws BackupFailure
     *
     * @since 0.0.1
     */
    public static function capture(SSH2 $ssh, string $hostname, #[\SensitiveParameter] array $settings, string $path): void {
        $profile = $settings['profile'];
        if (in_array($profile, Target::SWITCHES, true)) {
            $configuration = self::switchConfig($ssh, $hostname, $profile, $settings['enable']);
            if (file_put_contents($path, $configuration, LOCK_EX) !== strlen($configuration)) {
                throw new BackupFailure('Could not stage the running configuration.');
            }
            return;
        }
        if (isset(Target::CONFIG_FILES[$profile])) {
            self::captureDeviceFile($ssh, $profile, $path);
            return;
        }
        if (!in_array($profile, Target::SERVERS, true)) {
            throw new BackupFailure('Unsupported backup profile.');
        }

        self::remoteExport($ssh, self::archiveCommand($profile), $path);
        self::validateArchive($path, $profile);
    }

    /**
     * Get running configuration of a switch.
     *
     * @param SSH2 $ssh
     * @param string $hostname
     * @param string $profile
     * @param string|null $enable
     * @return string
     * @throws BackupFailure
     *
     * @since 0.0.1
     */
    private static function switchConfig(SSH2 $ssh, string $hostname, string $profile, #[\SensitiveParameter] ?string $enable): string {
        $stem = match ($profile) {
            'edgeswitch' => '\([^()\r\n]+\)\h*',
            'timos' => '\\*?[AB]:' . preg_quote($hostname, '~'),
            default => preg_quote($hostname, '~'),
        };
        $ssh->setWindowSize(200, 24);
        $ssh->setTimeout(15);
        $ssh->openShell();
        $screen = self::readPrompt($ssh, "~^{$stem}[>#]\\h*\\z~m");
        if ($profile === 'edgeswitch') {
            preg_match('~^(\([^()\r\n]+\))\h*[>#]\h*\z~m', $screen, $matches);
            $stem = preg_quote($matches[1], '~') . '\h*';
        }
        $prompt = "~^$stem#\\h*\\z~m";
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
        $text = self::shellOutput($ssh, $command, $prompt);

        return self::validateConfig($profile, $hostname, $text) . "\n";
    }

    /**
     * Get the output of a shell command on the device.
     *
     * @param SSH2 $ssh
     * @param string $command
     * @param string $prompt
     * @return string
     * @throws BackupFailure
     *
     * @since 0.0.1
     */
    private static function shellOutput(SSH2 $ssh, string $command, string $prompt): string {
        $ssh->write($command . "\n");
        $text = trim(preg_replace($prompt, '', self::readPrompt($ssh, $prompt, 180)));

        return str_starts_with($text, $command . "\n") ? ltrim(substr($text, strlen($command))) : $text;
    }

    /**
     * Read the device CLI prompt until it matches the given pattern or times out.
     *
     * @param SSH2 $ssh
     * @param string $pattern
     * @param int $seconds
     * @return string
     * @throws BackupFailure
     *
     * @since 0.0.1
     */
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

    /**
     * Validate the captured device configuration.
     *
     * @param string $profile
     * @param string $hostname
     * @param string $text
     * @return string
     * @throws BackupFailure
     *
     * @since 0.0.1
     */
    private static function validateConfig(string $profile, string $hostname, string $text): string {
        if (strlen($text) < ($profile === 'edgeswitch' ? 20 : 1000) || preg_match(self::CLI_ERRORS, $text)) {
            throw new BackupFailure('Configuration was too short or contained CLI errors or pagination.');
        }

        $hasInterfaces = preg_match('/^\h*interface\h+/m', $text);
        $hasHostname = preg_match('/^hostname\h+' . preg_quote($hostname, '/') . '\h*$/m', $text);
        $complete = match ($profile) {
            'timos' => str_contains($text, '# TiMOS') && str_contains($text, '# Finished'),
            'ios' => $hasInterfaces && $hasHostname && preg_match('/^end\h*\z/m', $text),
            'nxos' => $hasInterfaces && $hasHostname,
            'asa' => $hasInterfaces && preg_match('/^: end\h*$/m', $text),
            'edgeswitch' => preg_match('/^!?\h*Current Configuration:\h*$/mi', $text)
                && preg_match('/^\h*(?:configure|vlan database|interface\h+.+)\h*$/m', $text),
            default => $hasInterfaces,
        };
        if (!$complete) {
            throw new BackupFailure('Expected configuration markers or hostname were missing.');
        }

        return $text;
    }

    /**
     * Capture the configuration file from the device.
     *
     * @param SSH2 $ssh
     * @param string $profile
     * @param string $path
     * @throws BackupFailure
     *
     * @since 0.0.1
     */
    private static function captureDeviceFile(SSH2 $ssh, string $profile, string $path): void {
        $command = 'cat ' . Target::CONFIG_FILES[$profile];
        if ($profile === 'airfiber') {
            $prompt = '/^AF[\w.-]+#\h*\z/im';
            $ssh->setTimeout(15);
            $ssh->openShell();
            self::readPrompt($ssh, $prompt);
            $text = self::shellOutput($ssh, $command, $prompt) . "\n";
            if (file_put_contents($path, $text, LOCK_EX) !== strlen($text)) {
                throw new BackupFailure('Could not stage the airFiber configuration.');
            }
        } else {
            self::remoteExport($ssh, $command, $path, self::OUTPUT_LIMIT);
            $text = file_get_contents($path);
        }

        if (!is_string($text) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text) || preg_match(self::CLI_ERRORS, $text)) {
            throw new BackupFailure('The device did not return a readable configuration file.');
        }

        $valid = $profile === 'edgeos'
            ? preg_match('/^(?:system|interfaces)\h*\{\h*\r?$/m', $text) && preg_match('/^\}\h*\r?$/m', $text)
            : preg_match_all('/^(?:system|netconf|wireless|radio|bridge|users|aaa|httpd|ssh)\.[\w.-]+=.*$/m', $text) >= 2;
        if (!$valid) {
            throw new BackupFailure('The device configuration file was empty or did not have the expected format.');
        }
    }

    /**
     * Generate the archive command for the given profile.
     *
     * @param string $profile
     * @return string
     *
     * @since 0.0.1
     */
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
            array_push(
                $optional,
                'etc/named*',
                'etc/rndc.conf',
                'etc/rndc.key',
                'etc/sysconfig/named',
                'etc/logrotate.d/named',
                'etc/systemd/system/named.service',
                'etc/systemd/system/named.service.d',
                'usr/lib/systemd/system/named.service'
            );
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

    /**
     * Execute the remote export command and save the output to a local file.
     *
     * @param SSH2 $ssh
     * @param string $command
     * @param string $path
     * @param int $limit
     * @throws BackupFailure
     *
     * @since 0.0.1
     */
    private static function remoteExport(SSH2 $ssh, string $command, string $path, int $limit = self::EXPORT_LIMIT): void {
        $file = fopen($path, 'wb');
        if (!$file) {
            throw new BackupFailure('Could not stage the remote export.');
        }

        $bytes = 0;
        $deadline = hrtime(true) + 240_000_000_000;
        try {
            $ssh->enableQuietMode();
            $ssh->setTimeout(20);
            $ssh->exec($command, static function (string $chunk) use ($file, &$bytes, $deadline, $limit): void {
                $bytes += strlen($chunk);
                if ($bytes > $limit || hrtime(true) > $deadline) {
                    throw new BackupFailure('Remote export exceeded its size or time limit.');
                }
                if (fwrite($file, $chunk) !== strlen($chunk)) {
                    throw new BackupFailure('Could not write the remote export.');
                }
            });

            if (!$bytes || $ssh->isTimeout() || $ssh->getExitStatus() !== 0) {
                throw new BackupFailure('Remote export failed, was empty, or timed out.');
            }
        } finally {
            fclose($file);
        }
    }

    /**
     * Validate the integrity and contents of the archive.
     *
     * @param string $path
     * @param string $profile
     * @throws BackupFailure
     *
     * @since 0.0.1
     */
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
