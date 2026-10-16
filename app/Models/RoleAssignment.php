<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One role held by one person. Area leads hold it for one area; other roles have no area.
 */
class RoleAssignment extends Model
{
    protected $fillable = ['user_id', 'role', 'area_id'];

    protected function casts(): array
    {
        return ['role' => Role::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }
}
