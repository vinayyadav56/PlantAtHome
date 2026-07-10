<?php

namespace App\Modules\Rules\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property int    $rule_id
 * @property string $fact
 * @property string $operator
 * @property mixed  $value
 */
class RuleCondition extends Model
{
    protected $table = 'rule_conditions';

    protected $fillable = ['rule_id', 'fact', 'operator', 'value'];

    protected $casts = ['value' => 'array']; // wrapped {v:…} so scalars survive json

    /** The comparison value, unwrapped from its {v:…} json envelope. */
    public function rawValue(): mixed
    {
        return is_array($this->value) && array_key_exists('v', $this->value)
            ? $this->value['v']
            : $this->value;
    }
}

