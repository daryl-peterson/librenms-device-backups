<?php

namespace DRP\DeviceBackups\Controllers;


use PDOException;
use Throwable;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use LibreNMS\Util\Mail;

use DRP\DeviceBackups\Log;
use DRP\DeviceBackups\TraitHidePrivates;
use DRP\DeviceBackups\PluginData;
use DRP\DeviceBackups\PluginDb;
use DRP\DeviceBackups\PluginSettings;
use DRP\DeviceBackups\TraitValidateAdmin;

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

    private PluginData $plugin;

    /**
     * Constructor.
     *
     * @since 0.0.1
     */
    public function __construct() {
        $this->plugin = PluginData::getInstance();
    }

    /**
     * Plugin main page.
     *
     * @return View
     * @since 0.0.1
     */
    public function index(): View {
        return view("{$this->plugin->name}::page");
    }

    /**
     * Settings page.
     *
     * @return View
     * @since 0.0.1
     */
    public function settings(): View {
        $this->validateAdmin();

        $database = PluginSettings::databaseSettings($this->plugin->settings);
        unset($database['password']);

        $plugin = PluginData::getInstance();
        Log::error('PLUGIN', $plugin);

        return view("{$this->plugin->name}::settings", [
            'plugin' => $this->plugin,
            'database' => $database,
            'databaseSaved' => PluginSettings::hasSavedDatabase($this->plugin->settings),
            'databaseReady' => PluginDb::isReady($this->plugin->settings),
        ]);
    }

    /**
     * Test database connection.
     *
     * @param Request $request
     * @return JsonResponse|RedirectResponse
     * @since 0.0.1
     */
    public function testConnection(Request $request): JsonResponse|RedirectResponse {
        return $this->handleSettings($request, false);
    }

    /**
     * Test report email.
     *
     * @param Request $request
     * @return JsonResponse|RedirectResponse
     * @since 0.0.1
     */
    public function testReportEmail(Request $request): JsonResponse|RedirectResponse {
        $this->validateAdmin();

        $validator = Validator::make($request->only('backup_report_email'), [
            'backup_report_email' => ['required', 'email', 'max:254'],
        ], attributes: ['backup_report_email' => 'Backup Report Email']);
        if ($validator->fails()) {
            return $this->settingsResponse($request, false, 'error', $validator->errors()->first());
        }

        $recipient = $validator->validated()['backup_report_email'];
        try {
            $sent = Mail::send(
                $recipient,
                'Device Backups test email',
                'This is a test of the LibreNMS mail settings used for scheduled Device Backups reports.',
                false,
                false,
                false
            );
        } catch (Throwable) {
            $sent = false;
        }

        return $sent
            ? $this->settingsResponse($request, false, 'success', "Test email sent to $recipient.")
            : $this->settingsResponse($request, false, 'error', 'Could not send the test email. Check the LibreNMS mail settings.');
    }

    /**
     * Save plugin settings.
     *
     * @param Request $request
     * @return RedirectResponse
     * @since 0.0.1
     */
    public function saveSettings(Request $request): RedirectResponse {
        if ($request->input('database_action') === 'migrate') {
            return $this->migrateDatabase($request);
        }

        return $this->handleSettings($request, true);
    }

    /**
     * Handle plugin settings.
     *
     * @param Request $request
     * @param bool $save
     * @return JsonResponse|RedirectResponse
     * @since 0.0.1
     */
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
            $rules['backup_report_email'] = ['nullable', 'email', 'max:254'];
            $rules['backups_per_device'] = ['sometimes', 'required', 'integer', 'min:0'];
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->settingsResponse($request, $save, 'error', $validator->errors()->first());
        }

        $validated = $validator->validated();
        $database = array_intersect_key($validated, array_flip(['database', 'host', 'port', 'username', 'password']));
        $database['password'] ??= PluginSettings::databaseSettings($this->plugin->settings)['password'];

        try {
            $this->verifyDatabaseConnection($database);
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

    /**
     * Run database migrations for the plugin.
     *
     * @param Request $request
     * @return RedirectResponse
     * @since 0.0.1
     */
    private function migrateDatabase(Request $request): RedirectResponse {
        $this->validateAdmin();
        $settings = new PluginSettings();
        $saved = $settings->all();
        if (!PluginSettings::hasSavedDatabase($saved)) {
            return $this->settingsResponse($request, true, 'error', 'Test and save the database settings before running migrations.');
        }

        try {
            $database = PluginSettings::databaseSettings($saved);
            $this->verifyDatabaseConnection($database);
        } catch (DecryptException) {
            return $this->settingsResponse($request, true, 'error', 'The saved database password could not be read. Enter it again and save the settings.');
        } catch (PDOException $e) {
            return $this->settingsResponse($request, true, 'error', $this->databaseConnectionError($e));
        }

        try {
            if (!PluginDb::migrateSettings($database)) {
                return $this->settingsResponse(
                    $request,
                    true,
                    'error',
                    'Migrations did not complete. Try again after any running migrations finish.'
                );
            }

            if (!$settings->markMigrationsComplete($saved['database_connection'], PluginDb::migrationVersion())) {
                return $this->settingsResponse(
                    $request,
                    true,
                    'error',
                    'Could not confirm migrations for the saved settings. Reload Settings and run migrations again.'
                );
            }
        } catch (Throwable $e) {
            $code = $e instanceof PDOException ? ' (database error ' . ($e->errorInfo[1] ?? $e->getCode()) . ')' : '';
            Log::error('Device Backups migrations did not complete' . $code . '.');
            return $this->settingsResponse(
                $request,
                true,
                'error',
                'Could not complete database setup. Check the backup database user’s schema permissions and the LibreNMS log.'
            );
        }

        PluginDb::clearConnectionChecks();

        return $this->settingsResponse($request, true, 'success', 'Migrations are up to date. Backup functions are enabled.');
    }

    /**
     * Get a human-readable database connection error message.
     *
     * @param PDOException $e
     * @return string
     * @since 0.0.1
     */
    private function databaseConnectionError(PDOException $e): string {
        return match ((int) ($e->errorInfo[1] ?? $e->getCode())) {
            1045, 1698 => 'The database rejected the login. Check the username, password, and account authentication method.',
            1044 => 'The database user does not have access to the selected database.',
            1049 => 'The selected database does not exist.',
            2002, 2003, 2005 => 'Could not reach the database server. Check the host, port, and network access.',
            default => 'Could not connect to the backup database with the supplied settings.',
        };
    }

    protected function verifyDatabaseConnection(#[\SensitiveParameter] array $database): void {
        PluginDb::testConnection($database);
    }

    /**
     * Generate a response for plugin settings actions.
     *
     * @param Request $request
     * @param bool $save
     * @param string $status
     * @param string $message
     * @return JsonResponse|RedirectResponse
     * @since 0.0.1
     */
    private function settingsResponse(Request $request, bool $save, string $status, string $message): JsonResponse|RedirectResponse {
        $plugin = $this->plugin->name;
        if (!$save && $request->expectsJson()) {
            return response()->json(['message' => $message], $status === 'success' ? 200 : 422);
        }

        $redirect = redirect()->route("$plugin.settings")->with($status, $message);
        if (!$save || $status === 'error') {
            $fields = ['database', 'host', 'port', 'username', 'backup_report_email', 'backups_per_device'];
            $redirect->withInput(array_filter($request->only($fields), 'is_scalar'));
        }

        return $redirect;
    }
}
