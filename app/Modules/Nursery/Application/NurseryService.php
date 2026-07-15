<?php

namespace App\Modules\Nursery\Application;

use App\Modules\Identity\Domain\RoleName;
use App\Modules\Nursery\Domain\Events\CommissionChanged;
use App\Modules\Nursery\Domain\Events\NurseryApproved;
use App\Modules\Nursery\Domain\Events\NurseryCreated;
use App\Modules\Nursery\Domain\Events\NurseryUpdated;
use App\Modules\Nursery\Domain\Events\OwnershipTransferred;
use App\Modules\Nursery\Domain\NurseryStatus;
use App\Modules\Nursery\Infrastructure\Models\Nursery;
use App\Modules\Nursery\Infrastructure\Models\NurseryBalance;
use App\Modules\Nursery\Infrastructure\Models\NurseryDocument;
use App\Shared\Application\DomainActionException;
use App\Shared\Events\EventPublisher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Marvel\Mail\VendorCredentials;

/**
 * Nursery lifecycle use-cases. Every mutation runs in a transaction and
 * publishes through the outbox, so the legacy `shops` projection sees a change
 * iff it committed. Approve is idempotent (already active → no-op).
 */
class NurseryService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly EventPublisher $events,
    ) {
    }

    /**
     * Create a nursery in `pending` with its balance row, the owner login
     * (identity + legacy), and the legacy `shops` projection — all in ONE
     * transaction so a vendor either exists everywhere or nowhere. The
     * credentials email goes out post-commit (never inside the transaction).
     */
    public function create(array $data, ?string $actorUuid): Nursery
    {
        $ownerCreated = false;
        $owner = null;

        $nursery = $this->db->transaction(function () use ($data, $actorUuid, &$ownerCreated, &$owner) {
            $nursery = Nursery::create([
                'name'            => $data['name'],
                'slug'            => $this->resolveSlug($data),
                'description'     => $data['description'] ?? null,
                'logo'            => $data['logo'] ?? null,
                'cover_image'     => $data['cover_image'] ?? null,
                'address'         => $data['address'] ?? null,
                'settings'        => $data['settings'] ?? null,
                'commission_rate' => $data['commission_rate'] ?? null,
                'status'          => NurseryStatus::PENDING,
                'contact_person'  => $data['contact_person'] ?? null,
                'mobile'          => $data['mobile'] ?? null,
                'upi'             => $data['upi'] ?? null,
                'lat'             => $data['lat'] ?? null,
                'lng'             => $data['lng'] ?? null,
                'category_ids'    => $data['categories'] ?? null,
                'service_areas'   => $data['service_areas'] ?? null,
                'gst_number'      => $this->gstFromSettings($data),
            ]);

            NurseryBalance::create([
                'nursery_id'   => $nursery->id,
                'payment_info' => $data['balance']['payment_info'] ?? null,
            ]);

            if (! empty($data['owner_email'])) {
                [$owner, $ownerCreated] = $this->upsertIdentityOwner($nursery, $data);
            }

            $legacyId = $this->projectCreateToLegacyShop($nursery, $data, $owner, $actorUuid);
            if ($legacyId !== null) {
                $nursery->legacy_id = $legacyId;
                $nursery->save();
            }

            $this->events->publish(new NurseryCreated($nursery->uuid, $nursery->legacy_id));

            return $nursery;
        });

        // The admin typed the password, so mail it to the new owner AFTER commit —
        // a mail failure must never roll back (or fail) the vendor create.
        // Guard on a usable mail config (a mailer + a from address).
        $sent = false;
        if ($ownerCreated && ! empty($data['owner_password']) && config('mail.default') && config('mail.from.address')) {
            try {
                Mail::to($data['owner_email'])->send(new VendorCredentials(
                    $data['owner_email'],
                    $data['owner_password'],
                    $nursery->name,
                    $owner->name,
                    rtrim((string) (config('shop.dashboard_url') ?: ''), '/'),
                ));
                $sent = true;
            } catch (\Throwable) {
                $sent = false;
            }
        }
        $nursery->setAttribute('credentials_email_sent', $sent);

        return $nursery;
    }

    public function update(Nursery $nursery, array $data, ?string $actorUuid = null): Nursery
    {
        return $this->db->transaction(function () use ($nursery, $data, $actorUuid) {
            if (array_key_exists('categories', $data)) {
                $data['category_ids'] = $data['categories'];
            }
            if (array_key_exists('settings', $data)) {
                $data['gst_number'] = $this->gstFromSettings($data);
            }

            $nursery->fill($data)->save();

            // Only the operator-editable payment_info — never balance amounts.
            if (isset($data['balance']['payment_info']) && $nursery->balance) {
                $nursery->balance->payment_info = $data['balance']['payment_info'];
                $nursery->balance->save();
            }

            $this->events->publish($this->updatedEvent($nursery, $actorUuid));

            return $nursery;
        });
    }

    /** Auto-slug from the name when the form sends none; -2/-3… on collision. */
    private function resolveSlug(array $data): string
    {
        $slug = trim((string) ($data['slug'] ?? ''));
        if ($slug !== '') {
            return $slug;
        }

        $base = Str::slug($data['name']) ?: 'nursery';
        $slug = $base;
        // withTrashed: the unique index still covers soft-deleted rows.
        for ($i = 2; Nursery::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    /** Mirror settings.compliance.gst into the gst_number column (legacy parity). */
    private function gstFromSettings(array $data): ?string
    {
        $gst = $data['settings']['compliance']['gst'] ?? null;
        $gst = is_string($gst) ? strtoupper(trim($gst)) : null;

        return $gst !== null && $gst !== '' ? $gst : null;
    }

    /**
     * Find-or-create the identity login for the vendor owner and scope it to
     * the nursery. Returns [owner row, wasCreated].
     *
     * @return array{0:object,1:bool}
     */
    private function upsertIdentityOwner(Nursery $nursery, array $data): array
    {
        $email = $data['owner_email'];
        $owner = $this->db->table('identity_users')->where('email', $email)->first();
        $created = false;

        if (! $owner) {
            if (empty($data['owner_password'])) {
                throw DomainActionException::unprocessable(
                    'An owner password is required to create a new vendor login.',
                    'OWNER_PASSWORD_REQUIRED',
                    'owner_password',
                );
            }

            $roleId = $this->db->table('identity_roles')->where('name', RoleName::NURSERY_OWNER)->value('id');
            $now = Carbon::now();
            $this->db->table('identity_users')->insert([
                'uuid'              => (string) Str::uuid(),
                'name'              => $data['owner_name'] ?? $email,
                'email'             => $email,
                'password'          => Hash::make($data['owner_password']),
                'role_id'           => $roleId,
                'is_active'         => true,
                'email_verified_at' => $now,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
            $owner = $this->db->table('identity_users')->where('email', $email)->first();
            $created = true;
        }

        $nursery->owner_user_uuid = $owner->uuid;
        $nursery->save();

        $this->db->table('identity_users')
            ->where('uuid', $owner->uuid)
            ->update(['nursery_id' => $nursery->uuid]);

        return [$owner, $created];
    }

    /**
     * Inline legacy projection (same DB, additive inserts): `users` (+ spatie
     * grants), `shops`, `balances`, `category_shop`, `vendor_service_areas` —
     * so the storefront sees the vendor immediately. Column/table existence is
     * checked (staging/prod schema drift); returns the new shops.id, or null
     * when the legacy tables are absent.
     */
    private function projectCreateToLegacyShop(Nursery $nursery, array $data, ?object $owner, ?string $actorUuid): ?int
    {
        $schema = $this->db->getSchemaBuilder();
        if (! $schema->hasTable('shops')) {
            return null;
        }

        $now = Carbon::now();

        $legacyOwnerId = null;
        if ($owner && $schema->hasTable('users')) {
            $legacyOwnerId = $this->db->table('users')->where('email', $owner->email)->value('id');
            if ($legacyOwnerId === null) {
                // Same bcrypt hash as the identity user → one password everywhere.
                $legacyOwnerId = $this->db->table('users')->insertGetId([
                    'name'              => $owner->name,
                    'email'             => $owner->email,
                    'password'          => $owner->password,
                    'is_active'         => 1,
                    'email_verified_at' => $now,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]);
                $this->grantLegacyPermissions($legacyOwnerId);
            }
        }

        if ($legacyOwnerId === null && $actorUuid && $schema->hasTable('users')) {
            // Fallback owner: the acting admin's legacy account (matched by email).
            $actorEmail = $this->db->table('identity_users')->where('uuid', $actorUuid)->value('email');
            if ($actorEmail) {
                $legacyOwnerId = $this->db->table('users')->where('email', $actorEmail)->value('id');
            }
        }

        $json = fn ($value) => $value !== null ? json_encode($value) : null;
        $shop = [
            'owner_id'    => $legacyOwnerId,
            'name'        => $nursery->name,
            'slug'        => $nursery->slug,
            'description' => $nursery->description,
            'cover_image' => $json($data['cover_image'] ?? null),
            'logo'        => $json($data['logo'] ?? null),
            'is_active'   => 0,
            'address'     => $json($data['address'] ?? null),
            'settings'    => $json($data['settings'] ?? null),
            'created_at'  => $now,
            'updated_at'  => $now,
        ];
        foreach (['contact_person', 'mobile', 'upi', 'lat', 'lng', 'gst_number'] as $column) {
            if ($schema->hasColumn('shops', $column)) {
                $shop[$column] = $nursery->{$column};
            }
        }
        $shopId = $this->db->table('shops')->insertGetId($shop);

        if (isset($data['balance']['payment_info']) && $schema->hasTable('balances')) {
            $this->db->table('balances')->insert([
                'shop_id'      => $shopId,
                'payment_info' => json_encode($data['balance']['payment_info']),
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }

        if (! empty($data['categories']) && $schema->hasTable('category_shop')) {
            $this->db->table('category_shop')->insert(array_map(
                fn ($categoryId) => ['category_id' => (int) $categoryId, 'shop_id' => $shopId],
                $data['categories'],
            ));
        }

        if (! empty($data['service_areas']) && $schema->hasTable('vendor_service_areas')) {
            $this->insertLegacyServiceAreas($shopId, $data['service_areas'], $now);
        }

        return $shopId;
    }

    /** Spatie grants for a fresh vendor login; missing permission names are skipped. */
    private function grantLegacyPermissions(int $legacyUserId): void
    {
        $schema = $this->db->getSchemaBuilder();
        if (! $schema->hasTable('permissions') || ! $schema->hasTable('model_has_permissions')) {
            return;
        }

        $permissionIds = $this->db->table('permissions')
            ->whereIn('name', ['store_owner', 'customer'])
            ->pluck('id');

        foreach ($permissionIds as $permissionId) {
            $this->db->table('model_has_permissions')->insert([
                'permission_id' => $permissionId,
                'model_type'    => 'Marvel\\Database\\Models\\User',
                'model_id'      => $legacyUserId,
            ]);
        }
    }

    /** Legacy vendor_service_areas rows, normalised like ShopRepository::syncServiceAreas. */
    private function insertLegacyServiceAreas(int $shopId, array $areas, Carbon $now): void
    {
        $seen = [];
        foreach ($areas as $area) {
            $city = trim((string) ($area['city'] ?? ''));
            if ($city === '') {
                continue;
            }
            $pincode = isset($area['pincode']) && trim((string) $area['pincode']) !== '' ? trim((string) $area['pincode']) : null;
            $key = strtolower($city).'|'.($pincode ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $mode = $area['fulfillment_mode'] ?? 'local';

            $this->db->table('vendor_service_areas')->insert([
                'shop_id'          => $shopId,
                'city'             => $city,
                'pincode'          => $pincode,
                'fulfillment_mode' => in_array($mode, ['local', 'courier', 'both'], true) ? $mode : 'local',
                'eta_days'         => isset($area['eta_days']) && $area['eta_days'] !== '' ? (int) $area['eta_days'] : null,
                'is_active'        => (bool) ($area['is_active'] ?? true),
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        }
    }

    /** Approve: any non-active status → active. Already active is a no-op. */
    public function approve(Nursery $nursery, ?float $commissionRate, string $actorUuid): Nursery
    {
        if ($nursery->status === NurseryStatus::ACTIVE) {
            return $nursery;
        }

        return $this->db->transaction(function () use ($nursery, $commissionRate, $actorUuid) {
            $nursery->status = NurseryStatus::ACTIVE;
            if ($commissionRate !== null) {
                $nursery->commission_rate = $commissionRate;
            }
            $nursery->save();

            $this->events->publish(new NurseryApproved(
                $nursery->uuid,
                $nursery->legacy_id,
                $nursery->name,
                $nursery->slug,
                $nursery->description,
                $nursery->status,
                $nursery->commission_rate !== null ? (float) $nursery->commission_rate : null,
                $actorUuid,
            ));

            return $nursery;
        });
    }

    public function suspend(Nursery $nursery, string $actorUuid): Nursery
    {
        return $this->db->transaction(function () use ($nursery, $actorUuid) {
            $nursery->status = NurseryStatus::SUSPENDED;
            $nursery->save();

            $this->events->publish($this->updatedEvent($nursery, $actorUuid));

            return $nursery;
        });
    }

    public function changeCommission(Nursery $nursery, float $commissionRate, string $actorUuid): Nursery
    {
        return $this->db->transaction(function () use ($nursery, $commissionRate, $actorUuid) {
            $nursery->commission_rate = $commissionRate;
            $nursery->save();

            $this->events->publish(new CommissionChanged(
                $nursery->uuid,
                $nursery->legacy_id,
                $commissionRate,
                $actorUuid,
            ));

            return $nursery;
        });
    }

    public function setDocumentStatus(NurseryDocument $document, string $status, ?string $note, string $actorUuid): NurseryDocument
    {
        $document->status = $status;
        $document->note = $note;
        $document->reviewed_by_uuid = $actorUuid;
        $document->reviewed_at = Carbon::now();
        $document->save();

        return $document;
    }

    /**
     * Switch the owner: point owner_user_uuid at the identity user with the
     * given email and scope that user to this nursery. The previous owner's
     * identity scope is left untouched (an admin can reassign them separately).
     */
    public function transferOwnership(Nursery $nursery, string $newOwnerEmail, string $actorUuid): Nursery
    {
        return $this->db->transaction(function () use ($nursery, $newOwnerEmail, $actorUuid) {
            $newOwner = $this->db->table('identity_users')->where('email', $newOwnerEmail)->first();

            if (! $newOwner) {
                throw DomainActionException::unprocessable(
                    'No identity user exists with that email.',
                    'OWNER_NOT_FOUND',
                    'new_owner_email',
                );
            }

            $previousOwnerUuid = $nursery->owner_user_uuid;

            $nursery->owner_user_uuid = $newOwner->uuid;
            $nursery->save();

            $this->db->table('identity_users')
                ->where('uuid', $newOwner->uuid)
                ->update(['nursery_id' => $nursery->uuid]);

            $this->events->publish(new OwnershipTransferred(
                $nursery->uuid,
                $nursery->legacy_id,
                $previousOwnerUuid,
                $newOwner->uuid,
                $actorUuid,
            ));

            return $nursery;
        });
    }

    private function updatedEvent(Nursery $nursery, ?string $actorUuid): NurseryUpdated
    {
        return new NurseryUpdated(
            $nursery->uuid,
            $nursery->legacy_id,
            $nursery->name,
            $nursery->slug,
            $nursery->description,
            $nursery->status,
            $actorUuid,
        );
    }
}
