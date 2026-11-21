<?php

/**
 * LibreNMS Device Backups Plugin Controller
 *
 * @package     librenms-device-backups
 * @author      Daryl Peterson <@gmail.com>
 * @copyright   Copyright (c) 2026, Daryl Peterson
 * @license     https://opensource.org MIT License
 * @link        https://github.com/daryl-peterson/
 * @since       0.0.1
 */

namespace DRP\DeviceBackups\Controllers;

/**
 * Laravel imports.
 */

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Plugin imports.
 */

use DRP\DeviceBackups\Backup\BackupFailure;
use DRP\DeviceBackups\Backup\SshConnection;
use DRP\DeviceBackups\Log;
use DRP\DeviceBackups\TraitHidePrivates;
use DRP\DeviceBackups\PluginData;
use DRP\DeviceBackups\PluginDb;
use DRP\DeviceBackups\PluginSettings;
use PDOException;
use phpseclib4\Exception\BaseException as SftpException;
use phpseclib4\Net\SFTP;
use DRP\DeviceBackups\TraitValidateAdmin;
use Throwable;
use const DRP\DeviceBackups\PLUGIN_NAME;

/**
 * LibreNMS Device Backups Plugin Controller
 *
 * @package     librenms-device-backups
 * @author      Daryl Peterson <@gmail.com>
 * @copyright   Copyright (c) 2026, Daryl Peterson
 * @license     https://opensource.org MIT License
 * @link        https://github.com/daryl-peterson/
 * @since       0.0.1
 */
class PluginController extends Controller {
    use TraitHidePrivates;
    use TraitValidateAdmin;

    private array $info;
    private string $plugin;

    /**
     * Constructor.
     *
     * @since 0.0.1
     */
    public function __construct() {
        $this->info = PluginData::getInfo();
        $this->plugin = PLUGIN_NAME;
    }


    public function index(): View {

        return view("$this->plugin::page");
    }


    /**
     * Settings page.
     *
     * @return View
     * @since 0.0.1
     */
    public function settings(): View {
        $this->validateAdmin();

        $database = PluginSettings::databaseSettings($this->info['settings']);
        unset($database['password']);

        return view("$this->plugin::settings", [
            'info' => $this->info,
            'plugin' => $this->plugin,
            'database' => $database,
            'databaseSaved' => PluginSettings::hasSavedDatabase($this->info['settings']),
            'databaseReady' => PluginDb::isReady($this->info['settings']),
        ]);
    }

    public function testConnection(Request $request): JsonResponse|RedirectResponse {
        return $this->handleSettings($request, false);
    }

    public function testSftp(Request $request): JsonResponse|RedirectResponse {
        $this->validateAdmin();

        $validator = Validator::make($request->all(), $this->sftpRules(true), [], [
            'sftpserver' => 'SFTP server',
            'sftpport' => 'SFTP port',
            'sftpuser' => 'SFTP user',
            'sftppassword' => 'SFTP password',
            'sftpfingerprint' => 'SFTP host key fingerprint',
        ]);
        if ($validator->fails()) {
            return $this->settingsResponse($request, false, 'error', $validator->errors()->first());
        }

        $input = $validator->validated();
        try {
            $sftp = new SFTP(SshConnection::host($input['sftpserver']), (int) ($input['sftpport'] ?? 22), 5);
            $fingerprint = SshConnection::fingerprint($sftp);
            $expected = $input['sftpfingerprint'] ?? null;
            if ($expected === null) {
                return $this->settingsResponse($request, false, 'error',
                    "The server presented $fingerprint. Verify it with your SFTP administrator, then enter it in Host Key Fingerprint and test again.");
            }
            if (!hash_equals($expected, $fingerprint)) {
                return $this->settingsResponse($request, false, 'error', 'The SFTP host key does not match the entered fingerprint.');
            }

            $password = $input['sftppassword'] ?? PluginSettings::sftpPassword(PluginData::getSettings());
            if ($password === null || $password === '') {
                return $this->settingsResponse($request, false, 'error', 'Enter the SFTP password.');
            }
            if (!$sftp->login($input['sftpuser'], $password)) {
                return $this->settingsResponse($request, false, 'error', 'The SFTP server rejected the username or password.');
            }

            $sftp->pwd();

            return $this->settingsResponse($request, false, 'success', 'Connected to the SFTP server successfully.');
        } catch (DecryptException) {
            return $this->settingsResponse($request, false, 'error', 'The saved SFTP password could not be read. Enter the password and test again.');
        } catch (BackupFailure $e) {
            return $this->settingsResponse($request, false, 'error', $e->getMessage());
        } catch (SftpException) {
            return $this->settingsResponse($request, false, 'error', 'Could not complete the SFTP connection. Check the server, port, and SFTP access.');
        }
    }

