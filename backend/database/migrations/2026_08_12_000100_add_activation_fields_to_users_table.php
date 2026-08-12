<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const EMAIL_INDEX_NAME = 'users_email_lower_unique';

    public function up(): void
    {
        $this->dropSqliteEmailIndex();
        $isSqlite = DB::getDriverName() === 'sqlite';

        Schema::table('users', function (Blueprint $table) use ($isSqlite) {
            if ($isSqlite) {
                $table->enum('role', ['user', 'admin'])
                    ->default('user')
                    ->change();
            }

            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('blocked_at')->nullable();
            $table->text('blocked_reason')->nullable();
            $table->foreignId('blocked_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
        });

        $this->createSqliteEmailIndex();
    }

    public function down(): void
    {
        $this->dropSqliteEmailIndex();
        $isSqlite = DB::getDriverName() === 'sqlite';

        Schema::table('users', function (Blueprint $table) use ($isSqlite) {
            if ($isSqlite) {
                $table->enum('role', ['user', 'admin'])
                    ->default('user')
                    ->change();
            }

            $table->dropForeign(['blocked_by']);
            $table->dropIndex(['is_active']);
            $table->dropColumn([
                'is_active',
                'blocked_at',
                'blocked_reason',
                'blocked_by',
            ]);
        });

        $this->createSqliteEmailIndex();
    }

    private function dropSqliteEmailIndex(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS '.self::EMAIL_INDEX_NAME);
        }
    }

    private function createSqliteEmailIndex(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(sprintf(
                'CREATE UNIQUE INDEX %s ON users (LOWER(email))',
                self::EMAIL_INDEX_NAME,
            ));
        }
    }
};
