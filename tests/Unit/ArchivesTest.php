<?php

namespace DRP\DeviceBackups\Tests\Unit;

use DRP\DeviceBackups\Backup\Archives;
use DRP\DeviceBackups\Backup\BackupFailure;
use DRP\DeviceBackups\Tests\Support\TemporaryFilesTestCase;
use ZipArchive;

/**
 * LibreNMS Device Backups Archives Test.
 *
 * @package     device-backups
 * @author      Daryl Peterson <@gmail.com>
 * @license     https://opensource.org MIT License
 * @link        https://github.com/daryl-peterson/
 * @since       0.0.1
 */
final class ArchivesTest extends TemporaryFilesTestCase {
    public function testCreatesAnAes256ZipWithVerifiableContents(): void {
        Archives::verifyAvailable();
        $source = $this->path('source.txt');
        $archive = $this->path('backup.zip');
        $configuration = "hostname fixture\ninterface Ethernet1\nend\n";
        file_put_contents($source, $configuration);

        $checksum = Archives::zip($archive, $source, 'running-config.txt', 'fixture-password');
        self::assertSame(hash_file('sha256', $archive), $checksum);
        self::assertFileDoesNotExist($archive . '.partial');

        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::RDONLY));
        try {
            self::assertSame(ZipArchive::EM_AES_256, $zip->statName('running-config.txt')['encryption_method']);
            self::assertFalse($zip->getFromName('running-config.txt'));
            $zip->setPassword('fixture-password');
            self::assertSame($configuration, $zip->getFromName('running-config.txt'));
        } finally {
            $zip->close();
        }
    }

    public function testEnforcesTheFreeSpaceReserve(): void {
        $this->expectException(BackupFailure::class);
        $this->expectExceptionMessage('free-space reserve');

        Archives::checkSpace(dirname($this->path('unused')), PHP_INT_MAX);
    }
}
