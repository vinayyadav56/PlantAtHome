<?php

namespace Marvel\Database\Models\Legal;

use Illuminate\Database\Eloquent\Model;
use Marvel\Database\Models\Legal\Concerns\HasLegalUuid;
use Marvel\Database\Models\User;

class LegalDocumentVersion extends Model
{
    use HasLegalUuid;

    protected $table = 'legal_document_versions';
    // status is workflow-controlled: only LegalWorkflow may move it (forceFill).
    protected $guarded = ['id', 'status'];
    protected $casts = [
        'attachments' => 'array',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(LegalDocument::class, 'document_id');
    }

    public function comments()
    {
        return $this->hasMany(LegalDocumentComment::class, 'version_id');
    }

    public function approvals()
    {
        return $this->hasMany(LegalDocumentApproval::class, 'version_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function versionLabel(): string
    {
        return $this->version_major . '.' . $this->version_minor;
    }
}
