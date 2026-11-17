<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Lets an existing account see the admin report, or takes that away. Admin is never set
 * through sign-up: an operator runs this, for example in Laravel Cloud's console.
 */
final class MakeAdmin extends Command
{
    /**
     * @var string
     */
    protected $signature = 'users:make-admin
        {email : The email address of an existing account}
        {--remove : Take admin away instead}';

    /**
     * @var string
     */
    protected $description = 'Let an existing account see the admin report, or take that away';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No account has the email address {$email}.");

            return self::FAILURE;
        }

        $user->is_admin = ! $this->option('remove');
        $user->save();

        $this->info($user->is_admin ? "{$email} is now an admin." : "{$email} is no longer an admin.");

        return self::SUCCESS;
    }
}
