<?php

namespace DRP\DeviceBackups\Tests\Support;

use Illuminate\Database\Eloquent\Model;

final class DeviceAttributeFixture extends Model {
    protected $connection = 'inventory';
    protected $table = 'device_attribs';
    protected $guarded = [];
    public $timestamps = false;
}
