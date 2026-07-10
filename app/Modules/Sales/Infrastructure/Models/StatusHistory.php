<?php

namespace App\Modules\Sales\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string      $entity_type
 * @property string      $entity_uuid
 * @property string|null $from_status
 * @property string      $to_status
 */
class StatusHistory extends Model
{
    protected $table = 'sales_status_history';

    protected $fillable = ['entity_type', 'entity_uuid', 'from_status', 'to_status', 'actor', 'at'];

    protected $casts = ['at' => 'datetime'];
}
