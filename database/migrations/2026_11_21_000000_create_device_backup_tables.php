<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'device_backups';

    public function up(): void {
        $schema = Schema::connection($this->getConnection());
        $schema->create('device_backup_runs', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->string('status', 16);
            $table->string('email_status', 24)->default('pending');
        });

        $schema->create('device_backup_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('device_backup_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('device_id')->index();
            $table->string('hostname');
            $table->string('profile', 24)->nullable();
            $table->string('status', 16);
            $table->string('error')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->unsignedBigInteger('archive_bytes')->nullable();
        });

        $schema->create('device_backup_chunks', function (Blueprint $table): void {
            $table->foreignId('result_id')->constrained('device_backup_results')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->binary('payload');
            $table->primary(['result_id', 'sequence']);
        });
    }

    public function down(): void {
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('device_backup_chunks');
        $schema->dropIfExists('device_backup_results');
        $schema->dropIfExists('device_backup_runs');
    }
};
