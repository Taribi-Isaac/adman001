<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreUserRequest;
use App\Http\Requests\Settings\UpdateUserRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserManagementController extends Controller
{
    public function index(): Response
    {
        $this->authorize(Permissions::USERS_VIEW);

        $users = User::query()
            ->with('roles:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'roles' => $user->roles->pluck('name'),
                'email_verified_at' => $user->email_verified_at,
            ]);

        return Inertia::render('settings/users/Index', [
            'users' => $users,
            'roles' => Role::query()->orderBy('name')->pluck('name'),
            'canCreate' => auth()->user()?->can(Permissions::USERS_CREATE) ?? false,
            'canUpdate' => auth()->user()?->can(Permissions::USERS_UPDATE) ?? false,
            'canManageRoles' => auth()->user()?->can(Permissions::USERS_MANAGE_ROLES) ?? false,
        ]);
    }

    public function store(StoreUserRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize(Permissions::USERS_CREATE);

        $data = $request->validated();

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'is_active' => $data['is_active'] ?? true,
            'email_verified_at' => now(),
        ]);

        if (! empty($data['role']) && ($request->user()?->can(Permissions::USERS_MANAGE_ROLES) ?? false)) {
            $user->syncRoles([$data['role']]);
        } else {
            $user->syncRoles([User::ROLE_STAFF]);
        }

        $auditLogger->record(
            event: 'user.created',
            description: 'Staff user created',
            auditable: $user,
            newValues: [
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames()->all(),
            ],
        );

        return back()->with('success', 'User created.');
    }

    public function update(UpdateUserRequest $request, User $user, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize(Permissions::USERS_UPDATE);

        $data = $request->validated();
        $old = $user->only(['name', 'email', 'is_active']);
        $old['roles'] = $user->getRoleNames()->all();

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'is_active' => $data['is_active'],
        ]);

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        if (array_key_exists('role', $data) && ($request->user()?->can(Permissions::USERS_MANAGE_ROLES) ?? false)) {
            $user->syncRoles([$data['role']]);
        }

        $auditLogger->record(
            event: 'user.updated',
            description: 'Staff user updated',
            auditable: $user,
            oldValues: $old,
            newValues: [
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'roles' => $user->getRoleNames()->all(),
            ],
        );

        return back()->with('success', 'User updated.');
    }
}
