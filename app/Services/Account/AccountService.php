<?php

namespace App\Services\Account;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class AccountService
{
    /**
     * RF01 — usuário + perfil vazio + configurações padrão, tudo ou nada.
     *
     * @param  array{name: string, email: string, password: string, terms_version: string}  $data
     */
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'consented_at' => now(),
                'terms_version' => $data['terms_version'],
            ]);

            $user->setRelation('profile', $user->profile()->create());
            $user->settings()->create();

            return $user;
        });
    }

    /** RN06 — apaga o usuário e tudo dele. As FKs em cascata cuidam das tabelas de domínio. */
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->endSessions();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->delete();
        });
    }
}
