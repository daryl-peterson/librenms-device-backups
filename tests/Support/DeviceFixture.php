<?php

namespace DRP\DeviceBackups\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class DeviceFixture extends Model {
    protected $connection = 'inventory';
    protected $table = 'devices';
    protected $primaryKey = 'device_id';
    protected $guarded = [];
    protected $attributes = ['hostname' => 'router.example.test', 'disabled' => false];
    public $timestamps = false;

    private array $unsavedAttribs = [];

    public function attribs(): HasMany {
        return $this->hasMany(DeviceAttributeFixture::class, 'device_id');
    }

    public function getAttrib(string $name, mixed $default = null): mixed {
        if (!$this->exists) {
            return $this->unsavedAttribs[$name] ?? $default;
        }

        return $this->attribs()->where('attrib_type', $name)->value('attrib_value') ?? $default;
    }

    public function setAttrib(string $name, string $value): bool {
        if (!$this->exists) {
            $this->unsavedAttribs[$name] = $value;
            return true;
        }

        $this->attribs()->updateOrCreate(['attrib_type' => $name], ['attrib_value' => $value]);
        return true;
    }

    public function hasAttrib(string $name): bool {
        return $this->getAttrib($name) !== null;
    }

    public function forgetAttrib(string $name): bool {
        return $this->attribs()->where('attrib_type', $name)->delete() > 0;
    }
}
