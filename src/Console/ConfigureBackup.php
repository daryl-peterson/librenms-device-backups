<?php

namespace DRP\DeviceBackups\Console;

use App\Models\Device;
use DRP\DeviceBackups\Backup\SshConnection;
use DRP\DeviceBackups\Backup\Target;
use Illuminate\Console\Command;

final class ConfigureBackup extends Command {
    protected $signature = 'device-backups:configure
        {device : LibreNMS device ID}
        {--profile= : Backup profile, defaults to the detected LibreNMS OS when supported}
        {--disable : Remove this device from backup runs}';

    protected $description = 'Enroll a LibreNMS device for SSH backups';

    public function handle(): int {
        $id = filter_var($this->argument('device'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $device = $id ? Device::find($id) : null;
        if (!$device) {
            $this->error('Enter a valid LibreNMS device ID.');
            return self::FAILURE;
        }

        if ($this->option('disable')) {
            if (!Target::remove($device)) {
                $this->error('Could not remove the backup settings.');
                return self::FAILURE;
            }

            $this->info('Device removed from backup runs.');
            return self::SUCCESS;
        }

        $profiles = Target::profiles();
        $profile = $this->option('profile') ?: Target::profile($device);
        if (!in_array($profile, $profiles, true)) {
            $this->error('Choose a supported --profile: ' . implode(', ', $profiles));
            return self::FAILURE;
        }
        if (!$this->input->isInteractive()) {
            $this->error('Run this command interactively to enter SSH credentials.');
            return self::FAILURE;
        }

        $user = trim((string) $this->ask('SSH username'));
        $port = filter_var($this->ask('SSH port', '22'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $fingerprint = trim((string) $this->ask('Verified SHA256 host key fingerprint'));
        $password = $this->secret('SSH password', false);
        $promptName = '';
        $enable = null;
        if (Target::needsCliHostname($profile)) {
            $promptName = trim((string) $this->ask('Device CLI hostname', Target::promptName($device, [])));
        }
        if (in_array($profile, Target::SWITCHES, true)) {
            $enable = $this->secret('Enable password (press Enter if unused)', false);
        }

        if ($user === '' || !$port || !SshConnection::validFingerprint($fingerprint) || !is_string($password) || $password === ''
            || preg_match('/[\r\n]/', $enable ?? '') || (Target::needsCliHostname($profile) && !preg_match('/\A[a-zA-Z0-9_.-]+\z/', $promptName))) {
            $this->error('Check the SSH credentials, port, host key fingerprint, and CLI hostname.');
            return self::FAILURE;
        }

        if (!Target::save($device, [
            'profile' => $profile, 'user' => $user, 'port' => $port, 'fingerprint' => $fingerprint,
            'password' => $password, 'prompt_name' => $promptName, 'enable' => $enable,
        ])) {
            $this->error('Could not save the device backup settings.');
            return self::FAILURE;
        }

        $this->info("Device $id enrolled with the $profile profile.");
        return self::SUCCESS;
    }
}
