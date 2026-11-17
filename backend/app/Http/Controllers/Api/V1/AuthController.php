<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::query()->create($request->safe()->only(['name', 'email', 'phone', 'password']));

        if ($user->email) {
            event(new Registered($user));
        }

        return response()->json([
            'message' => $user->email
                ? 'Аккаунт создан. Проверьте почту, чтобы подтвердить адрес.'
                : 'Аккаунт создан. Теперь можно войти по номеру телефона.',
        ], 201);
    }

    public function login(LoginRequest $request): UserResource
    {
        $identifier = $request->validated('email');
        $userQuery = User::query();
        $user = str_contains($identifier, '@')
            ? $userQuery->where('email', $identifier)->first()
            : $userQuery->where('phone', $identifier)->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Неверный телефон или электронная почта, либо пароль.'],
            ]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return new UserResource($user);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Вы вышли из аккаунта.']);
    }

    public function currentUser(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function updateProfile(UpdateProfileRequest $request): UserResource
    {
        $user = $request->user();
        $data = $request->validated();
        $emailChanged = array_key_exists('email', $data) && $data['email'] !== $user->email;

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->fill($data)->save();
        if ($emailChanged && $user->email) {
            $user->sendEmailVerificationNotification();
        }

        return new UserResource($user->refresh());
    }

    public function sendVerificationNotification(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'Электронная почта уже подтверждена.']);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'Отправили письмо со ссылкой для подтверждения.',
        ], 202);
    }

    public function verifyEmail(Request $request, int $id, string $hash): JsonResponse|RedirectResponse
    {
        $user = User::query()->findOrFail($id);
        $wasVerified = $user->hasVerifiedEmail();

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            abort(403, 'Ссылка подтверждения недействительна.');
        }

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        if (! $request->expectsJson()) {
            return redirect()->away(rtrim((string) config('app.frontend_url'), '/').'/?verified=1');
        }

        if ($wasVerified) {
            return response()->json(['message' => 'Электронная почта уже подтверждена.']);
        }

        return response()->json(['message' => 'Электронная почта подтверждена.']);
    }

    public function sendPasswordResetLink(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->safe()->only('email'));

        return response()->json([
            'message' => 'Если аккаунт с таким адресом существует, письмо для сброса пароля будет отправлено.',
        ], 202);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->safe()->only(['email', 'password', 'password_confirmation', 'token']),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json(['message' => 'Пароль обновлён. Теперь можно войти.']);
    }
}
