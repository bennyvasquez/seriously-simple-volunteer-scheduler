<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * The one organization this install serves. SSVS is one install per organization, so this table holds a single row.
 */
class Organization extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'timezone', 'weeks_ahead'];

    /** Matches the column default, so a new model has it before it's reloaded. */
    protected $attributes = ['weeks_ahead' => 8];

    protected function casts(): array
    {
        return ['weeks_ahead' => 'integer', 'required_shadow_shifts' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (Organization $organization) {
            if (! in_array($organization->timezone, DateTimeZone::listIdentifiers(), true)) {
                throw new InvalidArgumentException("Unknown time zone: {$organization->timezone}");
            }
        });

        static::creating(function () {
            if (static::query()->exists()) {
                throw new LogicException('This install already has an organization. SSVS serves one organization per install.');
            }
        });
    }

    public static function current(): self
    {
        return static::query()->firstOr(fn () => throw new RuntimeException('No organization is set up yet.'));
    }

    /** The start of today in the organization's own time zone. */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone)->startOfDay();
    }
}
