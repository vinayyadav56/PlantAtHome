<?php

namespace App\Modules\Serviceability\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only coverage audit row (rule_added | rule_removed | sync).
 * created_at only — audit rows are never updated.
 *
 * @property int    $shop_id
 * @property string $action
 */
class CoverageAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'coverage_audit_logs';

    protected $fillable = ['shop_id', 'user_id', 'action', 'payload', 'created_at'];

    protected $casts = ['payload' => 'array'];
}
