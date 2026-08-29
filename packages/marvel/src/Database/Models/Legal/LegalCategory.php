<?php

namespace Marvel\Database\Models\Legal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Marvel\Database\Models\Legal\Concerns\HasLegalUuid;

class LegalCategory extends Model
{
    use HasLegalUuid;
    use SoftDeletes;

    protected $table = 'legal_categories';
    protected $guarded = ['id'];
    protected $casts = ['is_active' => 'bool'];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function documents()
    {
        return $this->hasMany(LegalDocument::class, 'category_id');
    }
}
