<?php

require dirname(__DIR__) . '/.test-vendor/autoload.php';

// LibreNMS provides this model in production. These tests only need device data and attributes.
class_alias(DRP\DeviceBackups\Tests\Support\DeviceFixture::class, App\Models\Device::class);
