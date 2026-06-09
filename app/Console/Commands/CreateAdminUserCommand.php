<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdminUserCommand extends Command
{
    protected $signature = 'titan:create-admin
                            {--email= : Admin email (or TITAN_ADMIN_EMAIL)}
                            {--name= : Display name (or TITAN_ADMIN_NAME)}
                            {--password= : Password (or TITAN_ADMIN_PASSWORD)}';

    protected $description = 'Create or update a platform admin user (for production bootstrap on Laravel Cloud)';

    public function handle(): int
    {
        $email = $this->option('email') ?: env('TITAN_ADMIN_EMAIL');
        $name = $this->option('name') ?: env('TITAN_ADMIN_NAME');
        $password = $this->option('password') ?: env('TITAN_ADMIN_PASSWORD');

        $existing = is_string($email)
            ? User::query()->where('email', $email)->first()
            : null;

        $rules = [
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
        ];

        if ($existing === null) {
            $rules['password'] = ['required', Password::defaults()];
        } elseif ($password !== null && $password !== '') {
            $rules['password'] = [Password::defaults()];
        }

        $validator = Validator::make(
            [
                'email' => $email,
                'name' => $name,
                'password' => $password,
            ],
            $rules,
            [
                'email.required' => 'Set --email or TITAN_ADMIN_EMAIL.',
                'name.required' => 'Set --name or TITAN_ADMIN_NAME.',
                'password.required' => 'Set --password or TITAN_ADMIN_PASSWORD when creating a new admin.',
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $validated = $validator->validated();

        if ($existing !== null) {
            $existing->fill([
                'name' => $validated['name'],
                'role' => UserRole::Admin,
            ]);

            if (array_key_exists('password', $validated)) {
                $existing->password = $validated['password'];
            }

            $existing->save();

            $this->info(array_key_exists('password', $validated)
                ? "Updated admin user {$validated['email']}."
                : "Updated admin user {$validated['email']} (password unchanged).");

            return self::SUCCESS;
        }

        User::query()->create([
            'email' => $validated['email'],
            'name' => $validated['name'],
            'password' => $validated['password'],
            'role' => UserRole::Admin,
        ]);

        $this->info("Created admin user {$validated['email']}.");

        return self::SUCCESS;
    }
}
