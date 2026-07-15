<?php

namespace App\Modules\Nursery\Http\Resources;

use App\Modules\Nursery\Infrastructure\Models\Nursery;
use App\Modules\Nursery\Infrastructure\Models\NurseryDocument;
use Illuminate\Support\Facades\DB;

/**
 * Wire shape of a nursery — the admin frontend builds against this exactly.
 * Never the internal id; legacy_id IS exposed (the admin mapper needs it).
 * Decimals cross the wire as floats.
 */
final class NurseryResource
{
    public static function make(Nursery $nursery, bool $withDetails = false): array
    {
        $balance = $nursery->balance;

        $data = [
            'uuid'            => $nursery->uuid,
            'legacy_id'       => $nursery->legacy_id,
            'name'            => $nursery->name,
            'slug'            => $nursery->slug,
            'description'     => $nursery->description,
            'status'          => $nursery->status,
            'commission_rate' => $nursery->commission_rate !== null ? (float) $nursery->commission_rate : null,
            'logo'            => $nursery->logo,
            'cover_image'     => $nursery->cover_image,
            'address'         => $nursery->address,
            'settings'        => $nursery->settings,
            'contact_person'  => $nursery->contact_person,
            'mobile'          => $nursery->mobile,
            'upi'             => $nursery->upi,
            'lat'             => $nursery->lat !== null ? (float) $nursery->lat : null,
            'lng'             => $nursery->lng !== null ? (float) $nursery->lng : null,
            'categories'      => $nursery->category_ids ?? [],
            'service_areas'   => $nursery->service_areas ?? [],
            'gst_number'      => $nursery->gst_number,
            'owner'           => self::owner($nursery),
            'balance'         => $balance ? [
                'total_earnings'   => (float) $balance->total_earnings,
                'withdrawn_amount' => (float) $balance->withdrawn_amount,
                'current_balance'  => (float) $balance->current_balance,
                'payment_info'     => $balance->payment_info,
            ] : null,
            'created_at'      => $nursery->created_at?->toIso8601String(),
            'updated_at'      => $nursery->updated_at?->toIso8601String(),
        ];

        if ($withDetails) {
            $data['documents'] = $nursery->documents->map(fn (NurseryDocument $d) => [
                'uuid'        => $d->uuid,
                'kind'        => $d->kind,
                'file'        => $d->file,
                'status'      => $d->status,
                'note'        => $d->note,
                'reviewed_at' => $d->reviewed_at?->toIso8601String(),
            ])->values()->all();
        }

        return $data;
    }

    /** Owner summary, joined from Identity by uuid (no cross-module FK). */
    private static function owner(Nursery $nursery): ?array
    {
        if (! $nursery->owner_user_uuid) {
            return null;
        }

        $owner = DB::table('identity_users')
            ->where('uuid', $nursery->owner_user_uuid)
            ->select('uuid', 'name', 'email')
            ->first();

        return $owner ? ['uuid' => $owner->uuid, 'name' => $owner->name, 'email' => $owner->email] : null;
    }
}
