<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'consented_at', 'terms_version'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'consented_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * RN01 — e-mail sempre minúsculo e sem espaços nas pontas.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => Str::lower(trim($value)));
    }

    /** @return HasOne<Profile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /** @return HasOne<UserSetting, $this> */
    public function settings(): HasOne
    {
        return $this->hasOne(UserSetting::class);
    }

    /**
     * Restrições e alergias marcadas.

     *

     * @return BelongsToMany<Restriction, $this>
     */
    public function restrictions(): BelongsToMany
    {
        return $this->belongsToMany(Restriction::class)->orderBy('position');
    }

    /**
     * Itens da cozinha.

     *

     * @return BelongsToMany<PantryItem, $this>
     */
    public function pantryItems(): BelongsToMany
    {
        return $this->belongsToMany(PantryItem::class)->orderBy('position');
    }

    /**
     * "Prefiro não ver no cardápio" (só alimentos `common_dislike`).

     *

     * @return BelongsToMany<Food, $this>
     */
    public function dislikedFoods(): BelongsToMany
    {
        return $this->belongsToMany(Food::class, 'disliked_food_user')->orderBy('name');
    }

    /** @return HasMany<WeighIn, $this> */
    public function weighIns(): HasMany
    {
        return $this->hasMany(WeighIn::class);
    }

    /** @return HasOne<WeighIn, $this> */
    public function latestWeighIn(): HasOne
    {
        return $this->hasOne(WeighIn::class)->latestOfMany('date');
    }

    /** RN34: peso atual = pesagem mais recente; antes da primeira, o peso informado no onboarding. */
    public function currentWeightKg(): ?float
    {
        $latest = $this->latestWeighIn;
        if ($latest !== null) {
            return $latest->weight_kg;
        }

        $start = $this->profile?->start_weight_kg;

        return $start === null ? null : (float) $start;
    }

    /** Usa o e-mail pt-BR com link para o front, no lugar do padrão do Laravel. */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /** Derruba as sessões abertas do usuário (RN05, RN06, RF04), poupando `$except` se vier. */
    public function endSessions(?string $except = null): void
    {
        DB::table('sessions')
            ->where('user_id', $this->id)
            ->when($except, fn ($query) => $query->where('id', '!=', $except))
            ->delete();
    }
}
