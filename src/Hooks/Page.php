<?php

namespace DRP\DeviceBackups\Hooks;

use App\Plugins\Hooks\PageHook;
use DRP\DeviceBackups\PluginData;
use DRP\DeviceBackups\Log;

/**
 * LibreNMS Device Backups Page.
 *
 * @package     librenms-device-backups
 * @author      Daryl Peterson <@gmail.com>
 * @copyright   Copyright (c) 2026, Daryl Peterson
 * @license     https://opensource.org MIT License
 * @link        https://github.com/daryl-peterson/
 * @since       0.0.1
 */
class Page extends PageHook {

    /**
     * Get page data.
     *
     * @return array
     * @version 0.0.1
     */
    public function data(): array {

        $return = PluginData::getData();
        Log::debug('Page data', $return);
        return $return;
    }
}
