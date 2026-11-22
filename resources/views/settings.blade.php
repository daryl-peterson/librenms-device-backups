@extends('layouts.librenmsv1')


@section('content')
    <div id="device-backups-flash-messages" style="margin-top:-12px; padding-bottom: 1em;">
        @includeIf("$plugin::layouts.flash-messages")
    </div>
    @component('device-backups::layouts.panel')
        @includeIf("$plugin::partials.author")

        @unless ($databaseReady)
            <p class="alert alert-info" role="status">
                {{ $databaseSaved
                    ? 'Database settings are saved. Run migrations to enable backups.'
                    : 'Test and save the backup database settings before running migrations.' }}
            </p>
        @endunless

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

            <div class="form-group">
                <label for="backup_report_email">Backup Report Email</label>
                <input type="email" class="form-control" id="backup_report_email" name="backup_report_email"
                    value="{{ old('backup_report_email', $info['settings']['backup_report_email'] ?? '') }}"
                    aria-describedby="backup-report-email-help">
                <p class="help-block" id="backup-report-email-help">Receives one summary after each scheduled backup run. Leave blank to disable reports.</p>
            </div>

            <button type="submit" class="btn btn-primary pull-right">Test and Save Settings</button>
            <button id="device-backups-dbtest" type="submit" class="btn btn-primary pull-right"
                style="margin-right: 10px" data-test-progress="Testing database connection…"
                data-test-fields="_token database host port username password" formnovalidate
                formaction="{{ route("$plugin.test-connection") }}">Test DB</button>
            <button type="submit" class="btn btn-default pull-right"
                style="margin-right: 10px" data-test-progress="Sending test email…"
                data-test-fields="_token backup_report_email" formnovalidate
                formaction="{{ route("$plugin.test-email") }}">Send Test Email</button>
        </form>

        <form method="POST" action="{{ route("$plugin.save-settings") }}">
            @csrf
            <button type="submit" name="database_action" value="migrate" class="btn btn-default"
                @disabled(!$databaseSaved)>Run Migrations</button>
        </form>
    @endcomponent
@endsection
@push('scripts')
    <script>
        document.getElementById('device-backups-settings')?.addEventListener('submit', async (event) => {
            const messages = document.getElementById('device-backups-flash-messages');
            const button = event.submitter;
            const progress = button?.dataset.testProgress;
            if (!progress || !messages) {
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
                result.textContent = progress;

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
                result.textContent = data.message ?? 'Could not complete the test.';
            } catch {
                result.className = 'alert alert-danger';
                result.textContent = controller.signal.aborted ?
                    'The test request timed out.' :
                    'Could not complete the test.';
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
