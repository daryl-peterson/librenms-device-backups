<?php

namespace DRP\DeviceBackups\Tests\Support;

use Illuminate\Auth\GenericUser;

final class UserFixture extends GenericUser {
    public function hasRole(string $role): bool {
        return $this->role === $role;
    }

    public function can(string $ability, mixed $target): bool {
        return $ability === 'view' && $target->getKey() !== $this->hiddenDevice;
    }
}
