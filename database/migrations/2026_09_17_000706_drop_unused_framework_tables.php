<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNUSED_TABLES = [
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $protectedTables = $this->protectedTables();

        foreach (self::UNUSED_TABLES as $table) {
            if (in_array($table, $protectedTables, true)) {
                continue;
            }

            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException(sprintf(
                    'La tabla %s contiene datos y no puede eliminarse automaticamente.',
                    $table,
                ));
            }
        }

        foreach (self::UNUSED_TABLES as $table) {
            if (in_array($table, $protectedTables, true)) {
                continue;
            }

            Schema::dropIfExists($table);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table): void {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }

        if (! Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        }

        if (! Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration');
            });
        }

        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table): void {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (! Schema::hasTable('job_batches')) {
            Schema::create('job_batches', function (Blueprint $table): void {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });
        }

        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table): void {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }
    }

    /**
     * @return array<int, string>
     */
    private function protectedTables(): array
    {
        $protectedTables = [];

        if (config('cache.default') === 'database') {
            $protectedTables = array_merge($protectedTables, ['cache', 'cache_locks']);
        }

        if (config('session.driver') === 'database') {
            $protectedTables[] = 'sessions';
        }

        if (config('queue.default') === 'database') {
            $protectedTables[] = 'jobs';
        }

        if (config('queue.failed.driver') === 'database') {
            $protectedTables[] = 'failed_jobs';
        }

        if (filled(config('queue.batching.database'))) {
            $protectedTables[] = 'job_batches';
        }

        return array_values(array_unique($protectedTables));
    }
};
