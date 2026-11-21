<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'device_backups';

    public function up(): void {
        Schema::connection($this->getConnection())->table('device_backup_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('requested_device_id')->nullable();
            $table->dateTime('requested_at')->nullable();
            $table->string('request_error')->nullable();
            $table->index(['requested_device_id', 'status']);
            $table->index(['status', 'requested_at']);
        });
    }

    public function down(): void {
        Schema::connection($this->getConnection())->table('device_backup_runs', function (Blueprint $table): void {
            $table->dropIndex(['requested_device_id', 'status']);
            $table->dropIndex(['status', 'requested_at']);
            $table->dropColumn(['requested_device_id', 'requested_at', 'request_error']);
        });
    }
};
