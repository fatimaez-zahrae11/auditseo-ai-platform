<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const INDEX_NAME = 'users_email_lower_unique';

    public function up(): void
    {
        $this->ensureSupportedDatabase();

        $canonicalEmails = [];
        $updates = [];

        foreach (DB::table('users')->select(['id', 'email'])->orderBy('id')->get() as $user) {
            $canonicalEmail = Str::lower(trim((string) $user->email));

            if ($canonicalEmail === '' || isset($canonicalEmails[$canonicalEmail])) {
                throw new LogicException(
                    'User emails cannot be canonicalized because duplicate or invalid identities exist.',
                );
            }

            $canonicalEmails[$canonicalEmail] = true;

            if ($canonicalEmail !== $user->email) {
                $updates[(int) $user->id] = $canonicalEmail;
            }
        }

        foreach ($updates as $id => $canonicalEmail) {
            DB::table('users')->where('id', $id)->update([
                'email' => $canonicalEmail,
            ]);
        }

        DB::statement(sprintf(
            'CREATE UNIQUE INDEX %s ON users (LOWER(email))',
            self::INDEX_NAME,
        ));
    }

    public function down(): void
    {
        $this->ensureSupportedDatabase();

        DB::statement(sprintf(
            'DROP INDEX IF EXISTS %s',
            self::INDEX_NAME,
        ));
    }

    private function ensureSupportedDatabase(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new LogicException(
                'Canonical user email uniqueness requires PostgreSQL or SQLite.',
            );
        }
    }
};
