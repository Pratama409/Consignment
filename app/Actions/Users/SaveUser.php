<?php

namespace App\Actions\Users;

use App\Actions\Audit\WriteAuditLog;
use App\Http\Requests\UserManagement\SaveUserRequest;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaveUser
{
    public function handle(array $validated, ?User $user = null): User
    {
        return $this->persist($validated, $user);
    }

    public function changeStatus(bool $isActive, User $user): User
    {
        return $this->persist(['is_active' => $isActive], $user, statusOnly: true);
    }

    private function persist(array $validated, ?User $user, bool $statusOnly = false): User
    {
        Gate::authorize($user ? 'update' : 'create', $user ?? User::class);

        try {
            return DB::transaction(function () use ($validated, $user, $statusOnly) {
                $user = $user ? User::query()->lockForUpdate()->findOrFail($user->id) : new User;
                $exists = $user->exists;
                if ($statusOnly) {
                    $validated = [...$this->snapshot($user), ...$validated];
                }
                $data = Validator::make($validated, SaveUserRequest::userRules($exists ? $user : null))->validate();
                $before = $exists ? $this->snapshot($user) : [];
                $user->name = $data['name'];
                $user->email = $data['email'];
                $user->role = $data['role'];
                $user->vendor_id = $data['vendor_id'] ?? null;
                $user->employee_no = $data['employee_no'] ?? null;
                $user->position = $data['position'] ?? null;
                $user->is_active = $data['is_active'];
                if (! empty($data['password'])) {
                    $user->password = Hash::make($data['password']);
                }
                $user->save();
                $action = ! $exists ? 'user.created' : ($before['is_active'] !== $user->is_active ? ($user->is_active ? 'user.reactivated' : 'user.deactivated') : 'user.updated');
                app(WriteAuditLog::class)->handle(auth()->user(), $action, $user, $before, $this->snapshot($user));

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'Email sudah digunakan.']);
        }
    }

    private function snapshot(User $user): array
    {
        return [...$user->only('name', 'email', 'vendor_id', 'employee_no', 'position', 'is_active'), 'role' => $user->role->value];
    }
}
