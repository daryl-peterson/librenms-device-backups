@extends('layouts.librenmsv1')


@section('content')
    <div id="device-backups-flash-messages" style="margin-top:-12px; padding-bottom: 1em;">
        @includeIf("$plugin::layouts.flash-messages")
    </div>
    <div class="container-fluid">

        <div class="col-sm-12 col-lg-6">
            <div class="panel panel-default">
                <div class="panel-heading">
                    @includeIf("$plugin::partials.breadcrumb")
                </div>
                <div class="panel-body">
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
                                <label for="sftpuser">SFTP User</label>
                                <input type="text" class="form-control" id="sftpuser" name="sftpuser"
                                    value="{{ old('sftpuser', $info['settings']['sftpuser'] ?? '') }}" placeholder="sftp_user">
                            </div>

                            <div class="form-group">
                                <label for="sftppassword">SFTP Password</label>
                                <input type="password" class="form-control" id="sftppassword" name="sftppassword"
                                    autocomplete="new-password" placeholder="Leave blank to keep the current password">
                            </div>
                        </fieldset>

                        <button type="submit" class="btn btn-primary pull-right" style="margin-left: 10px;">Save Settings</button>

                        <button id="device-backups-dbtest" type="submit" class="btn btn-primary pull-right"
                            formaction="{{ route("$plugin.test-connection") }}">Test DB Connection</button>

                        <button id="device-backups-dbtest" type="submit" class="btn btn-primary pull-right"
                            formaction="{{ route("$plugin.test-connection") }}">Test DB Connection</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
@push('scripts')
    <script>
        document.getElementById('device-backups-settings')?.addEventListener('submit', async (event) => {
            const messages = document.getElementById('device-backups-flash-messages');
            const button = event.submitter;
            if (button?.id !== 'device-backups-dbtest' || !messages) {
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
                result.textContent = 'Testing database connection…';

                const response = await fetch(button.formAction, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json'
                    },
                    body: new FormData(event.currentTarget),
                    signal: controller.signal,
                });
                const data = await response.json();
                result.className = response.ok ? 'alert alert-success' : 'alert alert-danger';
                result.textContent = data.message ?? 'Could not run the database test. Try again.';
            } catch {
                result.className = 'alert alert-danger';
                result.textContent = controller.signal.aborted ?
                    'The database test timed out. Try again.' :
                    'Could not run the database test. Try again.';
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
