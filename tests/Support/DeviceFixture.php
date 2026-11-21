<?php

namespace DRP\DeviceBackups\Tests\Support;

final class DeviceFixture {
    public function __construct(
        public string $hostname = 'router.example.test',
        public ?string $os = null,
        public ?string $sysName = null,
        public ?string $hardware = null,
        public ?string $sysDescr = null,
        public array $attributes = [],
    ) {}

    public function getAttrib(string $name, mixed $default = null): mixed {
        return $this->attributes[$name] ?? $default;
    }
}
