<?php

declare(strict_types=1);

namespace DRP\DeviceBackups;

use App\Models\Plugin as PluginModel;
use Illuminate\Support\Facades\Route;
use DRP\DeviceBackups\Log;
use DRP\DeviceBackups\PluginSettings;
use DRP\DeviceBackups\PluginCache;

/**
 * LibreNMS Device Backups Plugin.
 *
 * @package     librenms-device-backups
 * @author      Daryl Peterson <daryl.peterson@gmail.com>
 * @copyright   Copyright (c) 2026, Daryl Peterson
 * @license     https://opensource.org MIT License
 * @link        https://github.com/daryl-peterson/
 * @since       0.0.1
 */
class PluginData {
    use TraitHidePrivates;

    private array $data = [];

    private array $dbStatus = [
        'ready' => false,
        'error' => null
    ];



    public function __construct() {

        $name = PLUGIN_NAME;
        $settings = self::getSettings();
        $isReady = PluginDb::isReady($settings);

        $this->data['name'] = $name;
        $this->data['title'] = PLUGIN_TITLE;
        $this->data['author'] = PLUGIN_AUTHOR;
        $this->data['author_image'] = PLUGIN_AUTHOR_IMAGE;
        $this->data['ver'] = PLUGIN_VER;

        $this->data['settings'] = $settings;
        $this->data['isDbReady'] = $isReady;
        $this->data['menu'] = Route::has($name . '.backups.status')
            ? $name . '.backups.status'
            : $name . '.settings';
        $this->data['hasMenuRoute'] = Route::has($this->data['menu']);

        $this->data['coffee_link'] = 'https://www.buymeacoffee.com/daryl.peterson';
        $this->data['coffee_image'] = 'https://cdn.buymeacoffee.com/buttons/default-orange.png';
    }

    /**
     * Get plugin instance.
     *
     * @return self
     * @version 0.0.1
     */
    public static function getInstance(): self {
        return new self();
    }


    /**
     * Get plugin information.
     *
     * @return array{
     *  name: '',
     *  title: '',
     *  author: '',
     *  ver: '',
     *  image: '',
     *  settings: array,
     *  dbStatus: array{
     *    ready: bool,
     *    error: string|null
     *  },
     * }
     *
     * @version 0.0.1
     */
    public static function getInfo() {
        $dbError = PluginCache::get(CACHE_DB_ERROR);
        $obj = self::getInstance();

        $result = array(
            'name'     => $obj->data['name'],
            'title'    => $obj->data['title'],
            'author'   => $obj->data['author'],
            'ver'      => $obj->data['ver'],
            'image'    => $obj->data['author_image'],
            'settings' => $obj->data['settings'],
            'dbStatus'  => [
                'ready' => !$dbError,
                'error' => $dbError
            ],
        );

        return $result;
    }

    public static function getData(): array {
        $obj = self::getInstance();
        $dbError = PluginCache::get(CACHE_DB_ERROR);
        $result = [
            'info' => [
                'name'     => $obj->data['name'],
                'title'    => $obj->data['title'],
                'author'   => $obj->data['author'],
                'ver'      => $obj->data['ver'],
                'image'    => $obj->data['author_image'],
                'dbStatus'  => [
                    'ready' => !$dbError,
                    'error' => $dbError
                ]
            ],
            'dbIsReady' => $obj->data['isDbReady'],
            'settings' => self::getSettings()
        ];
        return $result;
    }

    /**
     * Get plugin object model.
     *
     * @return PluginModel|null
     * @version 0.0.1
     */
    public static function getPluginModel(): PluginModel|null {
        $result = PluginModel::versionTwo()->where('plugin_name', PLUGIN_NAME)->first();

        // Check if the plugin exists in the database.
        if (is_null($result)) {
            Log::error('Plugin not found: ' . PLUGIN_NAME);
            return null;
        }
        return $result;
    }

    /**
     * Get plugin settings.
     *
     * @return array
     * @version 0.0.1
     */
    public static function getSettings(): array {
        $obj = new PluginSettings();
        $settings = $obj->all();
        return $settings ?? [];
    }

    /**
     * Get plugin title.
     *
     * @return string
     * @version 0.0.1
     */
    public static function getPluginTitle(): string {
        return PLUGIN_TITLE;
    }

    /**
     * Get plugin name.
     *
     * @return string
     * @version 0.0.1
     */
    public static function getPluginName(): string {
        return PLUGIN_NAME;
    }

    /**
     * Get plugin name.
     *
     * @return string
     * @version 0.0.1
     */
    public string $name {
        get => $this->data['name'];
    }

    /**
     * Get plugin title.
     *
     * @return string
     * @version 0.0.1
     */
    public string $title {
        get => $this->data['title'];
    }
    /**
     * Get plugin author.
     *
     * @return string
     * @version 0.0.1
     */
    public string $author {
        get => $this->data['author'];
    }
    /**
     * Get plugin version.
     *
     * @return string
     * @version 0.0.1
     */
    public string $ver {
        get => $this->data['ver'];
    }
    /**
     * Get plugin image.
     *
     * @return string
     * @version 0.0.1
     */
    public string $author_image {
        get => $this->data['author_image'];
    }

    /**
     * Get plugin menu route.
     *
     * @return string
     * @version 0.0.1
     */
    public string | bool $menu {
        get => $this->data['menu'];
    }

    /**
     * Get plugin settings.
     *
     * @return array
     * @version 0.0.1
     */
    public array $settings {
        get => $this->data['settings'];
    }

    /**
     * Get plugin database readiness status.
     *
     * @return bool
     * @version 0.0.1
     */
    public bool $isDbReady {
        get => $this->data['isDbReady'];
    }
    /**
     * Get plugin menu route availability.
     *
     * @return bool
     * @version 0.0.1
     */
    public bool $hasMenuRoute {
        get => $this->data['hasMenuRoute'];
    }

    /**
     * Get plugin coffee link.
     *
     * @return string
     * @version 0.0.1
     */
    public string $coffee_link {
        get => $this->data['coffee_link'];
    }

    /**
     * Get plugin coffee image.
     *
     * @return string
     * @version 0.0.1
     */
    public string $coffee_image {
        get => $this->data['coffee_image'];
    }
}
