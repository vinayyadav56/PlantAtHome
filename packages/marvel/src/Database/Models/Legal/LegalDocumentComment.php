<?php

namespace Marvel\Database\Models\Legal;

use Illuminate\Database\Eloquent\Model;
use Marvel\Database\Models\User;

class LegalDocumentComment extends Model
{
    protected $table = 'legal_document_comments';
    protected $guarded = ['id'];
    protected $casts = ['selection' => 'array', 'resolved_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function replies()
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
