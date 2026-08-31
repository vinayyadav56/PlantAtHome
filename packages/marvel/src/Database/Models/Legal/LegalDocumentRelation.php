<?php

namespace Marvel\Database\Models\Legal;

use Illuminate\Database\Eloquent\Model;

class LegalDocumentRelation extends Model
{
    protected $table = 'legal_document_relations';
    protected $guarded = ['id'];

    public function document()
    {
        return $this->belongsTo(LegalDocument::class, 'document_id');
    }

    public function related()
    {
        return $this->belongsTo(LegalDocument::class, 'related_document_id');
    }
}
