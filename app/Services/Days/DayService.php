<?php

namespace App\Services\Days;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Única porta de escrita do dia (RN23, RN26, RN27). */
class DayService
{
    public function __construct(private readonly DayMaterializer $days) {}

    /** RN23 — só o dia de hoje (São Paulo) muda. */
    public function assertEditable(CarbonImmutable $date): void
    {
        if (! $date->isToday()) {
            throw new DomainException(ErrorCode::DayNotEditable);
        }
    }

    /** RF13 — marcar ou desmarcar uma refeição. */
    public function setDone(User $user, CarbonImmutable $date, string $slot, bool $done): void
    {
        $this->assertEditable($date);
        $meal = $this->days->meals($user, $date)->firstWhere('slot', $slot) ?? throw new NotFoundHttpException;

        $meal->update(['done_at' => $done ? ($meal->done_at ?? now()) : null]);
    }
}
