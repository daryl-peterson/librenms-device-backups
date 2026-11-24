<?php

require dirname(__DIR__) . '/.test-vendor/autoload.php';

// LibreNMS provides these models in production.
class_alias(DRP\DeviceBackups\Tests\Support\DeviceFixture::class, App\Models\Device::class);
class_alias(DRP\DeviceBackups\Tests\Support\PluginFixture::class, App\Models\Plugin::class);
class_alias(DRP\DeviceBackups\Tests\Support\MailFixture::class, LibreNMS\Util\Mail::class);
