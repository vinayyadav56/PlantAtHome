<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GardenPackage extends Model
{
    protected $table = 'garden_packages';
    protected $fillable = [
        'user_id', 'lead_id', 'name', 'description', 'items', 'service',
        'total_visits', 'price', 'duration_days', 'start_date', 'end_date',
        'status', 'payment_status', 'razorpay_link_id', 'razorpay_link_url', 'razorpay_payment_id',
    ];
    protected $casts = [
        'items' => 'array',
        'total_visits' => 'int',
        'price' => 'float',
        'duration_days' => 'int',
        'start_date' => 'date',
        'end_date' => 'date',
    ];
    protected $appends = ['visits_used', 'visits_left'];

    public function visits(): HasMany
    {
        return $this->hasMany(GardenPackageVisit::class, 'garden_package_id')->orderBy('scheduled_date');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getVisitsUsedAttribute(): int
    {
        return $this->visits()->where('status', 'completed')->count();
    }

    public function getVisitsLeftAttribute(): int
    {
        return max(0, (int) $this->total_visits - $this->visits_used);
    }
}
