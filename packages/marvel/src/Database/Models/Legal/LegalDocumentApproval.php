<?php

namespace Marvel\Database\Models\Legal;

use Illuminate\Database\Eloquent\Model;
use Marvel\Database\Models\User;

class LegalDocumentApproval extends Model
{
    protected $table = 'legal_document_approvals';
    protected $guarded = ['id'];
    public $timestamps = false;
    protected $casts = ['requested_at' => 'datetime', 'acted_at' => 'datetime'];

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
