<?php

namespace DRP\DeviceBackups\Tests\Unit;

use DRP\DeviceBackups\Helper;
use Illuminate\Auth\GenericUser;
use Illuminate\Auth\RequestGuard;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HelperTest extends TestCase {
    #[DataProvider('roles')]
    public function testOnlyAuthenticatedAdminsAreAllowed(?string $role, bool $expected): void {
        $user = $role === null ? null : new class (['role' => $role]) extends GenericUser {
            public function hasRole(string $role): bool {
                return $this->role === $role;
            }
        };
        $app = new Container();
        $app->instance('auth', new RequestGuard(static fn () => $user, Request::create('/')));
        $previousApp = Auth::getFacadeApplication();
        Auth::clearResolvedInstance('auth');
        Auth::setFacadeApplication($app);

        try {
            self::assertSame($expected, Helper::isAdmin());
        } finally {
            Auth::clearResolvedInstance('auth');
            Auth::setFacadeApplication($previousApp);
        }
    }

    public static function roles(): array {
        return [
            'guest' => [null, false],
            'normal user' => ['user', false],
            'admin' => ['admin', true],
        ];
    }

    public function testConvertsDurationsToSeconds(): void {
        self::assertSame(120, Helper::minutes(2));
        self::assertSame(10_800, Helper::hours(3));
        self::assertSame(172_800, Helper::days(2));
    }
}
