<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Local/staging bootstrap for the first Super Administrator.
 *
 * Local:
 *   php artisan adman:create-super-admin
 *
 * Production:
 *   Do not use this command. Create the first admin through a controlled break-glass process.
 */
class CreateSuperAdminCommand extends Command
{
    protected $signature = 'adman:create-super-admin
                            {--name= : Full name of the administrator}
                            {--email= : Email address used for login}
                            {--password= : Password (prompted securely if omitted)}
                            {--force-staging : Allow use on APP_ENV=staging (never production)}';

    protected $description = 'Create or promote a Super Administrator (local development; staging with --force-staging only)';

    public function handle(AuditLogger $auditLogger): int
    {
        if (app()->isProduction()) {
            $this->error('Refused: adman:create-super-admin cannot run in production.');
            $this->line('Create production administrators through a controlled, audited process — never via a default bootstrap password.');

            return self::FAILURE;
        }

        if (app()->environment('staging') && ! $this->option('force-staging')) {
            $this->error('Refused: staging requires --force-staging to create a Super Administrator.');
            $this->line('Use staging-specific credentials and do not reuse production passwords.');

            return self::FAILURE;
        }

        $this->call('db:seed', [
            '--class' => RolesAndPermissionsSeeder::class,
            '--force' => true,
        ]);

        $name = $this->option('name')
            ?: env('ADMAN_SUPER_ADMIN_NAME')
            ?: $this->ask('Name', 'Super Administrator');

        $email = $this->option('email')
            ?: env('ADMAN_SUPER_ADMIN_EMAIL')
            ?: $this->ask('Email');

        $password = $this->option('password') ?: env('ADMAN_SUPER_ADMIN_PASSWORD');
        if (! is_string($password) || $password === '') {
            $password = $this->secret('Password');
            $confirm = $this->secret('Confirm password');

            if ($password !== $confirm) {
                $this->error('Passwords do not match.');

                return self::FAILURE;
            }
        }

        $passwordRules = ['required', 'string', 'min:12'];
        if (app()->environment('staging')) {
            $passwordRules[] = Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols();
        }

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => $passwordRules,
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        /** @var User $user */
        $user = User::query()->firstOrNew(['email' => strtolower(trim((string) $email))]);
        $wasExisting = $user->exists;

        $user->fill([
            'name' => $name,
            'password' => Hash::make($password),
            'is_active' => true,
        ]);
        $user->email_verified_at = now();
        $user->save();

        $user->syncRoles([User::ROLE_SUPER_ADMINISTRATOR]);

        $auditLogger->record(
            event: $wasExisting ? 'user.promoted_super_admin' : 'user.created_super_admin',
            description: $wasExisting
                ? 'Existing user promoted to Super Administrator'
                : 'Super Administrator created via artisan command',
            auditable: $user,
            newValues: [
                'email' => $user->email,
                'name' => $user->name,
                'environment' => app()->environment(),
            ],
            actor: $user,
        );

        $this->info($wasExisting
            ? "Promoted {$user->email} to Super Administrator."
            : "Created Super Administrator {$user->email}.");
        $this->comment('Password was not printed. Store credentials in your password manager.');

        return self::SUCCESS;
    }
}
