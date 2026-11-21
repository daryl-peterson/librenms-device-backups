@extends('layouts.librenmsv1')


@section('content')
    <div id="device-backups-flash-messages" style="margin-top:-12px; padding-bottom: 1em;">
        @includeIf("$plugin::layouts.flash-messages")
    </div>
    @component('device-backups::layouts.panel')
        @includeIf("$plugin::partials.author")


        <form id="device-backups-settings" method="POST" action="{{ route("$plugin.save-settings") }}">
            @csrf

            <fieldset>
                <legend style="border: 0px; font-size: 1.2em; font-weight: bold; margin-bottom: .5em;">Database Settings
                </legend>
                <div class="form-group">
                    <label for="database">Database</label>
                    <input type="text" class="form-control" id="database" name="database"
                        value="{{ old('database', $database['database']) }}" placeholder="database_name">
                </div>

                <div class="form-group">
                    <label for="host">Database Host</label>
                    <input type="text" class="form-control" id="host" name="host"
                        value="{{ old('host', $database['host']) }}" placeholder="database_host">
                </div>

                <div class="form-group">
                    <label for="port">Database Port</label>
                    <input type="text" class="form-control" id="port" name="port"
                        value="{{ old('port', $database['port']) }}" placeholder="database_port">
                </div>

                <div class="form-group">
                    <label for="username">Database Username</label>
                    <input type="text" class="form-control" id="username" name="username"
                        value="{{ old('username', $database['username']) }}" placeholder="database_username">
                </div>
                <div class="form-group">
                    <label for="password">Database Password</label>
                    <input type="password" class="form-control" id="password" name="password" autocomplete="new-password"
                        placeholder="Leave blank to keep the current password">
                </div>
            </fieldset>
            <fieldset>
                <legend style="border: 0px; font-size: 1.2em; font-weight: bold; margin-bottom: .5em;">SFTP Settings
                </legend>

                <div class="form-group">
                    <label for="sftpserver">SFTP Server</label>
                    <input type="text" class="form-control" id="sftpserver" name="sftpserver"
                        value="{{ old('sftpserver', $info['settings']['sftpserver'] ?? '') }}" placeholder="sftp_server">
                </div>

                <div class="form-group">
                    <label for="sftpport">SFTP Port</label>
                    <input type="number" class="form-control" id="sftpport" name="sftpport" min="1" max="65535"
                        value="{{ old('sftpport', $info['settings']['sftpport'] ?? 22) }}">
                </div>

                <div class="form-group">
                    <label for="sftpuser">SFTP User</label>
                    <input type="text" class="form-control" id="sftpuser" name="sftpuser"
                        value="{{ old('sftpuser', $info['settings']['sftpuser'] ?? '') }}" placeholder="sftp_user">
                </div>

                <div class="form-group">
                    <label for="sftppassword">SFTP Password</label>
                    <input type="password" class="form-control" id="sftppassword" name="sftppassword"
                        autocomplete="new-password" placeholder="Leave blank to keep the current password">
                </div>
                <div class="form-group">
                    <label for="sftpfingerprint">Host Key Fingerprint</label>
                    <input type="text" class="form-control" id="sftpfingerprint" name="sftpfingerprint"
                        value="{{ old('sftpfingerprint', $info['settings']['sftpfingerprint'] ?? '') }}"
                        placeholder="SHA256:…">
                </div>
            </fieldset>

            <div class="form-group">
                <label for="backup_report_email">Backup Report Email</label>
                <input type="email" class="form-control" id="backup_report_email" name="backup_report_email"
                    value="{{ old('backup_report_email', $info['settings']['backup_report_email'] ?? '') }}">
            </div>

            <button type="submit" class="btn btn-primary pull-right">Test, Migrate and Save</button>
            <button id="device-backups-sftptest" type="submit" class="btn btn-primary pull-right"
                style="margin-right: 10px" data-connection-test="SFTP"
                data-test-fields="_token sftpserver sftpport sftpuser sftppassword sftpfingerprint" formnovalidate
                formaction="{{ route("$plugin.test-sftp") }}">Test SFTP</button>

            <button id="device-backups-dbtest" type="submit" class="btn btn-primary pull-right"
                style="margin-right: 10px" data-connection-test="database"
                data-test-fields="_token database host port username password" formnovalidate
                formaction="{{ route("$plugin.test-connection") }}">Test DB</button>


        </form>
    @endcomponent
@endsection
@push('scripts')
    <script>
        document.getElementById('device-backups-settings')?.addEventListener('submit', async (event) => {
            const messages = document.getElementById('device-backups-flash-messages');
            const button = event.submitter;
            const service = button?.dataset.connectionTest;
            if (!service || !messages) {
                return;
            }

            event.preventDefault();
            if (button.disabled) {
                return;
            }

            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 12000);
            const result = document.createElement('div');
            result.setAttribute('role', 'status');
            button.disabled = true;

            try {
                result.className = 'alert alert-info';
                messages.replaceChildren(result);
                result.textContent = `Testing ${service} connection…`;

                const form = new FormData(event.currentTarget);
                const body = new FormData();
                for (const field of button.dataset.testFields.split(' ')) {
                    body.set(field, form.get(field) ?? '');
                }

                const response = await fetch(button.formAction, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json'
                    },
                    body,
                    signal: controller.signal,
                });
                const data = await response.json();
                result.className = response.ok ? 'alert alert-success' : 'alert alert-danger';
                result.textContent = data.message ?? `Could not run the ${service} test. Try again.`;
            } catch {
                result.className = 'alert alert-danger';
                result.textContent = controller.signal.aborted ?
                    `The ${service} test timed out. Try again.` :
                    `Could not run the ${service} test. Try again.`;
            } finally {
                clearTimeout(timeout);
                button.disabled = false;
                setTimeout(() => result.remove(), 5000);
            }
        });

        $(document).ready(function() {
            $("#device-backups-flash-messages .alert-dismissible").delay(5000).fadeOut(500, function() {
                $(this).alert('close');
            });
        });
    </script>
@endpush
