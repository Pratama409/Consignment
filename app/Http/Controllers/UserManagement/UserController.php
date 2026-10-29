<?php

namespace App\Http\Controllers\UserManagement;

use App\Actions\Users\SaveUser;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserManagement\SaveUserRequest;
use App\Http\Requests\UserManagement\UserIndexRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Queries\UserListQuery;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class UserController extends Controller
{
    public function index(UserIndexRequest $request, UserListQuery $query)
    {
        $filters = [...['q' => '', 'role' => 'all', 'status' => 'all', 'vendor' => '', 'sort' => 'name', 'direction' => 'asc', 'per_page' => 10], ...$request->safe()->except('page')];
        $filters['q'] ??= '';
        $filters['vendor'] ??= '';
        $users = $query->make($filters)->paginate((int) $filters['per_page'])->withQueryString();
        if ($users->currentPage() > $users->lastPage()) {
            return to_route('users.index', [...$filters, 'page' => $users->lastPage()]);
        }
        $users->through(fn (User $user) => [...$this->serialize($user), 'edit_url' => route('users.edit', $user), 'status_url' => route('users.status', $user)]);

        return Inertia::render('UserManagement/Index', ['users' => $users, 'filters' => $filters, 'options' => $this->options(), 'create_url' => route('users.create')]);
    }

    public function create()
    {
        Gate::authorize('create', User::class);

        return $this->form();
    }

    public function edit(User $user)
    {
        Gate::authorize('update', $user);

        return $this->form($user);
    }

    public function store(SaveUserRequest $request, SaveUser $save)
    {
        $save->handle($request->validated());

        return to_route('users.index')->with('success', 'Pengguna berhasil ditambahkan.');
    }

    public function update(SaveUserRequest $request, User $user, SaveUser $save)
    {
        $save->handle($request->validated(), $user);

        return to_route('users.index')->with('success', 'Perubahan pengguna berhasil disimpan.');
    }

    public function status(SaveUserRequest $request, User $user, SaveUser $save)
    {
        $save->handle([...$this->serialize($user), ...$request->validated()], $user);

        return to_route('users.index', Arr::only($request->query(), ['q', 'role', 'status', 'vendor', 'sort', 'direction', 'per_page', 'page']))->with('success', 'Status pengguna berhasil diubah.');
    }

    private function form(?User $user = null)
    {
        return Inertia::render('UserManagement/Form', ['user' => $user ? $this->serialize($user) : null, 'options' => $this->options(), 'submit_url' => $user ? route('users.update', $user) : route('users.store')]);
    }

    private function serialize(User $user): array
    {
        return [...$user->only('id', 'name', 'email', 'vendor_id', 'employee_no', 'position', 'is_active'), 'role' => $user->role->value, 'role_label' => $user->role->label(), 'vendor_name' => $user->vendor?->vendor_name];
    }

    private function options(): array
    {
        return ['roles' => array_map(fn (UserRole $role) => ['value' => $role->value, 'label' => $role->label()], UserRole::cases()), 'vendors' => Vendor::orderBy('vendor_code')->get(['id', 'vendor_code', 'vendor_name', 'is_active'])];
    }
}
