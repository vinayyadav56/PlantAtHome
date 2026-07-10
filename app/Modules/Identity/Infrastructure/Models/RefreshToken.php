<?php

namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int         $id
 * @property string      $uuid
 * @property int         $user_id
 * @property string      $token_hash
 * @property Carbon      $expires_at
 * @property Carbon|null $revoked_at
 * @property string|null $replaced_by
 */
class RefreshToken extends Model
{
    protected $table = 'identity_refresh_tokens';

    protected $fillable = [
        'uuid', 'user_id', 'token_hash', 'expires_at', 'revoked_at', 'replaced_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(IdentityUser::class, 'user_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
