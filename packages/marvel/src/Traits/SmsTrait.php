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
                $this->deliverSms($smsGateway, $order->customer_contact, $smsArray['customerMessage'], 'refund.customer');
            }

            if ($userType['admin'] == true) {

                $adminList = $this->adminList();


                foreach ($adminList as $admin) {
                    $adminProfile = $admin->profile;
                    if ($adminProfile) $this->deliverSms($smsGateway, $adminProfile->contact, $smsArray['adminMessage'], 'refund.admin');
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
                $this->deliverSms($smsGateway, $order->customer_contact, $smsArray['customerMessage'], 'order.customer');
            }
            if ($userType['admin']) {

                $adminList = $this->adminList();


                foreach ($adminList as $admin) {
                    $adminProfile = $admin->profile;
                    if ($adminProfile) $this->deliverSms($smsGateway, $adminProfile->contact, $smsArray['adminMessage'], 'order.admin');
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
