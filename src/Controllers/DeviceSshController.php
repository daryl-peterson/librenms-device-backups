<?php

namespace DRP\DeviceBackups\Controllers;

use App\Models\Device;
use DRP\DeviceBackups\Backup\BackupFailure;
use DRP\DeviceBackups\Backup\DeviceSshStatus;
use DRP\DeviceBackups\Backup\SshConnection;
use DRP\DeviceBackups\Backup\SshFailure;
use DRP\DeviceBackups\Backup\Target;
use DRP\DeviceBackups\PluginData;
use DRP\DeviceBackups\TraitValidateAdmin;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use phpseclib4\Exception\BaseException as SshException;
use phpseclib4\Net\SSH2;
use Throwable;

final class DeviceSshController extends Controller {
    use TraitValidateAdmin;

    public function index(Request $request): View {
        $this->authorizePlugin();
        $search = $this->search($request->query('search'));
        $devices = Target::inventory($search)->with([
            'attribs' => fn ($query) => $query->whereIn('attrib_type', [Target::ATTRIBUTE, DeviceSshStatus::ATTRIBUTE]),
        ])->simplePaginate(25)->withQueryString();

        return view('device-backups::ssh-status', ['devices' => $devices, 'search' => $search]);
    }

    public function edit(Device $device): View {
        $this->authorizePlugin($device);
        $enrolled = Target::revision($device) !== null;
        $settings = $this->saved($device);

        return view('device-backups::ssh-settings', [
            'device' => $device,
            'enrolled' => $enrolled,
            'unreadable' => $enrolled && $settings === [],
            'profile' => $settings['profile'] ?? Target::profile($device),
            'user' => $settings['user'] ?? '',
            'port' => $settings['port'] ?? 22,
            'fingerprint' => $settings['fingerprint'] ?? '',
            'promptName' => Target::promptName($device, $settings),
            'hasPassword' => isset($settings['password']) && $settings['password'] !== '',
            'hasEnablePassword' => !empty($settings['enable']),
            'switches' => Target::SWITCHES,
            'fileProfiles' => array_keys(Target::CONFIG_FILES),
            'servers' => Target::SERVERS,
            'check' => DeviceSshStatus::get($device),
        ]);
    }

    public function update(Request $request, Device $device): RedirectResponse {
        $this->authorizePlugin($device);
        $saved = $this->saved($device);
        $redirect = $this->editRedirect($device);
        $fields = ['profile', 'ssh_user', 'ssh_port', 'fingerprint', 'prompt_name', 'clear_enable'];
        $safeInput = array_filter($request->only($fields), 'is_scalar');

        $profile = $request->input('profile');
        $switch = in_array($profile, Target::SWITCHES, true);
        $needsCliHostname = is_string($profile) && Target::needsCliHostname($profile);
        $validator = Validator::make($request->all(), [
            'profile' => ['required', Rule::in(Target::profiles())],
            'ssh_user' => ['required', 'string', 'max:255'],
            'ssh_port' => ['required', 'integer', 'between:1,65535'],
            'fingerprint' => ['required', 'string', 'regex:/\\ASHA256:[a-zA-Z0-9+\\/]{43}\\z/'],
            'ssh_password' => [isset($saved['password']) && $saved['password'] !== '' ? 'nullable' : 'required', 'string'],
            'prompt_name' => [Rule::excludeIf(!$needsCliHostname), 'required', 'string', 'max:128', 'regex:/\\A[a-zA-Z0-9_.-]+\\z/'],
            'enable_password' => ['nullable', 'string', 'not_regex:/[\\r\\n]/'],
            'clear_enable' => ['nullable', 'boolean'],
        ], [], ['fingerprint' => 'host key fingerprint']);

        if ($validator->fails()) {
            return $redirect->withErrors($validator)->withInput($safeInput);
        }

        $data = $validator->validated();
        try {
            $updated = Target::save($device, [
                'profile' => $data['profile'],
                'user' => $data['ssh_user'],
                'port' => (int) $data['ssh_port'],
                'fingerprint' => $data['fingerprint'],
                'password' => $data['ssh_password'] ?? $saved['password'],
                'prompt_name' => $data['prompt_name'] ?? '',
                'enable' => $switch && !$request->boolean('clear_enable')
                    ? ($data['enable_password'] ?? $saved['enable'] ?? null)
                    : null,
            ]);
        } catch (QueryException) {
            $updated = false;
        }

        return $updated
            ? $redirect->with('success', 'Device SSH settings saved.')
            : $redirect->withInput($safeInput)->with('error', 'Could not save the device SSH settings.');
    }

    public function check(Request $request, Device $device): RedirectResponse {
        $this->authorizePlugin($device);
        $redirect = $request->input('from') === 'status'
            ? redirect()->route('device-backups.backups.ssh.index', array_filter([
                'search' => $this->search($request->input('search')),
                'page' => max(1, (int) filter_var($request->input('page'), FILTER_VALIDATE_INT)),
            ], static fn ($value) => $value !== '' && $value !== 1))
            : $this->editRedirect($device);

        $settings = $this->saved($device);
        if ($settings === []) {
            return $redirect->with('error', 'Save valid device SSH settings before testing.');
        }

        try {
            DeviceSshStatus::connect($device, $settings, 5);

            return $redirect->with('success', "SSH login succeeded for {$device->hostname}.");
        } catch (SshFailure $e) {
            return $redirect->with('error', "{$device->hostname}: {$e->getMessage()}");
        }
    }

    public function fingerprint(Request $request, Device $device): JsonResponse {
        $this->authorizePlugin($device);
        $validator = Validator::make($request->only('port'), ['port' => ['required', 'integer', 'between:1,65535']]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }
        $port = (int) $validator->validated()['port'];

        try {
            $ssh = new SSH2(SshConnection::host($device->hostname), $port, 5);

            return response()->json(['fingerprint' => SshConnection::fingerprint($ssh)]);
        } catch (BackupFailure|SshException) {
            return response()->json(['message' => 'Could not read the host key. Check the device address and SSH port.'], 422);
        }
    }

    public function remove(Device $device): RedirectResponse {
        $this->authorizePlugin($device);
        try {
            $removed = Target::remove($device);
        } catch (QueryException) {
            $removed = false;
        }

        return $this->editRedirect($device)->with(
            $removed ? 'success' : 'error',
            $removed ? 'Device removed from scheduled backups.' : 'Could not remove the device SSH settings.',
        );
    }

    private function authorizePlugin(?Device $device = null): void {
        $this->validateAdmin();
        abort_unless(PluginData::getPluginModel()?->plugin_active, 404);
        if ($device !== null) {
            Gate::authorize('view', $device);
        }
    }

    private function editRedirect(Device $device): RedirectResponse {
        return redirect()->route('device-backups.backups.ssh.edit', ['device' => $device->getKey()]);
    }

    private function saved(Device $device): array {
        if (Target::revision($device) === null) {
            return [];
        }

        try {
            return Target::get($device);
        } catch (Throwable) {
            return [];
        }
    }

    private function search(mixed $value): string {
        return is_string($value) ? mb_substr(trim($value), 0, 128) : '';
    }
}
