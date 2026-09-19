<?php

namespace Marvel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\User;
use Marvel\Enums\EventType;
use Marvel\Enums\Permission;
use Marvel\Traits\SmsTrait;

/**
 * "Someone signed up" -> the owner's phone.
 *
 * Why a queued job rather than a line in the three controllers:
 *
 *  - There is no usable event. `Registered` fires only inside register() AND
 *    only when useMustVerifyEmail is true, which production has set to false,
 *    so it never fires there at all. `ProcessUserData` carries no payload.
 *  - Customers are created at three separate call sites (register, social
 *    login, OTP login). Editing three is three chances to miss the fourth one
 *    added later, so this hangs off User::created instead — one choke point.
 *  - That hook fires BEFORE givePermissionTo(CUSTOMER) runs, so an inline role
 *    check would see a user with no permissions. Deferring past the commit is
 *    what makes the role gate below correct, not just tidy.
 *
 * Fails silent by design: an alert that cannot be sent must never break a
 * signup.
 */
class NotifyOwnerOfSignup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SmsTrait;

    public int $tries = 3;

    public function __construct(public int $userId)
    {
        // Set here, not as a property default: Queueable already declares
        // $afterCommit, and redeclaring it with a different default is a fatal
        // trait-composition conflict. Only run once the signup has committed.
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        try {
            $user = User::find($this->userId);
            if (! $user) {
                return;
            }

            // User::created also fires for admins, vendors, staff and seeders.
            // Only a real customer counts — and a customer is never also a
            // super-admin or store owner, so exclude those explicitly rather
            // than trusting the customer role alone.
            if (! $user->hasPermissionTo(Permission::CUSTOMER)) {
                return;
            }
            if (
                $user->hasPermissionTo(Permission::SUPER_ADMIN)
                || $user->hasPermissionTo(Permission::STORE_OWNER)
            ) {
                return;
            }

            if (! $this->ownerAlertEnabled(EventType::CUSTOMER_REGISTERED)) {
                return;
            }

            // Nothing on this path de-duplicates sends, and a queue retry
            // re-runs handle(). One row per user per hour is enough to turn a
            // retry storm into a single message.
            if (! Cache::add('owner_notify:signup:' . $user->id, 1, 3600)) {
                return;
            }

            $gateway = $this->getOtpGateway();
            if (! $gateway) {
                return;
            }

            $message = __('sms.owner.customerSignup.message', [
                'NAME'    => $user->name ?: 'A new customer',
                'CONTACT' => $user->email ?: ($user->profile->contact ?? 'no contact'),
            ]);

            foreach ($this->ownerNotifyContacts() as $contact) {
                $this->deliverSms($gateway, $contact, $message, 'signup.owner');
            }
        } catch (\Throwable $e) {
            Log::warning('sms.customer_signup.failed', [
                'user_id' => $this->userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
