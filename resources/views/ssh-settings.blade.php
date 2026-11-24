@extends('layouts.librenmsv1')

@section('content')
    @component($plugin->name . '::layouts.panel', ['plugin' => $plugin])
        <h3>SSH Settings <small>{{ $device->hostname }}</small></h3>
        @includeIf($plugin->name . '::layouts.flash-messages')
        @if ($errors->any())
            <ul class="text-danger" role="alert">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif
        @if ($unreadable)
            <p class="alert alert-warning">The saved SSH settings could not be read. Re-enter the credentials to update them.</p>
        @endif

        <p>
            @include('device-backups::partials.ssh-state')
            @if ($check)
                <span class="text-muted">{{ \DRP\DeviceBackups\Helper::localTime($check['checked_at']) }}</span>
            @endif
        </p>

        <form id="backup-device-ssh" method="POST" action="{{ route('device-backups.backups.ssh.update', ['device' => $device->getKey()]) }}">
            @csrf

            <div class="form-group">
                <label for="ssh-profile">Backup Profile</label>
                <select id="ssh-profile" name="profile" class="form-control" required>
                    <option value="">Select a profile</option>
                    @foreach (['Switches and firewalls' => $switches, 'Routers and radios' => $fileProfiles, 'Servers' => $servers] as $group => $profiles)
                        <optgroup label="{{ $group }}">
                            @foreach ($profiles as $option)
                                <option value="{{ $option }}" @selected(old('profile', $profile) === $option)>{{ $option }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>

            <div class="form-group">
                <label for="ssh-user">SSH Username</label>
                <input id="ssh-user" name="ssh_user" class="form-control" value="{{ old('ssh_user', $user) }}" required>
            </div>

            <div class="form-group">
                <label for="ssh-password">SSH Password</label>
                <input type="password" id="ssh-password" name="ssh_password" class="form-control" autocomplete="new-password"
                    @if ($hasPassword) placeholder="Leave blank to keep the current password" @else required @endif>
            </div>

            <div class="form-group">
                <label for="ssh-port">SSH Port</label>
                <input type="number" id="ssh-port" name="ssh_port" class="form-control" min="1" max="65535"
                    value="{{ old('ssh_port', $port) }}" required>
            </div>

            <div class="form-group">
                <label for="ssh-fingerprint">Verified Host Key Fingerprint</label>
                <div class="input-group">
                    <input id="ssh-fingerprint" name="fingerprint" class="form-control" placeholder="SHA256:…"
                        value="{{ old('fingerprint', $fingerprint) }}" required>
                    <span class="input-group-btn">
                        <button type="button" class="btn btn-default" id="read-device-host-key"
                            data-url="{{ route('device-backups.backups.ssh.fingerprint', ['device' => $device->getKey()]) }}">Read Host
                            Key</button>
                    </span>
                </div>
                <p id="device-host-key-result" class="help-block" role="status" hidden></p>
            </div>

            <fieldset class="form-group">
                <legend>Switch CLI Settings</legend>
                <label for="ssh-prompt">CLI Hostname</label>
                <input class="form-control" id="ssh-prompt" name="prompt_name" value="{{ old('prompt_name', $promptName) }}">
                <span class="help-block">EdgeSwitch detects the CLI hostname automatically.</span>

                <label for="ssh-enable">Enable Password</label>
                <input type="password" class="form-control" id="ssh-enable" name="enable_password" autocomplete="new-password"
                    @if ($hasEnablePassword) placeholder="Leave blank to keep the current password" @endif>
                @if ($hasEnablePassword)
                    <div class="checkbox">
                        <label><input type="checkbox" name="clear_enable" value="1" @checked(old('clear_enable'))> Clear the saved
                            enable password</label>
                    </div>
                @endif
            </fieldset>

            <button class="btn btn-primary" type="submit">Save SSH Settings</button>
            <a class="btn btn-default" href="{{ route('device-backups.backups.ssh.index') }}">Back to SSH Status</a>
        </form>

        @if ($enrolled)
            <hr>
            <form method="POST" class="form-inline tw:inline-block"
                action="{{ route('device-backups.backups.ssh.check', ['device' => $device->getKey()]) }}">
                @csrf
                <button type="submit" class="btn btn-default">Test Saved SSH Settings</button>
            </form>
            <form method="POST" class="form-inline tw:inline-block"
                action="{{ route('device-backups.backups.ssh.remove', ['device' => $device->getKey()]) }}"
                onsubmit="return confirm('Remove the saved SSH credentials and stop backing up this device?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-default">Remove Enrollment</button>
            </form>
        @endif
    @endcomponent
@endsection

@push('scripts')
    <script>
        document.getElementById('read-device-host-key')?.addEventListener('click', async (event) => {
            const button = event.currentTarget;
            const form = button.form;
            const port = form.elements.ssh_port;
            if (!port.reportValidity()) {
                return;
            }

            const result = document.getElementById('device-host-key-result');
            result.hidden = false;
            result.textContent = 'Reading the device host key…';
            const timeout = new AbortController();
            const timer = setTimeout(() => timeout.abort(), 12000);
            button.disabled = true;
            try {
                const response = await fetch(button.dataset.url, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json'
                    },
                    signal: timeout.signal,
                    body: new URLSearchParams({
                        _token: form.elements._token.value,
                        port: port.value
                    }),
                });
                const data = await response.json();
                result.textContent = response.ok ?
                    `The device presented ${data.fingerprint}. Verify it with the device console or administrator before entering it above.` :
                    (data.message ?? 'Could not read the device host key.');
            } catch {
                result.textContent = 'Could not read the device host key. Try again.';
            } finally {
                clearTimeout(timer);
                button.disabled = false;
            }
        });
    </script>
@endpush
