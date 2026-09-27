<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateAdmin extends Command
{
    protected $signature = 'gold:make-admin {email} {password} {--name=Admin}';

    protected $description = 'Buat atau perbarui pengguna dengan peran admin untuk login panel admin';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $password = (string) $this->argument('password');
        $name = (string) $this->option('name');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Email tidak valid: {$email}");

            return self::FAILURE;
        }

        if (strlen($password) < 6) {
            $this->error('Password minimal 6 karakter.');

            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password, // di-hash otomatis oleh cast 'hashed'
                'role' => 'admin',
            ]
        );

        $this->info("Admin siap. Email: {$email} | Nama: {$user->name} | Role: {$user->role}");

        return self::SUCCESS;
    }
}