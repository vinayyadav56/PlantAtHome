<?php

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int         $id
 * @property string      $uuid
 * @property string      $code
 * @property string      $type
 * @property string      $scope
 * @property int|null    $category_id
 */
class Attribute extends Model
{
    use HasUuid;
    use SoftDeletes;

    protected $table = 'catalog_attributes';

    protected $fillable = [
        'uuid', 'code', 'name', 'type', 'scope', 'category_id', 'options',
        'created_by', 'updated_by',
    ];

    protected $casts = ['options' => 'array'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
