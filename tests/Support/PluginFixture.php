<?php

namespace DRP\DeviceBackups\Tests\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class PluginFixture extends Model {
    /** @var list<self>|null Use null to query the test database. */
    public static ?array $records = [];

    protected $connection = 'inventory';
    protected $table = 'plugins';
    protected $primaryKey = 'plugin_id';
    protected $guarded = [];
    protected $casts = ['settings' => 'array', 'plugin_active' => 'bool'];
    public $timestamps = false;

    public static function versionTwo(): Collection|Builder {
        return self::$records === null
            ? self::query()->where('version', 2)
            : (new Collection(self::$records))->where('version', 2);
    }
}
