<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\EmailAddress;
use Illuminate\Console\Command;

class MakeAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:admin {email : The email address of the existing user}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Promote an existing user to the admin role';

    public function handle(): int
    {
        $email = EmailAddress::canonicalize((string) $this->argument('email'));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user exists with email [{$email}].");

            return self::FAILURE;
        }

        if ($user->isAdmin()) {
            $this->info("User [{$user->email}] is already an admin.");

            return self::SUCCESS;
        }

        $user->role = User::ROLE_ADMIN;
        $user->save();

        $this->info("User [{$user->email}] is now an admin.");

        return self::SUCCESS;
    }
}
