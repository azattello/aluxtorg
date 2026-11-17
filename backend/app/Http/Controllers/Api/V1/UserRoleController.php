<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateUserRoleRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserRoleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'name_asc', 'name_desc', 'email_asc', 'email_desc'])],
        ]);
        $search = trim($validated['search'] ?? '');
        $sort = $validated['sort'] ?? 'newest';

        $users = User::query()
            ->select(['id', 'name', 'email', 'phone', 'role', 'email_verified_at'])
            ->when($validated['role'] ?? null, fn ($query, string $role) => $query->where('role', $role))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            }));

        $users = match ($sort) {
            'name_asc' => $users->orderBy('name')->orderBy('id'),
            'name_desc' => $users->orderByDesc('name')->orderBy('id'),
            'email_asc' => $users->orderBy('email')->orderBy('id'),
            'email_desc' => $users->orderByDesc('email')->orderBy('id'),
            'oldest' => $users->orderBy('created_at')->orderBy('id'),
            default => $users->orderByDesc('created_at')->orderByDesc('id'),
        };

        return UserResource::collection($users->paginate(25)->withQueryString());
    }

    public function update(UpdateUserRoleRequest $request, User $user): UserResource|JsonResponse
    {
        $newRole = UserRole::from($request->validated('role'));

        if ($request->user()->is($user)) {
            return response()->json([
                'message' => 'Нельзя изменить собственную роль.',
            ], 422);
        }

        $previousRole = DB::transaction(function () use ($request, $user, $newRole): ?UserRole {
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if ($user->email !== null && ! $user->hasVerifiedEmail() && $newRole !== UserRole::Customer) {
                return null;
            }

            $oldRole = $user->role;

            if ($oldRole === $newRole) {
                return $oldRole;
            }

            if ($oldRole === UserRole::SuperAdministrator && $newRole !== UserRole::SuperAdministrator) {
                $superAdministrators = User::query()
                    ->where('role', UserRole::SuperAdministrator->value)
                    ->lockForUpdate()
                    ->get(['id']);

                if ($superAdministrators->count() <= 1) {
                    return null;
                }
            }

            $user->role = $newRole;
            $user->save();

            AuditLog::query()->create([
                'actor_user_id' => $request->user()->getKey(),
                'event_key' => 'access.user_role_changed',
                'subject_type' => User::class,
                'subject_id' => $user->getKey(),
                'metadata' => [
                    'previous_role' => $oldRole->value,
                    'new_role' => $newRole->value,
                ],
            ]);

            return $oldRole;
        });

        if ($previousRole === null) {
            $currentUser = $user->fresh();

            return response()->json([
                'message' => $currentUser->email !== null && ! $currentUser->hasVerifiedEmail() && $newRole !== UserRole::Customer
                    ? 'Для назначения привилегированной роли пользователь должен подтвердить почту.'
                    : 'Нельзя снять роль последнего супер-администратора.',
            ], 422);
        }

        if ($previousRole === $newRole) {
            return new UserResource($user->refresh());
        }

        return new UserResource($user->refresh());
    }
}
