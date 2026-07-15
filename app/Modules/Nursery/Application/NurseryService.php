<?php

namespace App\Modules\Nursery\Application;

use App\Modules\Nursery\Domain\Events\CommissionChanged;
use App\Modules\Nursery\Domain\Events\NurseryApproved;
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

    /** Create a nursery in `pending` with an empty balance row. */
    public function create(array $data, ?string $actorUuid): Nursery
    {
        return $this->db->transaction(function () use ($data) {
            $nursery = Nursery::create([
                'name'            => $data['name'],
                'slug'            => $data['slug'] ?? null,
                'description'     => $data['description'] ?? null,
                'logo'            => $data['logo'] ?? null,
                'cover_image'     => $data['cover_image'] ?? null,
                'address'         => $data['address'] ?? null,
                'settings'        => $data['settings'] ?? null,
                'commission_rate' => $data['commission_rate'] ?? null,
                'status'          => NurseryStatus::PENDING,
            ]);

            NurseryBalance::create(['nursery_id' => $nursery->id]);

            return $nursery;
        });
    }

    public function update(Nursery $nursery, array $data, ?string $actorUuid = null): Nursery
    {
        return $this->db->transaction(function () use ($nursery, $data, $actorUuid) {
            $nursery->fill($data)->save();

            $this->events->publish($this->updatedEvent($nursery, $actorUuid));

            return $nursery;
        });
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
