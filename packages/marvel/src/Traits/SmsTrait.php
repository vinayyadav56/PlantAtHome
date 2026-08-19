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
                $smsGateway->sendSms($order->customer_contact, $smsArray['customerMessage']);
            }

            if ($userType['admin'] == true) {

                $adminList = $this->adminList();


                foreach ($adminList as $admin) {
                    $adminProfile = $admin->profile;
                    if ($adminProfile) $smsGateway->sendSms($adminProfile->contact, $smsArray['adminMessage']);
                }
            }
        } catch (Exception $e) {
            //Log::error($e->getMessage());
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
                $smsGateway->sendSms($order->customer_contact, $smsArray['customerMessage']);
                /* $customer = $order->customer;
                 if ($customer && $customer->profile && $customer->profile->contact) {
                     $smsGateway->sendSms($customer->profile->contact, $smsArray['customerMessage']);
                 }*/
            }
            if ($userType['admin']) {

                $adminList = $this->adminList();


                foreach ($adminList as $admin) {
                    $adminProfile = $admin->profile;
                    if ($adminProfile) $smsGateway->sendSms($adminProfile->contact, $smsArray['adminMessage']);
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
                        $shopOwnerProfile = Profile::where('customer_id', $storeOwner->id)->firstOrFail();

                        if ($shopOwnerProfile)
                            $smsGateway->sendSms($shopOwnerProfile->contact, str_replace(':ORDER_TRACKING_NUMBER', $childOrder->tracking_number, $message));
                    }
                } else {
                    $storeOwner = $order->shop->owner;
                    $storeOwnerProfile = $storeOwner->profile;
                    if ($storeOwnerProfile && $storeOwnerProfile->contact)
                        $smsGateway->sendSms($storeOwnerProfile->contact, str_replace(':ORDER_TRACKING_NUMBER', $order->tracking_number, $message));
                }
            }
        } catch (Exception $e) {
            // do nothing
            info('This exception info is from SmsTrait sendSmsOnOrderEvent method');
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
