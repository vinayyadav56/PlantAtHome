<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

class AdminTask extends Model
{
    protected $table = 'admin_tasks';
    protected $fillable = ['title', 'description', 'category', 'priority', 'status', 'completed_at'];
    protected $casts = ['completed_at' => 'datetime'];
}
