<?php

namespace Marvel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\GardenLead;
use Marvel\Enums\EventType;
use Marvel\Traits\SmsTrait;

/**
 * "A corporate gifting enquiry landed" -> the owner's phone.
 *
 * Nothing else notifies anyone: a lead only ever appeared in the admin list.
 * Same shape as NotifyOwnerOfSignup — gated on the settings matrix before
 * dispatch and inside handle(), de-duplicated per lead, fails silent: an
 * alert that cannot be sent must never break an enquiry.
 */
class NotifyOwnerOfCorporateLead implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SmsTrait;

    public int $tries = 3;

    public const HOURLY_CAP = 20;
    public const HOURLY_KEY = 'owner_notify:corporate_lead:hourly';

    /** Checked BEFORE dispatch so a switched-off alert queues nothing. Never throws. */
    public static function isEnabled(): bool
    {
        try {
            return (new self(0))->ownerAlertEnabled(EventType::CORPORATE_LEAD);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function __construct(public int $leadId)
    {
        // Set here, not as a property default: Queueable already declares
        // $afterCommit, and redeclaring it is a trait-composition conflict.
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        try {
            $lead = GardenLead::find($this->leadId);
            if (! $lead || ! $this->ownerAlertEnabled(EventType::CORPORATE_LEAD)) {
                return;
            }

            // A queue retry re-runs handle(); one message per lead per hour.
            if (! Cache::add('owner_notify:corporate_lead:' . $lead->id, 1, 3600)) {
                return;
            }
            // And at most HOURLY_CAP alerts an hour in total: a flood of spoofed leads must
            // not become a flood of paid messages. The leads themselves still land in admin.
            Cache::add(self::HOURLY_KEY, 0, 3600);
            if ((int) Cache::increment(self::HOURLY_KEY) > self::HOURLY_CAP) {
                Log::notice('sms.corporate_lead.capped', ['lead_id' => $lead->id]);
                return;
            }

            $gateway = $this->getOtpGateway();
            if (! $gateway) {
                return;
            }

            $message = __('sms.owner.corporateLead.message', [
                'COMPANY'  => $lead->company ?: 'a company',
                'NAME'     => $lead->name,
                'PHONE'    => $lead->phone,
                'QUANTITY' => $lead->quantity ?: 'n/a',
                'OCCASION' => $lead->occasion ?: 'n/a',
            ]);

            foreach ($this->ownerNotifyContacts() as $contact) {
                $this->deliverSms($gateway, $contact, $message, 'corporate_lead.owner');
            }
        } catch (\Throwable $e) {
            Log::warning('sms.corporate_lead.failed', [
                'lead_id' => $this->leadId,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
