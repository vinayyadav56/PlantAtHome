<?php

namespace Marvel\Database\Models\Legal;

use Illuminate\Database\Eloquent\Model;

class LegalDocumentType extends Model
{
    protected $table = 'legal_document_types';
    protected $guarded = ['id'];
    protected $casts = ['is_active' => 'bool'];
}
