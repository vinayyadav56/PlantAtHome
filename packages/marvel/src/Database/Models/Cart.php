<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    protected $table = 'carts';

    protected $fillable = ['user_id', 'items', 'shopping_city_id', 'shopping_city'];

    protected $casts = [
        'items' => 'array',
    ];
}
