<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

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
        ];
    }

    public function roles(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    public function loginLinks(): HasMany
    {
        return $this->hasMany(LoginLink::class);
    }

    /** Sent after the response, so asking for a reset takes the same time whether or not the address has an account. */
    public function sendPasswordResetNotification($token): void
    {
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

    /** Every role can see volunteers' contact details (leads for their areas), so anyone with a role needs two-factor (spec section 13). */
    public function requiresTwoFactor(): bool
    {
        return $this->roles()->exists();
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
}
