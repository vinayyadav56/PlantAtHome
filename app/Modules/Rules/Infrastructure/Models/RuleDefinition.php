<?php

namespace App\Modules\Rules\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int    $id
 * @property string $uuid
 * @property string $name
 * @property string $scope
 * @property int    $priority
 * @property string $condition_combinator
 * @property bool   $is_active
 */
class RuleDefinition extends Model
{
    use HasUuid;
    use SoftDeletes;

    protected $table = 'rule_definitions';

    protected $fillable = [
        'uuid', 'name', 'scope', 'priority', 'condition_combinator',
        'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'priority'  => 'integer',
        'is_active' => 'boolean',
    ];

    public function conditions(): HasMany
    {
        return $this->hasMany(RuleCondition::class, 'rule_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(RuleAction::class, 'rule_id')->orderBy('sort');
    }
}
