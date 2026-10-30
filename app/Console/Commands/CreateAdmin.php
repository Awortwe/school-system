<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'tss:create-admin
        {name : Full name of the administrator}
        {email : Login email address}
        {--password= : Password (prompted when omitted)}
        {--employee-no= : Optional staff employee number}';

    protected $description = 'Create a Super Administrator account for The Sanctuary Schools';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        $email = strtolower(trim((string) $this->argument('email')));

        $password = $this->option('password') ?: $this->secret('Password (minimum 12 characters)');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'employee_no' => $this->option('employee-no') ?: null,
            'employment_status' => 'active',
            'employment_date' => now()->toDateString(),
            'is_active' => true,
            'must_change_password' => true,
            'email_verified_at' => now(),
        ]);

        $user->assignRole('super_admin');

        $this->info('Super Administrator created successfully.');
        $this->line('Email: '.$user->email);
        $this->line('The user must change the password at first login.');

        return self::SUCCESS;
    }
}
