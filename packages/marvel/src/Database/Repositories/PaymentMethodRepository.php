<?php


namespace Marvel\Database\Repositories;

use Exception;
use Marvel\Database\Models\PaymentMethod;
use Marvel\Events\PaymentMethods;
use Marvel\Facades\Payment;
use Marvel\Traits\PaymentTrait;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentMethodRepository extends BaseRepository
{
    use PaymentTrait;
    /**
     * @var array
     */
    protected $fieldSearchable = [];

    /**
     * @var string[]
     */
    protected $dataArray = [
        "method_key",
        "default_card",
        "payment_intent"
    ];


    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
            throw $e;
        }
    }

    /**
     * Configure the Model
     **/
    public function model()
    {
        return PaymentMethod::class;
    }


    /**
     * setStripeIntent
     *
     * @param  mixed $request
     * @return void
     */
    public function setStripeIntent($request)
    {
        # code...
        $customer = $this->createPaymentCustomer($request);
        $setupIntent = Payment::setIntent([
            'customer' => $customer->customer_id,
            'payment_method_types' => ['card'],
            'usage' => 'on_session'
        ]);

        return $setupIntent;
    }


    /**
     * saveStripeCard
     *
     * @param  mixed  $request
     * @return PaymentMethod
     * @throws Exception
     */
    public function saveStripeCard($request)
    {
        $retrieved_payment_method = Payment::retrievePaymentMethod($request['method_key']);

        if ($this->paymentMethodAlreadyExists($retrieved_payment_method->card->fingerprint)) {
            return PaymentMethod::where("fingerprint", "=", $retrieved_payment_method->card->fingerprint)->first();
        } else {
            $attached_payment_method = Payment::attachPaymentMethodToCustomer($retrieved_payment_method->id, $request);
            return $this->saveCard($attached_payment_method, $request);
        }
    }




    /**
     * storeCards
     *
     * @param  mixed $request
     * @param  mixed $settings
     * @return void
     */
    public function storeCards($request)
    {
        try {
            switch ($request['payment_gateway']) {
                case 'stripe':
                    # code...
                    $retrieved_payment_method = Payment::retrievePaymentMethod($request['method_key']);
                    if ($this->paymentMethodAlreadyExists($retrieved_payment_method->card->fingerprint)) {
                        return PaymentMethod::where("fingerprint", "=", $retrieved_payment_method->card->fingerprint)->first();
                    } else {
                        // attach card with customer
                        $attached_payment_method = Payment::attachPaymentMethodToCustomer($retrieved_payment_method->id, $request);
                        return $this->saveCard($attached_payment_method, $request);
                    }
                    break;

                case 'razorpay':
                    break;
            }
        } catch (Exception $e) {
            throw new HttpException(400, COULD_NOT_CREATE_THE_RESOURCE);
        }
    }

    /**
     * setDefaultPaymentMethod
     *
     * @param  mixed $request
     * @return void
     */
    public function setDefaultPaymentMethod($request)
    {
        // SECURITY: scope to the caller's OWN card (via payment_gateways.user_id) so a customer
        // can't flip another user's default-card flag by guessing method_id.
        $userId = $request->user()->id;
        $payment_method = PaymentMethod::where('id', '=', $request['method_id'])
            ->whereRelation('payment_gateways', 'user_id', $userId)
            ->firstOrFail();
        /* Unset other defaults only among THIS user's cards on the same gateway. */
        PaymentMethod::where('id', '!=', $payment_method->id)
            ->whereRelation('payment_gateways', 'user_id', $userId)
            ->where([
            'default_card'       => true,
            "payment_gateway_id" =>  $payment_method?->payment_gateway_id,
        ])->update(['default_card' => false]);

        $payment_method->default_card = true;
        $payment_method->save();
        event(new PaymentMethods($payment_method));
        return $payment_method;
    }
}
