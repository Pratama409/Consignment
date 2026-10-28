<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {--name= : Nama admin} {--email= : Email admin}';

    protected $description = 'Buat akun admin internal tanpa pendaftaran publik.';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Jalankan perintah ini secara interaktif agar kata sandi tidak ditulis pada command line.');

            return self::FAILURE;
        }

        $attributes = [
            'name' => $this->option('name') ?: $this->ask('Nama admin'),
            'email' => mb_strtolower(trim((string) ($this->option('email') ?: $this->ask('Email admin')))),
            'password' => $this->secret('Kata sandi (minimal 12 karakter)'),
            'password_confirmation' => $this->secret('Konfirmasi kata sandi'),
        ];
        $validator = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $admin = new User(collect($attributes)->only(['name', 'email', 'password'])->all());
        $admin->forceFill(['role' => UserRole::Admin])->save();
        $this->info('Akun admin berhasil dibuat. Silakan masuk melalui halaman login.');

        return self::SUCCESS;
    }
}
