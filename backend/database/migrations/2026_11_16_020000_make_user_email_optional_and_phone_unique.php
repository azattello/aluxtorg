<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Support\PhoneNumber;

return new class extends Migration
{
    public function up(): void
    {
        $phones = DB::table('users')->whereNotNull('phone')->get(['id', 'phone']);
        $normalizedPhones = [];
        $phoneUpdates = [];

        foreach ($phones as $user) {
            $normalized = PhoneNumber::normalize($user->phone);
            if (! PhoneNumber::isValid($normalized)) {
                continue;
            }

            if (isset($normalizedPhones[$normalized])) {
                throw new RuntimeException('Невозможно включить уникальность телефонов: после нормализации обнаружены дубликаты.');
            }

            $normalizedPhones[$normalized] = true;
            if ($normalized !== $user->phone) {
                $phoneUpdates[] = ['id' => $user->id, 'phone' => $normalized];
            }
        }

        $duplicatePhones = DB::table('users')
            ->select('phone')
            ->whereNotNull('phone')
            ->groupBy('phone')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicatePhones) {
            throw new RuntimeException('Невозможно включить уникальность телефонов: в users есть дубликаты.');
        }

        foreach ($phoneUpdates as $update) {
            DB::table('users')->where('id', $update['id'])->update(['phone' => $update['phone']]);
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
            $table->unique('phone');
        });
    }

    public function down(): void
    {
        if (DB::table('users')->whereNull('email')->exists()) {
            throw new RuntimeException('Нельзя сделать email обязательным, пока в users есть аккаунты без email.');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['phone']);
            $table->string('email')->nullable(false)->change();
        });
    }
};
