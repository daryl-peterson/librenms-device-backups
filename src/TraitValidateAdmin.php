<?php

/**
 * LibreNMS Device Backups Trait to validate admin users.
 *
 * @package     device-backups
 * @author      Daryl Peterson <@gmail.com>
 * @license     https://opensource.org MIT License
 * @link        https://github.com/daryl-peterson/
 * @since       0.0.1
 */

namespace DRP\DeviceBackups;

/**
 * Laravel imports.
 */

use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Plugin imports.
 */

use DRP\DeviceBackups\Helper;

trait TraitValidateAdmin {
    /**
     * Check if the current user is an admin and abort if not.
     *
     * @return void
     *
     * @since 0.0.1
     * @throws HttpException
     * @throws NotFoundHttpException
     * @throws HttpResponseException
     *
     */
    public function validateAdmin(): void {
        if (!Helper::isAdmin()) {
            abort(403, 'Unauthorized action.');
        }
    }
}
