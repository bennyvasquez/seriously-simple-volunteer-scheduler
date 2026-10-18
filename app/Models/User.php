<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /** Fields that identify a person; the change log scrubs these when an account is removed. Phone joins when phone numbers are built. */
    public const PERSONAL_FIELDS = ['name', 'email'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'deactivated_at' => 'datetime',
            'password_reset_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function roles(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    public function regulars(): HasMany
    {
        return $this->hasMany(SlotRegular::class);
    }

    public function loginLinks(): HasMany
    {
        return $this->hasMany(LoginLink::class);
    }

    /** Sent after the response, so asking for a reset takes the same time whether or not the address has an account. A turned-off account gets no email, and the answer on screen stays the same. */
    public function sendPasswordResetNotification($token): void
    {
        if (! $this->isActive()) {
            return;
        }

        defer(fn () => $this->notify(new ResetPassword($token)));
    }

    public function hasRole(Role $role, ?Area $area = null): bool
    {
        return $this->roles()
            ->where('role', $role->value)
            ->when($area !== null, fn ($query) => $query->where('area_id', $area->id))
            ->exists();
    }

    /** Admins can do everything a coordinator can. */
    public function isCoordinator(): bool
    {
        return $this->roles()->whereIn('role', [Role::Admin->value, Role::Coordinator->value])->exists();
    }

    /** The ids of the areas this person leads. */
    public function ledAreaIds(): Collection
    {
        return $this->roles()->where('role', Role::AreaLead->value)->whereNotNull('area_id')->pluck('area_id');
    }

    /** Anyone with a role can open the coordinator calendar; a turned-off account cannot. */
    public function canViewCalendar(): bool
    {
        return $this->isActive() && $this->roles()->exists();
    }

    /** Coordinators and admins manage every area; a lead manages only the areas they lead. */
    public function canManageArea(Area $area): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        return $this->isCoordinator() || $this->hasRole(Role::AreaLead, $area);
    }

    /** Every role can see volunteers' contact details (leads for their areas), so anyone with a role needs two-factor (spec section 13). */
    public function requiresTwoFactor(): bool
    {
        return $this->roles()->exists();
    }

    /** The first word of the name, for places that show a person to someone who can't see their full name. */
    public function firstName(): string
    {
        return explode(' ', trim($this->name))[0];
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    /** Admins can grant any role; coordinators can grant area lead. */
    public function canGrant(Role $role): bool
    {
        if ($this->hasRole(Role::Admin)) {
            return true;
        }

        return $role === Role::AreaLead && $this->hasRole(Role::Coordinator);
    }

    /**
     * The people this person may see on the users page. Coordinators and admins see everyone. Area leads see anyone with a shift or a regular slot in one of their areas, past or future, and themselves. Everyone else sees only themselves. Deactivated people are included so the page can filter them, and the query has no order.
     *
     * @return Builder<User>
     */
    public function visiblePeople(): Builder
    {
        if ($this->isCoordinator()) {
            return static::query();
        }

        $areaIds = $this->roles()->where('role', Role::AreaLead->value)->pluck('area_id');

        return static::query()->where(fn (Builder $query) => $query
            ->whereKey($this->id)
            ->when($areaIds->isNotEmpty(), fn (Builder $query) => $query
                ->orWhereHas('assignments.shift', fn ($shift) => $shift->whereIn('area_id', $areaIds))
                ->orWhereHas('regulars.weeklySlot', fn ($slot) => $slot->whereIn('area_id', $areaIds))));
    }

    public function canSee(User $other): bool
    {
        return $this->visiblePeople()->whereKey($other->id)->exists();
    }
}
