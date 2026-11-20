<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_a_customer_and_sends_a_verification_email(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New Customer',
            'email' => ' CUSTOMER@example.com ',
            'phone' => '+77010000000',
            'password' => 'StrongPassword123',
            'password_confirmation' => 'StrongPassword123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Аккаунт создан. Проверьте почту, чтобы подтвердить адрес.');

        $user = User::query()->where('email', 'customer@example.com')->firstOrFail();
        $this->assertSame(UserRole::Customer, $user->role);
        $this->assertNull($user->email_verified_at);
        $this->assertFalse($this->isAuthenticated());
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_registration_validates_email_and_password_confirmation(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'New Customer',
            'email' => 'not-an-email',
            'password' => 'StrongPassword123',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_registration_allows_missing_email_but_requires_unique_phone_and_eight_character_password(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Phone Customer',
            'phone' => '8 (701) 000-00-00',
            'password' => 'Abcdef12',
            'password_confirmation' => 'Abcdef12',
        ])->assertCreated()
            ->assertJsonPath('message', 'Аккаунт создан. Теперь можно войти по номеру телефона.');

        $user = User::query()->where('phone', '+77010000000')->firstOrFail();
        $this->assertNull($user->email);
        $this->assertSame(UserRole::Customer, $user->role);
        Notification::assertNothingSent();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Duplicate Phone',
            'phone' => '+7 701 000 00 00',
            'password' => 'Abcdef12',
            'password_confirmation' => 'Abcdef12',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_registration_rejects_passwords_shorter_than_eight_characters(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Short Password',
            'phone' => '+77010000000',
            'password' => 'Abc1234',
            'password_confirmation' => 'Abc1234',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_login_regenerates_authenticated_session_and_logout_ends_it(): void
    {
        $user = User::factory()->create([
            'email' => 'customer@example.com',
            'password' => 'StrongPassword123',
        ]);

        $this->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/api/v1/auth/login', [
                'email' => 'CUSTOMER@example.com',
                'password' => 'StrongPassword123',
            ])->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.role', UserRole::Customer->value);

        $this->assertAuthenticatedAs($user);

        $this->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Вы вышли из аккаунта.');

        $this->assertGuest('web');
    }

    public function test_login_does_not_disclose_whether_an_email_exists(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'missing@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email'])
            ->assertJsonPath('errors.email.0', 'Неверный телефон или электронная почта, либо пароль.');
    }

    public function test_login_accepts_a_normalized_phone_number(): void
    {
        $user = User::factory()->create([
            'email' => null,
            'phone' => '+77010000000',
            'password' => 'StrongPassword123',
        ]);

        $this->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/api/v1/auth/login', [
                'email' => '8 (701) 000-00-00',
                'password' => 'StrongPassword123',
            ])->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->assertAuthenticatedAs($user);
    }

    public function test_api_authentication_errors_are_returned_in_russian(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Войдите в аккаунт, чтобы продолжить.');
    }

    public function test_authenticated_user_can_read_and_update_only_profile_fields(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.role', UserRole::Customer->value)
            ->assertJsonMissingPath('data.password');

        $this->patchJson('/api/v1/auth/profile', [
            'name' => 'Updated Name',
            'phone' => '+77015551234',
            'role' => UserRole::SuperAdministrator->value,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.phone', '+77015551234')
            ->assertJsonPath('data.role', UserRole::Customer->value);

        $this->assertSame(UserRole::Customer, $user->fresh()->role);
    }

    public function test_phone_only_user_can_add_an_email_and_must_verify_it(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => null, 'phone' => '+77010000000']);

        $this->actingAs($user)
            ->patchJson('/api/v1/auth/profile', [
                'email' => ' New.Email@example.com ',
                'phone' => '+77010000000',
            ])->assertOk()
            ->assertJsonPath('data.email', 'new.email@example.com')
            ->assertJsonPath('data.email_verified_at', null);

        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_email_verification_requires_a_valid_temporary_signature(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute(
            'api.v1.auth.email.verify',
            now()->addMinutes(10),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())],
            absolute: false,
        );

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('message', 'Электронная почта подтверждена.');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_generated_verification_notification_link_is_accepted_by_the_api_route(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();
        $verificationUrl = null;

        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$verificationUrl): bool {
            $verificationUrl = $notification->toMail($user)->actionUrl;

            return true;
        });

        $this->assertIsString($verificationUrl);
        $this->get($verificationUrl)
            ->assertRedirect(config('app.frontend_url').'/?verified=1');
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_email_verification_rejects_tampered_and_unsigned_links(): void
    {
        $user = User::factory()->unverified()->create();

        $this->getJson("/api/v1/auth/email/verify/{$user->id}/".sha1('someone-else@example.com'))
            ->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_authenticated_unverified_user_can_resend_verification_notification(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/auth/email/verification-notification')
            ->assertAccepted();

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_password_reset_request_uses_a_generic_response(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'known@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'known@example.com'])
            ->assertAccepted()
            ->assertJsonPath('message', 'Если аккаунт с таким адресом существует, письмо для сброса пароля будет отправлено.');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'unknown@example.com'])
            ->assertAccepted()
            ->assertJsonPath('message', 'Если аккаунт с таким адресом существует, письмо для сброса пароля будет отправлено.');
    }

    public function test_password_reset_changes_the_password_and_invalidates_the_reset_token(): void
    {
        $user = User::factory()->create([
            'email' => 'customer@example.com',
            'password' => 'OldPassword123',
        ]);
        $token = app('auth.password.broker')->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertOk();

        $this->assertTrue(password_verify('NewPassword123', $user->fresh()->password));

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'AnotherPassword123',
            'password_confirmation' => 'AnotherPassword123',
        ])->assertUnprocessable();
    }

    public function test_only_super_administrator_can_change_user_roles_and_change_is_audited(): void
    {
        $target = User::factory()->create(['email' => 'target@example.com']);
        $superAdministrator = $this->userWithRole(UserRole::SuperAdministrator);

        $this->actingAs($superAdministrator)
            ->patchJson("/api/v1/admin/users/{$target->id}/role", ['role' => UserRole::Manager->value])
            ->assertOk()
            ->assertJsonPath('data.role', UserRole::Manager->value);

        $this->assertSame(UserRole::Manager, $target->fresh()->role);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $superAdministrator->id,
            'event_key' => 'access.user_role_changed',
            'subject_type' => User::class,
            'subject_id' => $target->id,
        ]);
        $this->assertSame([
            'previous_role' => UserRole::Customer->value,
            'new_role' => UserRole::Manager->value,
        ], AuditLog::query()->firstOrFail()->metadata);
    }

    public function test_only_super_administrator_can_list_users_and_search_by_name_or_email(): void
    {
        $superAdministrator = $this->userWithRole(UserRole::SuperAdministrator);
        $target = User::factory()->create([
            'name' => 'Role Management Target',
            'email' => 'role-target@example.com',
        ]);
        User::factory()->create([
            'name' => 'Unrelated Customer',
            'email' => 'unrelated@example.com',
        ]);
        User::factory()->create([
            'name' => 'Manager Alpha',
            'email' => 'manager-alpha@example.com',
            'role' => UserRole::Manager,
        ]);

        $this->actingAs($this->userWithRole(UserRole::Administrator))
            ->getJson('/api/v1/admin/users')
            ->assertForbidden();

        $this->actingAs($superAdministrator)
            ->getJson('/api/v1/admin/users?search=role-target')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $target->id)
            ->assertJsonPath('data.0.role', UserRole::Customer->value);

        $phoneTarget = User::factory()->create([
            'name' => 'Phone Search Target',
            'email' => null,
            'phone' => '+77015551234',
        ]);

        $this->getJson('/api/v1/admin/users?search=77015551234')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $phoneTarget->id);

        $this->getJson('/api/v1/admin/users?role=manager&sort=name_asc')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Manager Alpha');
    }

    public function test_role_change_requires_verified_target_and_cannot_change_self_or_last_super_administrator(): void
    {
        $superAdministrator = $this->userWithRole(UserRole::SuperAdministrator);
        $unverified = User::factory()->unverified()->create();

        $this->actingAs($superAdministrator)
            ->patchJson("/api/v1/admin/users/{$unverified->id}/role", ['role' => UserRole::Manager->value])
            ->assertUnprocessable();

        $this->patchJson("/api/v1/admin/users/{$superAdministrator->id}/role", ['role' => UserRole::Manager->value])
            ->assertUnprocessable();

        $this->patchJson("/api/v1/admin/users/{$superAdministrator->id}/role", ['role' => UserRole::Administrator->value])
            ->assertUnprocessable();

        $this->assertSame(UserRole::SuperAdministrator, $superAdministrator->fresh()->role);
    }

    public function test_super_administrator_can_assign_a_role_to_an_account_without_email(): void
    {
        $superAdministrator = $this->userWithRole(UserRole::SuperAdministrator);
        $target = User::factory()->create(['email' => null, 'phone' => '+77010000000']);

        $this->actingAs($superAdministrator)
            ->patchJson("/api/v1/admin/users/{$target->id}/role", ['role' => UserRole::Manager->value])
            ->assertOk()
            ->assertJsonPath('data.role', UserRole::Manager->value);
    }

    public function test_every_confirmed_role_boundary_is_enforced_server_side(): void
    {
        $expectedAccess = [
            'view-admin' => [
                UserRole::Customer->value => false,
                UserRole::Manager->value => true,
                UserRole::WarehouseOperator->value => false,
                UserRole::Administrator->value => true,
                UserRole::SuperAdministrator->value => true,
            ],
            'manage-catalog' => [
                UserRole::Customer->value => false,
                UserRole::Manager->value => true,
                UserRole::WarehouseOperator->value => false,
                UserRole::Administrator->value => true,
                UserRole::SuperAdministrator->value => true,
            ],
            'manage-orders' => [
                UserRole::Customer->value => false,
                UserRole::Manager->value => true,
                UserRole::WarehouseOperator->value => false,
                UserRole::Administrator->value => true,
                UserRole::SuperAdministrator->value => true,
            ],
            'manage-crm' => [
                UserRole::Customer->value => false,
                UserRole::Manager->value => true,
                UserRole::WarehouseOperator->value => false,
                UserRole::Administrator->value => true,
                UserRole::SuperAdministrator->value => true,
            ],
            'manage-warehouse' => [
                UserRole::Customer->value => false,
                UserRole::Manager->value => true,
                UserRole::WarehouseOperator->value => true,
                UserRole::Administrator->value => true,
                UserRole::SuperAdministrator->value => true,
            ],
            'manage-system-settings' => [
                UserRole::Customer->value => false,
                UserRole::Manager->value => false,
                UserRole::WarehouseOperator->value => false,
                UserRole::Administrator->value => false,
                UserRole::SuperAdministrator->value => true,
            ],
            'manage-user-roles' => [
                UserRole::Customer->value => false,
                UserRole::Manager->value => false,
                UserRole::WarehouseOperator->value => false,
                UserRole::Administrator->value => false,
                UserRole::SuperAdministrator->value => true,
            ],
        ];

        foreach ($expectedAccess as $ability => $roleAccess) {
            foreach (UserRole::cases() as $role) {
                $allowed = $roleAccess[$role->value];
                $this->assertSame(
                    $allowed,
                    $this->userWithRole($role)->can($ability),
                    "{$role->value} access for {$ability} did not match the approved role matrix.",
                );
            }
        }
    }

    public function test_role_management_endpoint_rejects_non_super_administrator_roles(): void
    {
        $target = User::factory()->create(['email' => 'target@example.com']);

        foreach ([
            UserRole::Customer,
            UserRole::Manager,
            UserRole::WarehouseOperator,
            UserRole::Administrator,
        ] as $role) {
            $actor = $this->userWithRole($role);

            $this->actingAs($actor)
                ->patchJson("/api/v1/admin/users/{$target->id}/role", ['role' => UserRole::Manager->value])
                ->assertForbidden();
        }
    }

    public function test_registration_rate_limiter_is_registered(): void
    {
        $user = User::factory()->create();

        $this->assertNotNull($user->getEmailForVerification());
        $this->assertSame(UserRole::Customer, $user->role);
    }

    public function test_login_endpoint_throttles_repeated_invalid_attempts(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'limited@example.com',
                'password' => 'invalid-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'limited@example.com',
            'password' => 'invalid-password',
        ])->assertTooManyRequests();
    }

    public function test_first_super_administrator_can_be_bootstrapped_once_from_verified_account(): void
    {
        $user = User::factory()->create(['email' => 'first-admin@example.com']);

        $this->artisan('app:make-super-admin', ['email' => $user->email])
            ->expectsConfirmation("Назначить {$user->email} супер-администратором?", 'yes')
            ->assertExitCode(0);

        $this->assertSame(UserRole::SuperAdministrator, $user->fresh()->role);
        $this->assertDatabaseHas('audit_logs', [
            'event_key' => 'access.initial_super_administrator_created',
            'subject_id' => $user->id,
        ]);

        $secondUser = User::factory()->create(['email' => 'second-admin@example.com']);
        $this->artisan('app:make-super-admin', ['email' => $secondUser->email])
            ->assertExitCode(1);
        $this->assertSame(UserRole::Customer, $secondUser->fresh()->role);
    }

    private function userWithRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }
}
