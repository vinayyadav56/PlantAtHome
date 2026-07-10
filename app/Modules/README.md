# Modules — bounded contexts (v2 modular monolith)

This is the greenfield **modular-monolith** kernel growing alongside the live
Pickbazar (`packages/marvel`) code. New domain work goes here; the marvel
package stays untouched until cutover.

## Layout of a module

Every bounded context is a self-contained module under `app/Modules/<Context>`:

```
app/Modules/<Context>/
  Domain/          # entities, value objects, domain events, repository INTERFACES (framework-agnostic)
  Application/     # services / command handlers / subscribers (orchestration, DTOs)
  Infrastructure/  # Eloquent models, repository implementations, mappers
  Http/            # controllers, form requests, resources, routes (thin)
  Database/        # migrations, seeders, factories (module-owned)
  Tests/
```

## The two rules (guardrails, architecture plan §2–3)

1. **Cross-module communication is only via** (a) another module's **Application
   service interface** (bound in a service provider) or (b) a **domain event**
   published to the outbox. A direct `use Modules\Other\Infrastructure\SomeModel`
   is a build error.
2. **Ownership**: each context owns its tables. No module writes another's data.

## Shared kernel

Cross-cutting primitives live in `app/Shared` (`App\Shared\…`), not here:

- `Shared/Domain` — `Entity`, `AggregateRoot`, `DomainEvent`,
  `AbstractDomainEvent`, `ValueObject/Money`.
- `Shared/Events` — `EventPublisher` (→ `OutboxEventPublisher`),
  `OutboxRelay`, `SubscriberRegistry`, `IntegrationMessage`.
- `Shared/Http` — `ApiResponse` envelope, `ApiController`.

Routes are served under **`/api/v1`** (see `routes/v1.php`), registered by
`App\Providers\SharedKernelServiceProvider`.

## Roadmap

Phase 0 (this) = skeleton + shared kernel + outbox. `Platform` is the first,
minimal module (health + a demo ping that emits a domain event through the
outbox). Phases 1→12 (Identity, Catalog, Configuration, Rules, Inventory,
Pricing, Serviceability, Cart/Checkout/Orders/…, Search, …) build on it.
