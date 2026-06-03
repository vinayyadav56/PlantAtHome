<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GardenPackageVisit extends Model
{
    protected $table = 'garden_package_visits';
    protected $fillable = [
        'garden_package_id', 'scheduled_date', 'completed_at',
        'gardener_name', 'notes', 'status',
    ];
    protected $casts = [
        'scheduled_date' => 'date',
        'completed_at' => 'datetime',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(GardenPackage::class, 'garden_package_id');
    }
}
