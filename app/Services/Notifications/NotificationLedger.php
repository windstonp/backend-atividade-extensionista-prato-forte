<?php

namespace App\Services\Notifications;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Registro do que já foi enviado (`sent_notifications`): trava anti-duplicata e contagens. */
final class NotificationLedger
{
    /** true só para quem reservou primeiro (duas execuções ao mesmo tempo: uma ganha). */
    public function claim(User $user, string $type, string $reference, ?string $rule = null): bool
    {
        return DB::table('sent_notifications')->insertOrIgnore([
            'user_id' => $user->id, 'type' => $type, 'reference' => $reference, 'rule' => $rule, 'sent_at' => now(),
        ]) === 1;
    }

    public function countSince(User $user, string $type, CarbonImmutable $since): int
    {
        return DB::table('sent_notifications')->where('user_id', $user->id)->where('type', $type)->where('sent_at', '>=', $since)->count();
    }

    public function ruleSentSince(User $user, string $rule, CarbonImmutable $since): bool
    {
        return DB::table('sent_notifications')->where('user_id', $user->id)->where('rule', $rule)->where('sent_at', '>=', $since)->exists();
    }
}
