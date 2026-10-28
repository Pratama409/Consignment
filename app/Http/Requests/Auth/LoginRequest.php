<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Masukkan alamat email Anda.',
            'email.email' => 'Masukkan alamat email yang valid.',
            'password.required' => 'Masukkan kata sandi Anda.',
        ];
    }

    public function authenticate(): void
    {
        $key = Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            event(new Lockout($this));
            throw ValidationException::withMessages([
                'email' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.',
            ]);
        }

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($key);
            throw ValidationException::withMessages(['email' => 'Email atau kata sandi tidak sesuai.']);
        }

        RateLimiter::clear($key);
    }
}
