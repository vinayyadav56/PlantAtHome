<?php

namespace Marvel\Database\Models\Legal;

use Illuminate\Database\Eloquent\Model;
use Marvel\Database\Models\Legal\Concerns\HasLegalUuid;

class LegalTemplate extends Model
{
    use HasLegalUuid;

    protected $table = 'legal_templates';
    protected $guarded = ['id'];
    protected $casts = ['default_metadata' => 'array', 'is_active' => 'bool'];
}
