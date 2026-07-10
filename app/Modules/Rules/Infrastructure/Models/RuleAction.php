<?php

namespace App\Modules\Rules\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property int    $rule_id
 * @property string $type
 * @property array  $params
 */
class RuleAction extends Model
{
    protected $table = 'rule_actions';

    protected $fillable = ['rule_id', 'type', 'params', 'sort'];

    protected $casts = [
        'params' => 'array',
        'sort'   => 'integer',
    ];
}
