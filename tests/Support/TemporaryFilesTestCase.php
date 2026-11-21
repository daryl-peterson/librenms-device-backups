<?php

namespace DRP\DeviceBackups\Tests\Support;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;

abstract class TemporaryFilesTestCase extends TestCase {
    private string $directory;

    protected function setUp(): void {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/device-backups-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void {
        foreach (new FilesystemIterator($this->directory) as $entry) {
            unlink($entry->getPathname());
        }
        rmdir($this->directory);

        parent::tearDown();
    }

    protected function path(string $filename): string {
        return $this->directory . '/' . $filename;
    }
}
