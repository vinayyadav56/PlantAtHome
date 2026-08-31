<?php

namespace Marvel\Http\Controllers;

use Illuminate\Support\Facades\Log;
use Marvel\Database\Repositories\CheckoutRepository;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\CheckoutVerifyRequest;

class CheckoutController extends CoreController
{
    public $repository;

    public function __construct(CheckoutRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Verify the checkout data and calculate tax and shipping.
     *
     * @param CheckoutVerifyRequest $request
     * @return array
     */
    public function verify(CheckoutVerifyRequest $request)
    {
        try {
            return $this->repository->verify($request);
        } catch (MarvelException $th) {
            // RETHROW, don't relabel. MarvelException::isClientSafe() is true by contract —
            // its message exists precisely to be shown to the shopper ("Minimum order amount
            // is X", "this vertical is unavailable in your city"). Replacing every one of them
            // with SOMETHING_WENT_WRONG turned every distinct, actionable failure of the most
            // important endpoint in checkout into one dead end, for the shopper AND for anyone
            // trying to diagnose it: the real reason was constructed and then discarded.
            throw $th;
        } catch (\Throwable $th) {
            // Anything else genuinely is unexpected. Keep the generic message for the shopper,
            // but record what actually happened — this is the only place it exists.
            Log::error('checkout.verify.failed', [
                'error'      => $th->getMessage(),
                'exception'  => get_class($th),
                'at'         => $th->getFile() . ':' . $th->getLine(),
                'user_id'    => optional($request->user())->id,
                'lines'      => is_array($request['products'] ?? null) ? count($request['products']) : null,
                'ship_city'  => is_array($request['shipping_address'] ?? null)
                    ? ($request['shipping_address']['city'] ?? null) : null,
            ]);

            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }
}
