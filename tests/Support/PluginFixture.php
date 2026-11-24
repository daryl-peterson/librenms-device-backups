<?php

namespace DRP\DeviceBackups\Tests\Support;

use Illuminate\Support\Collection;

final class PluginFixture {
    /** @var list<self> */
    public static array $records = [];

    public function __construct(
        public readonly string $plugin_name,
        public readonly int $version,
        public readonly array $settings,
    ) {}

    public static function versionTwo(): Collection {
        return (new Collection(self::$records))->where('version', 2);
    }
}
