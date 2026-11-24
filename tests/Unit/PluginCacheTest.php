<?php

namespace DRP\DeviceBackups\Tests\Unit;

use DRP\DeviceBackups\PluginCache;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\TestCase;

final class PluginCacheTest extends TestCase {
    public function testPluginEntriesAreTaggedAndCanBeRemovedWithoutAffectingOtherEntries(): void {
        $cache = new Repository(new ArrayStore());
        $app = new Container();
        $app->instance('cache', $cache);
        $previousApp = Cache::getFacadeApplication();
        Cache::clearResolvedInstance('cache');
        Cache::setFacadeApplication($app);

        $key = 'device-backups-unit-test-key';

        try {
            $cache->put($key, 'unrelated value');

            self::assertFalse(PluginCache::has($key));
            self::assertSame('fallback', PluginCache::get($key, 'fallback'));
            self::assertTrue(PluginCache::set($key, 'plugin value'));
            self::assertTrue(PluginCache::has($key));
            self::assertSame('plugin value', PluginCache::all()[$key]);
            self::assertContains($key, PluginCache::keys());
            self::assertSame('unrelated value', $cache->get($key));

            self::assertTrue(PluginCache::forget($key));
            self::assertFalse(PluginCache::has($key));
            self::assertNotContains($key, PluginCache::keys());

            self::assertTrue(PluginCache::set($key, 'new plugin value'));
            self::assertTrue(PluginCache::flush());
            self::assertFalse(PluginCache::has($key));
            self::assertSame('unrelated value', $cache->get($key));
        } finally {
            PluginCache::forget($key);
            Cache::clearResolvedInstance('cache');
            Cache::setFacadeApplication($previousApp);
        }
    }
}
