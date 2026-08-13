<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A registered Expo push token for one customer device. See ExpoPushService for sends.
 */
class DeviceToken extends Model
{
    protected $fillable = ['user_id', 'token', 'platform'];
}
