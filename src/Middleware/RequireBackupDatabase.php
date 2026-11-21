<?php

namespace DRP\DeviceBackups\Middleware;

use Closure;
use DRP\DeviceBackups\Helper;
use DRP\DeviceBackups\PluginDb;
use DRP\DeviceBackups\PluginSettings;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireBackupDatabase {
    public function handle(Request $request, Closure $next): Response {
        abort_unless(Helper::isAdmin(), 403, 'Unauthorized action.');
        $settings = new PluginSettings();
        abort_unless($settings->plugin?->plugin_active, 404);

        if (!PluginDb::isReady($settings->all())) {
            if ($request->expectsJson()) {
                return response()->json(['message' => PluginDb::SETUP_REQUIRED], 503);
            }

            return redirect()->route('device-backups.settings')->with('error', PluginDb::SETUP_REQUIRED);
        }

        return $next($request);
    }
}
