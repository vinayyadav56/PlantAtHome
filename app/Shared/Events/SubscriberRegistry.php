<?php

namespace App\Shared\Events;

use Illuminate\Contracts\Container\Container;

/**
 * Maps an event name to the subscriber classes that react to it. Modules
 * register their subscribers here (in their service providers) — the producer
 * never knows who consumes. Subscribers are resolved from the container and
 * invoked with an IntegrationMessage; each MUST be idempotent (dedupe on
 * eventId) because delivery is at-least-once (Section 9).
 *
 * A subscriber is any class with a `handle(IntegrationMessage $message): void`
 * method (or an __invoke). This is the in-process bus; swapping it for RabbitMQ
 * later means changing only the relay's transport, not this registry.
 */
final class SubscriberRegistry
{
    /** @var array<string, string[]> event name => subscriber class names */
    private array $subscribers = [];

    public function __construct(private readonly Container $container)
    {
    }

    public function listen(string $eventName, string $subscriberClass): void
    {
        $this->subscribers[$eventName][] = $subscriberClass;
    }

    /** @return string[] */
    public function subscribersFor(string $eventName): array
    {
        return array_values(array_unique($this->subscribers[$eventName] ?? []));
    }

    /** Deliver a message to every subscriber registered for its event name. */
    public function dispatch(IntegrationMessage $message): void
    {
        foreach ($this->subscribersFor($message->eventName) as $subscriberClass) {
            $subscriber = $this->container->make($subscriberClass);
            if (method_exists($subscriber, 'handle')) {
                $subscriber->handle($message);
            } elseif (is_callable($subscriber)) {
                $subscriber($message);
            }
        }
    }
}