    private function sftpRules(bool $testing): array {
        $presence = $testing ? 'required' : 'nullable';

        return [
            'sftpserver' => [$presence, 'string', 'max:255', 'regex:/\A[a-zA-Z0-9_.:-]+\z/'],
            'sftpport' => ['nullable', 'integer', 'between:1,65535'],
            'sftpuser' => [$presence, 'string', 'max:255'],
            'sftppassword' => ['nullable', 'string'],
            'sftpfingerprint' => ['nullable', 'string', 'regex:/\ASHA256:[a-zA-Z0-9+\/]{43}\z/'],
        ];
    }

    public function saveSettings(Request $request): RedirectResponse {
        if ($request->input('database_action') === 'migrate') {
            return $this->migrateDatabase($request);
        }

        return $this->handleSettings($request, true);
    }

    private function handleSettings(Request $request, bool $save): JsonResponse|RedirectResponse {
        $this->validateAdmin();

        $rules = [
            'host' => ['required', 'string', 'max:255', 'regex:/\A[a-zA-Z0-9_.:-]+\z/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:64', 'not_regex:/[;\x00-\x1f\x7f]/'],
            'username' => ['required', 'string'],
            'password' => ['nullable', 'string'],
        ];
        if ($save) {
            $rules += $this->sftpRules(false) + ['backup_report_email' => ['nullable', 'email', 'max:254']];
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->settingsResponse($request, $save, 'error', $validator->errors()->first());
        }

        $validated = $validator->validated();
        $database = array_intersect_key($validated, array_flip(['database', 'host', 'port', 'username', 'password']));
        $database['password'] ??= PluginSettings::databaseSettings($this->info['settings'])['password'];

        try {
            PluginDb::testConnection($database);
        } catch (PDOException $e) {
            return $this->settingsResponse($request, $save, 'error', $this->databaseConnectionError($e));
        }

        if (!$save) {
            return $this->settingsResponse($request, false, 'success', 'Connected to the backup database successfully.');
        }

        $formSettings = array_diff_key($validated, $database);
        try {
            $saved = (new PluginSettings())->saveConnections($database, $formSettings);
        } catch (QueryException) {
            $saved = false;
        }

        if (!$saved) {
            return $this->settingsResponse($request, true, 'error', 'Could not save the Device Backups settings.');
        }

        PluginDb::clearConnectionChecks();

        return $this->settingsResponse($request, true, 'success', 'Database connection verified and settings saved.');
    }

    private function migrateDatabase(Request $request): RedirectResponse {
        $this->validateAdmin();
        $settings = new PluginSettings();
        $saved = $settings->all();
        if (!PluginSettings::hasSavedDatabase($saved)) {
            return $this->settingsResponse($request, true, 'error', 'Test and save the database settings before running migrations.');
        }

        try {
            $database = PluginSettings::databaseSettings($saved);
            PluginDb::testConnection($database);
        } catch (DecryptException) {
            return $this->settingsResponse($request, true, 'error', 'The saved database password could not be read. Enter it again and save the settings.');
        } catch (PDOException $e) {
            return $this->settingsResponse($request, true, 'error', $this->databaseConnectionError($e));
        }

        try {
            if (!PluginDb::migrateSettings($database)) {
                return $this->settingsResponse($request, true, 'error',
                    'Migrations did not complete. Try again after any running migrations finish.');
            }

            if (!$settings->markMigrationsComplete($saved['database_connection'], PluginDb::migrationVersion())) {
                return $this->settingsResponse($request, true, 'error',
                    'Could not confirm migrations for the saved settings. Reload Settings and run migrations again.');
            }
        } catch (Throwable $e) {
            $code = $e instanceof PDOException ? ' (database error ' . ($e->errorInfo[1] ?? $e->getCode()) . ')' : '';
            Log::error('Device Backups migrations did not complete' . $code . '.');
            return $this->settingsResponse($request, true, 'error',
                'Could not complete database setup. Check the backup database user’s schema permissions and the LibreNMS log.');
        }

        PluginDb::clearConnectionChecks();

        return $this->settingsResponse($request, true, 'success', 'Migrations are up to date. Backup functions are enabled.');
    }

    private function databaseConnectionError(PDOException $e): string {
        return match ((int) ($e->errorInfo[1] ?? $e->getCode())) {
            1045, 1698 => 'The database rejected the login. Check the username, password, and account authentication method.',
            1044 => 'The database user does not have access to the selected database.',
            1049 => 'The selected database does not exist.',
            2002, 2003, 2005 => 'Could not reach the database server. Check the host, port, and network access.',
            default => 'Could not connect to the backup database with the supplied settings.',
        };
    }

    private function settingsResponse(Request $request, bool $save, string $status, string $message): JsonResponse|RedirectResponse {
        if (!$save && $request->expectsJson()) {
            return response()->json(['message' => $message], $status === 'success' ? 200 : 422);
        }

        $redirect = redirect()->route("$this->plugin.settings")->with($status, $message);
        if (!$save || $status === 'error') {
            $fields = ['database', 'host', 'port', 'username', 'sftpserver', 'sftpport', 'sftpuser', 'sftpfingerprint', 'backup_report_email'];
            $redirect->withInput(array_filter($request->only($fields), 'is_scalar'));
        }

        return $redirect;
    }
}
