<?php

/**
 * LibreNMS Device Importer Import Controller
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

use Illuminate\Routing\Controller;
use Illuminate\View\View;

/**
 * Plugin imports.
 */

use DRP\DeviceBackups\TraitHidePrivates;
use DRP\DeviceBackups\PluginData;
use DRP\DeviceBackups\TraitValidateAdmin;
use const DRP\DeviceBackups\PLUGIN_NAME;

/**
 * LibreNMS Device Importer Import Controller
 *
 * @package     librenms-device-backups
 * @author      Daryl Peterson <@gmail.com>
 * @copyright   Copyright (c) 2026, Daryl Peterson
 * @license     https://opensource.org MIT License
 * @link        https://github.com/daryl-peterson/
 * @since       0.0.1
 */
class ImportController extends Controller {
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
     * Import page.
     *
     * @return View
     * @since 0.0.1
     */
    public function import(): View {
        $this->validateAdmin();

        return view("$this->plugin::import", ['info' => $this->info, 'plugin' => $this->plugin]);
    }

    /**
     * Export page.
     *
     * @return View
     * @since 0.0.1
     */
    public function export(): View {
        $this->validateAdmin();

        return view("$this->plugin::export", ['info' => $this->info, 'plugin' => $this->plugin]);
    }

    /**
     * Settings page.
     *
     * @return View
     * @since 0.0.1
     */
    public function settings(): View {
        $this->validateAdmin();

        return view("$this->plugin::settings", ['info' => $this->info, 'plugin' => $this->plugin]);
    }
}
