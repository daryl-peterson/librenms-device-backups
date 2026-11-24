<?php

namespace DRP\DeviceBackups\Tests\Database;

use DRP\DeviceBackups\Backup\DeviceSshStatus;
use DRP\DeviceBackups\Backup\Target;
use DRP\DeviceBackups\Controllers\DeviceSshController;
use DRP\DeviceBackups\Tests\Support\PluginTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DeviceSshControllerTest extends PluginTestCase {
    public function testIndexAndEditReportEnrollmentWithoutExposingPasswords(): void {
        $device = $this->device(['hostname' => 'branch.example.test']);
        $this->device(['hostname' => 'other.example.test']);
        $controller = new DeviceSshController();

        $form = $controller->edit($device)->getData();
        self::assertFalse($form['enrolled']);
        self::assertSame('edgeos', $form['profile']);

        $device->setAttrib(Target::ATTRIBUTE, 'unreadable ciphertext');
        self::assertTrue($controller->edit($device)->getData()['unreadable']);
        self::assertTrue(Target::save($device, $this->enrollment()));

        $index = $controller->index($this->request('/?search=branch'))->getData();
        self::assertSame([$device->getKey()], $index['devices']->getCollection()->modelKeys());

        $form = $controller->edit($device)->getData();
        self::assertTrue($form['enrolled']);
        self::assertTrue($form['hasPassword']);
        self::assertArrayNotHasKey('password', $form);
    }

    public function testUpdatingAndRemovingEnrollmentPreservesBlankPasswordAndClearsStatus(): void {
        $device = $this->device(['os' => 'ios']);
        $controller = new DeviceSshController();
        $data = [
            'profile' => 'ios',
            'ssh_user' => 'backup',
            'ssh_port' => '2222',
            'fingerprint' => $this->enrollment()['fingerprint'],
            'ssh_password' => 'saved password',
            'prompt_name' => 'router',
            'enable_password' => 'saved enable password',
        ];

        $response = $controller->update($this->request('/', 'POST', $data), $device);
        self::assertStringEndsWith('/ssh/' . $device->getKey(), $response->getTargetUrl());
        self::assertSame('saved password', Target::get($device)['password']);
        self::assertStringNotContainsString('saved password', $device->getAttrib(Target::ATTRIBUTE));

        $data['ssh_password'] = null;
        $data['clear_enable'] = true;
        $controller->update($this->request('/', 'POST', $data), $device);
        self::assertSame('saved password', Target::get($device)['password']);
        self::assertNull(Target::get($device)['enable']);

        $device->setAttrib(DeviceSshStatus::ATTRIBUTE, '{}');
        $controller->remove($device);
        self::assertNull(Target::revision($device));
        self::assertFalse($device->hasAttrib(DeviceSshStatus::ATTRIBUTE));
    }

    public function testInvalidInputDoesNotFlashSecretsOrChangeEnrollment(): void {
        $device = $this->device();
        $controller = new DeviceSshController();
        $controller->update($this->request('/', 'POST', [
            'profile' => 'edgeos', 'ssh_port' => '0', 'ssh_password' => 'discard this',
        ]), $device);

        self::assertTrue($this->session->get('errors')->has('ssh_port'));
        self::assertArrayNotHasKey('ssh_password', $this->session->get('_old_input'));
        self::assertNull(Target::revision($device));
    }

    public function testChecksRejectMissingAndInvalidSettingsWithoutDialingADevice(): void {
        $device = $this->device(['hostname' => 'invalid/hostname']);
        $controller = new DeviceSshController();

        $response = $controller->check($this->request('/', 'POST', [
            'from' => 'status', 'search' => ' test ', 'page' => 2,
        ]), $device);
        self::assertStringContainsString('search=test&page=2', $response->getTargetUrl());
        self::assertStringContainsString('Save valid', $this->session->get('error'));

        self::assertTrue(Target::save($device, $this->enrollment(['port' => 0])));
        $controller->check($this->request('/', 'POST'), $device);
        self::assertSame('invalid_settings', DeviceSshStatus::get($device)['status']);
        self::assertStringContainsString('Check the saved SSH', $this->session->get('error'));

        self::assertTrue(Target::save($device, $this->enrollment()));
        $controller->check($this->request('/', 'POST'), $device);
        self::assertSame('connection_failed', DeviceSshStatus::get($device)['status']);
    }

    public function testFingerprintRejectsAnInvalidPortAndHost(): void {
        $device = $this->device(['hostname' => 'invalid/hostname']);
        $controller = new DeviceSshController();

        $response = $controller->fingerprint($this->request('/', 'POST', ['port' => '65536']), $device);
        self::assertSame(422, $response->getStatusCode());

        $response = $controller->fingerprint($this->request('/', 'POST', ['port' => '22']), $device);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Could not read the host key', $response->getContent());
    }

    public function testDatabaseErrorLeavesExistingEnrollmentUnchanged(): void {
        $device = $this->device();
        $controller = new DeviceSshController();
        // Writes fail while reads of the current enrollment remain available.
        DB::connection('inventory')->statement(<<<'SQL'
            CREATE TRIGGER reject_enrollment BEFORE INSERT ON device_attribs
            BEGIN SELECT RAISE(ABORT, 'test enrollment write failure'); END
            SQL);

        $controller->update($this->request('/', 'POST', [
            'profile' => 'edgeos', 'ssh_user' => 'backup', 'ssh_port' => '22',
            'fingerprint' => $this->enrollment()['fingerprint'], 'ssh_password' => 'test',
        ]), $device);
        self::assertSame('Could not save the device SSH settings.', $this->session->get('error'));

        Schema::connection('inventory')->drop('device_attribs');
        $controller->remove($device);
        self::assertSame('Could not remove the device SSH settings.', $this->session->get('error'));
    }
}
