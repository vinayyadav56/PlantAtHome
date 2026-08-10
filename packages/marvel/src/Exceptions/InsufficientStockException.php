<?php

namespace Marvel\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thrown by the inventory decrement when the atomic conditional UPDATE matches
 * 0 rows and the oversell policy is 'block'. Deliberately an HttpException so
 * it renders as a clean 422 to the buyer, and deliberately NOT a MarvelException
 * so OrderController::store's generic wrap doesn't mask it. It must always be
 * rethrown by the inventory listener's defensive catches — throwing it inside
 * the order transaction is what rolls the whole order back.
 */
class InsufficientStockException extends HttpException
{
    public function __construct(string $message = 'Some items in your cart just went out of stock. Please review your cart and try again.')
    {
        parent::__construct(422, $message);
    }
}
