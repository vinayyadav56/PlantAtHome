<?php

namespace Marvel\Traits;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Profile;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\User;
use Marvel\Enums\EventType;
use Marvel\Enums\Permission;
use Marvel\Otp\Gateways\OtpGateway;

trait SmsTrait
{

    public function sendSmsOnRefund($smsArray)
    {
        try {
            $order = $smsArray['order'];
            $smsGateway = $this->getOtpGateway();
            if (!$smsGateway) {
                return; // no messaging provider configured — not an error, just nothing to send
            }
            $userType = $this->getWhichUserWillGetSms($smsArray['smsEventName'], $smsArray['language']);
            if ($userType['customer'] == true) {
                // Same replace-with-fallback seam as the order branch above.
                $dltSent = ! empty($smsArray['dlt']['code'])
                    && app(\Marvel\Services\SmsTemplateService::class)
                        ->sendByCode($smsArray['dlt']['code'], $order->customer_contact, $smsArray['dlt']['vars'] ?? []);
                if (! $dltSent) {
                    $this->deliverSms($smsGateway, $order->customer_contact, $smsArray['customerMessage'], 'refund.customer');
                }
            }

            if ($userType['admin'] == true) {
                foreach ($this->ownerNotifyContacts($smsArray['language'] ?? DEFAULT_LANGUAGE) as $contact) {
                    $this->deliverSms($smsGateway, $contact, $smsArray['adminMessage'], 'refund.admin');
                }
            }
        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::warning('sms.refund_event.failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * One send + its outcome logged. Gateways return a Result rather than
     * throwing, so an unchecked call silently dropped every failure — this is
     * the single seam where notify delivery problems become visible.
     */
    protected function deliverSms($gateway, $contact, $message, string $audience): void
    {
        if (empty($contact)) {
            return;
        }
        $result = $gateway->sendSms($contact, $message);
        if ($result && method_exists($result, 'isValid') && !$result->isValid()) {
            \Illuminate\Support\Facades\Log::warning('sms.order_event.send_failed', [
                'audience' => $audience,
                'errors' => $result->getErrors(),
            ]);
        }
    }


    /**
     * @param $data
     * @return array
     */

    public function sendSmsOnOrderEvent($smsArray, $shouldSendToChildOrder = true): void
    {


        try {
            $order = $smsArray['order'];
            $smsGateway = $this->getOtpGateway();
            if (!$smsGateway) {
                return; // no messaging provider configured — not an error, just nothing to send
            }
            $userType = $this->getWhichUserWillGetSms($smsArray['smsEventName'], $smsArray['language']);

            if ($userType['customer'] && $order->parent_id == null) {
                // Airtel DLT path: an active registry template for this event
                // sends structured variables through its own MSG91 flow — the
                // legacy blob below is skipped only when that send SUCCEEDS,
                // so an unconfigured/failed template never drops the message.
                $dltSent = ! empty($smsArray['dlt']['code'])
                    && app(\Marvel\Services\SmsTemplateService::class)
                        ->sendByCode($smsArray['dlt']['code'], $order->customer_contact, $smsArray['dlt']['vars'] ?? []);
                if (! $dltSent) {
                    $this->deliverSms($smsGateway, $order->customer_contact, $smsArray['customerMessage'], 'order.customer');
                }
            }
            if ($userType['admin']) {
                // One configurable destination, falling back to every
                // super-admin's profile contact when none is set.
                foreach ($this->ownerNotifyContacts($smsArray['language'] ?? DEFAULT_LANGUAGE) as $contact) {
                    $this->deliverSms($smsGateway, $contact, $smsArray['adminMessage'], 'order.admin');
                }
            }
            if ($userType['vendor']) {
                $message = $smsArray['storeOwnerMessage'];
                if ($order->parent_id == null) {
                    if (!$shouldSendToChildOrder) {
                        return;
                    }
                    $childOrders = $order->children;


                    foreach ($childOrders as $childOrder) {
                        $storeOwner = $childOrder->shop->owner;
                        // first(), not firstOrFail(): a vendor without a profile
                        // must not abort the remaining vendors' notifications
                        // (the throw landed in the catch-all below and silently
                        // dropped every later child order).
                        $shopOwnerProfile = Profile::where('customer_id', $storeOwner->id)->first();

                        if ($shopOwnerProfile)
                            $this->deliverSms($smsGateway, $shopOwnerProfile->contact, str_replace(':ORDER_TRACKING_NUMBER', $childOrder->tracking_number, $message), 'order.vendor');
                    }
                } else {
                    $storeOwner = $order->shop->owner;
                    $storeOwnerProfile = $storeOwner->profile;
                    if ($storeOwnerProfile && $storeOwnerProfile->contact)
                        $this->deliverSms($smsGateway, $storeOwnerProfile->contact, str_replace(':ORDER_TRACKING_NUMBER', $order->tracking_number, $message), 'order.vendor');
                }
            }
        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::warning('sms.order_event.failed', [
                'event' => $smsArray['smsEventName'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get OTP gateway
     *
     * @return OtpGateway
     */
    protected function getOtpGateway()
    {
        // Transactional notifications (order updates) and login OTP are different jobs and can
        // sensibly use different providers: WhatsApp for order updates a customer keeps, SMS for
        // the login code. This used to read `active_otp_gateway` only, so the two were welded
        // together — switching order updates to WhatsApp also switched login, and vice versa.
        // `auth.notify_gateway` decouples them and falls back to the old behaviour when unset.
        $gateway = config('auth.notify_gateway') ?: config('auth.active_otp_gateway');
        $gateWayClass = "Marvel\\Otp\\Gateways\\" . ucfirst($gateway) . 'Gateway';
        if (!class_exists($gateWayClass)) {
            // A misconfigured provider must never break order processing — the notification is
            // skipped and the order flow continues.
            \Illuminate\Support\Facades\Log::warning('notification gateway missing', ['gateway' => $gateway]);
            return null;
        }
        return new OtpGateway(new $gateWayClass());
    }

    /**
     * Get which user will get sms
     *
     * @param string $smsEventName
     * @param string $language
     * @return mixed
     */

    public function getWhichUserWillGetSms(string $smsEventName, string $language): array
    {
        return $this->getWhichUserWillGetEventSmsOrEmail($smsEventName, 'smsEvent', $language);
    }

    /**
     * Get admin List
     * @return Collection
     */
    public function adminList(): Collection
    {
        return User::permission(Permission::SUPER_ADMIN)->get();
    }

    /**
     * Where owner alerts (new order, new signup) should actually go.
     *
     * Historically this was "every super-admin's profile contact", which is
     * neither configurable nor predictable — add a second super-admin and the
     * owner silently starts sharing their alerts. A number set at
     * Settings -> Events -> "Owner notification number" wins outright; with
     * nothing set the old behaviour is preserved exactly, so this is safe to
     * deploy before anyone fills the field in.
     *
     * WhatsappGateway::normalize() accepts any format and prefixes 91 for a
     * bare 10-digit number, so no canonicalisation is needed here.
     *
     * @return string[]
     */
    public function ownerNotifyContacts(string $language = DEFAULT_LANGUAGE): array
    {
        try {
            $settings = Settings::getData($language);
            $configured = trim((string) ($settings->options['ownerNotify']['number'] ?? ''));
        } catch (\Throwable $e) {
            $configured = '';
        }

        if ($configured !== '') {
            return [$configured];
        }

        return $this->adminList()
            ->map(fn ($admin) => $admin->profile->contact ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Is this owner alert switched on? Reuses the same settings matrix the
     * order toggles already live in, so the admin's notification-events form
     * renders it with no new UI plumbing.
     */
    public function ownerAlertEnabled(string $eventName, string $language = DEFAULT_LANGUAGE): bool
    {
        return (bool) ($this->getWhichUserWillGetSms($eventName, $language)['admin'] ?? false);
    }

    public function getWhichUserWillGetEmail($emailEventName, $language): array
    {
        return $this->getWhichUserWillGetEventSmsOrEmail($emailEventName, 'emailEvent', $language);
    }

    public function getWhichUserWillGetEventSmsOrEmail(string $eventName, string $eventType, string $language): array
    {
        $orderStatusChangeArray = [
            EventType::ORDER_CANCELLED, EventType::ORDER_DELIVERED, EventType::ORDER_CREATED, EventType::ORDER_STATUS_CHANGED
        ];
        if (in_array($eventName, $orderStatusChangeArray)) {
            $eventName = EventType::ORDER_STATUS_CHANGED;
        }
        if (in_array($eventName, [EventType::ORDER_PAYMENT_FAILED, EventType::ORDER_PAYMENT_SUCCESS])) {
            $eventName = EventType::ORDER_PAYMENT;
        }
        $userArray = ['customer' => false, 'admin' => false, 'vendor' => false];
        $settings = Settings::getData($language);
        if (!isset($settings->options[$eventType])) return $userArray;
        $options = $settings->options;
        foreach ($userArray as $key => $value) {
            if (isset($options[$eventType][$key][$eventName])) {
                $userArray[$key] = $options[$eventType][$key][$eventName];
            }
        }
        //send a test email

        return $userArray;
    }
}
