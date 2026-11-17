<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MakeSuperAdministrator extends Command
{
    protected $signature = 'app:make-super-admin {email : Email существующего подтверждённого аккаунта}';

    protected $description = 'Назначить первого супер-администратора для новой установки';

    public function handle(): int
    {
        $user = User::query()->where('email', mb_strtolower($this->argument('email')))->first();

        if (! $user) {
            $this->error('Аккаунт с таким email не найден. Сначала зарегистрируйтесь и подтвердите почту.');

            return self::FAILURE;
        }

        if (! $user->hasVerifiedEmail()) {
            $this->error('Для назначения этой роли необходимо подтвердить email аккаунта.');

            return self::FAILURE;
        }

        if (User::query()->where('role', UserRole::SuperAdministrator->value)->exists()) {
            $this->error('Супер-администратор уже существует. Используйте защищённый интерфейс управления ролями.');

            return self::FAILURE;
        }

        if (! $this->confirm("Назначить {$user->email} супер-администратором?")) {
            $this->comment('Изменения не внесены.');

            return self::SUCCESS;
        }

        $promoted = DB::transaction(function () use ($user): bool {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(731942850143)');
            }

            $existing = User::query()
                ->where('role', UserRole::SuperAdministrator->value)
                ->lockForUpdate()
                ->exists();

            if ($existing) {
                return false;
            }

            $user->role = UserRole::SuperAdministrator;
            $user->save();
            AuditLog::query()->create([
                'actor_user_id' => null,
                'event_key' => 'access.initial_super_administrator_created',
                'subject_type' => User::class,
                'subject_id' => $user->getKey(),
                'metadata' => ['new_role' => UserRole::SuperAdministrator->value],
            ]);

            return true;
        });

        if (! $promoted) {
            $this->error('Супер-администратор был создан одновременно другим запросом. Изменения не внесены.');

            return self::FAILURE;
        }

        $this->info("{$user->email} назначен первым супер-администратором.");

        return self::SUCCESS;
    }
}
