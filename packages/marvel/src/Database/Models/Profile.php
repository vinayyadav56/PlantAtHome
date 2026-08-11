<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Http\Rules\UniquePhone;

class Profile extends Model
{
    protected $table = 'user_profiles';

    public $guarded = [];

    protected $casts = [
        'socials' => 'json',
        'avatar' => 'json',
        'notifications' => 'json',
    ];

    protected static function booted(): void
    {
        // contact arrives in whatever format the client sent; contact_clean
        // (last 10 digits, same normalization as UniquePhone) is the
        // deterministic lookup key OTP login matches on. Bare 10-digit
        // contacts are stored E.164 so new rows have ONE canonical format.
        static::saving(function (Profile $profile) {
            // Deploy-lag guard: skip while the column hasn't been migrated yet.
            try {
                if (!\Illuminate\Support\Facades\Schema::hasColumn('user_profiles', 'contact_clean')) {
                    unset($profile->contact_clean);
                    return;
                }
            } catch (\Throwable $e) {
                return;
            }
            if ($profile->isDirty('contact') || ($profile->contact && !$profile->contact_clean)) {
                $profile->contact_clean = UniquePhone::normalize($profile->contact);
                if (preg_match('/^[0-9]{10}$/', (string) $profile->contact)) {
                    $profile->contact = '+91' . $profile->contact;
                }
            }
        });
    }

    /**
     * @return BelongsTo
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}
