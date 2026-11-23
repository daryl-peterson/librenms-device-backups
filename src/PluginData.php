<?php
declare(strict_types=1);

namespace DRP\DeviceBackups;

use App\Models\Plugin as PluginModel;
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

    private string $pvtName = PLUGIN_NAME;
    private string $pvtTitle = PLUGIN_TITLE;
    private string $pvtAuthor = PLUGIN_AUTHOR;
    private string $pvtVer = PLUGIN_VER;
    private string $pvtImage = 'https://avatars.githubusercontent.com/u/13834451?s=400&u=ff8417db6126da8d9ff82822ea0be5897ad744b3&v=4';
    private array $pvtSettings = [];
    private array $dbStatus = [
        'ready' => false,
        'error' => null
    ];

    public function __construct() {
        $this->pvtSettings = self::getSettings();
    }

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
            'name'     => $obj->pvtName,
            'title'    => $obj->pvtTitle,
            'author'   => $obj->pvtAuthor,
            'ver'      => $obj->pvtVer,
            'image'    => $obj->pvtImage,
            'settings' => $obj->pvtSettings,
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
                'name'     => $obj->pvtName,
                'title'    => $obj->pvtTitle,
                'author'   => $obj->pvtAuthor,
                'ver'      => $obj->pvtVer,
                'image'    => $obj->pvtImage,
                'dbStatus'  => [
                    'ready' => !$dbError,
                    'error' => $dbError
                ]
            ],
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
        get => $this->pvtName;
    }

    /**
     * Get plugin title.
     *
     * @return string
     * @version 0.0.1
     */
    public string $title {
        get => $this->pvtTitle;
    }
    /**
     * Get plugin author.
     *
     * @return string
     * @version 0.0.1
     */
    public string $author {
        get => $this->pvtAuthor;
    }
    /**
     * Get plugin version.
     *
     * @return string
     * @version 0.0.1
     */
    public string $ver {
        get => $this->pvtVer;
    }
    /**
     * Get plugin image.
     *
     * @return string
     * @version 0.0.1
     */
    public string $image {
        get => $this->pvtImage;
    }
    /**
     * Get plugin settings.
     *
     * @return array
     * @version 0.0.1
     */
    public array $settings {
        get => $this->pvtSettings;
    }
}
