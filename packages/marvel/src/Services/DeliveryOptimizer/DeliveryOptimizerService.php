<?php

namespace Marvel\Services\DeliveryOptimizer;

use Carbon\Carbon;
use Marvel\Services\DeliveryOptimizer\Contracts\CandidateProviderInterface;
use Marvel\Services\DeliveryOptimizer\Contracts\OptimizerConfigInterface;
use Marvel\Services\DeliveryOptimizer\Contracts\ShippingQuoteClientInterface;
use Marvel\Services\DeliveryOptimizer\Dto\CartItem;
use Marvel\Services\DeliveryOptimizer\Dto\OptimizationResult;
use Marvel\Services\DeliveryOptimizer\Dto\QuoteRequest;
use Marvel\Services\DeliveryOptimizer\Dto\QuoteResult;
use Marvel\Services\DeliveryOptimizer\Dto\Shipment;
use Marvel\Services\DeliveryOptimizer\Dto\UserLocation;
use Marvel\Services\DeliveryOptimizer\PhaseB\CostModel;
use Marvel\Services\DeliveryOptimizer\PhaseB\GreedyAssigner;
use Marvel\Services\DeliveryOptimizer\PhaseB\PlanState;
use Marvel\Services\DeliveryOptimizer\PhaseB\ShipmentGrouper;
use Marvel\Services\DeliveryOptimizer\PhaseB\TwoOptRefiner;

/**
 * The optimization brain. Given a cart + destination, it consolidates items onto the
 * fewest delivery legs that minimise true cost, prices each leg once (estimate while
 * browsing, firm at checkout), and returns one flat customer fee + per-leg dates +
 * upsell hint.
 *
 * Depends only on the 4 interfaces (the extraction seam) + the pure Phase-B search,
 * so the whole thing lifts into a Go/Python worker unchanged.
 */
final class DeliveryOptimizerService
{
    private CostModel $costModel;
    private ShipmentGrouper $grouper;
    private GreedyAssigner $greedy;
    private TwoOptRefiner $twoOpt;

    public function __construct(
        private CandidateProviderInterface $candidates,
        private ShippingQuoteClientInterface $quoter,
        private OptimizerConfigInterface $config,
    ) {
        $this->costModel = new CostModel($config);
        $this->grouper = new ShipmentGrouper();
        $this->greedy = new GreedyAssigner($this->costModel);
        $this->twoOpt = new TwoOptRefiner($this->costModel);
    }

    /**
     * Full solve. $opts: firm (bool, default false = browse/estimate), cod (bool),
     * codAmount (float).
     *
     * @param CartItem[] $items
     */
    public function optimizeCart(array $items, UserLocation $loc, array $opts = []): OptimizationResult
    {
        $solved = $this->solve($items, $loc);
        return $this->buildResult($solved['state'], $solved['subtotalPerItem'], $solved['unfulfillable'], $loc, $opts);
    }

    /**
     * Phase A for the whole cart + Phase B (greedy + bounded 2-opt). Returns the raw
     * PlanState and the per-item min selling price (so callers can do incremental ops).
     *
     * @param CartItem[] $items
     * @return array{state: PlanState, subtotalPerItem: array<string,float>, unfulfillable: CartItem[]}
     */
    public function solve(array $items, UserLocation $loc): array
    {
        $this->candidates->warm($items, $loc);

        $rawByItem = [];
        $fulfillable = [];
        $unfulfillable = [];
        $subtotalPerItem = [];

        foreach ($items as $item) {
            $cands = $this->candidates->candidates($item, $loc, $this->config->topK());
            if (empty($cands)) {
                $unfulfillable[] = $item;
                continue;
            }
            $rawByItem[$item->key()] = $cands;
            $fulfillable[] = $item;
            $subtotalPerItem[$item->key()] = $this->minSellingPrice($cands);
        }

        $deduped = $this->grouper->dedupePerLeg($rawByItem);
        $state = $this->greedy->assign($fulfillable, $deduped);
        $this->twoOpt->refine($state, $this->config->timeBudgetMs());

        return ['state' => $state, 'subtotalPerItem' => $subtotalPerItem, 'unfulfillable' => $unfulfillable];
    }

    /**
     * Incremental add: Phase-A only the new item, greedy-place it into the best existing
     * leg (O(K)); trigger a full re-opt only when it opens a NEW leg whose fee exceeds the
     * marginal threshold, or every N events. Mutates and returns $state.
     */
    public function incrementalAdd(PlanState $state, CartItem $newItem, UserLocation $loc, int $eventCount = 0): PlanState
    {
        $this->candidates->warm([$newItem], $loc);
        $cands = $this->candidates->candidates($newItem, $loc, $this->config->topK());
        if (empty($cands)) {
            return $state; // unfulfillable — leave the plan untouched
        }
        $deduped = $this->grouper->dedupePerLeg([$newItem->key() => $cands]);
        $state->registerItem($newItem, $deduped[$newItem->key()]);

        $bestLeg = null;
        $bestMarginal = INF;
        foreach ($state->itemViable[$newItem->key()] as $legKey => $cand) {
            $m = $this->greedy->marginalCost($state, $newItem->key(), $legKey, $cand);
            if ($m < $bestMarginal - 1e-9) {
                $bestMarginal = $m;
                $bestLeg = $legKey;
            }
        }
        if ($bestLeg === null) {
            return $state;
        }
        $wasEmpty = empty($state->legs[$bestLeg]['items']);
        $state->place($newItem->key(), $bestLeg);

        if ($this->shouldReopt($state, $bestLeg, $wasEmpty, $eventCount)) {
            $this->twoOpt->refine($state, $this->config->timeBudgetMs());
        }
        return $state;
    }

    /**
     * Incremental remove: drop the item (an emptied leg's fee vanishes for free), with a
     * re-opt only on the N-event cadence (removal can make a merge newly profitable).
     */
    public function incrementalRemove(PlanState $state, string $itemKey, int $eventCount = 0): PlanState
    {
        $state->unassign($itemKey);
        unset($state->cart[$itemKey], $state->itemViable[$itemKey]);

        $n = $this->config->fullReoptEveryNEvents();
        if ($n > 0 && $eventCount > 0 && ($eventCount % $n === 0)) {
            $this->twoOpt->refine($state, $this->config->timeBudgetMs());
        }
        return $state;
    }

    /**
     * Turn a solved PlanState into the customer-facing result: price each active leg
     * once (firm at checkout, estimate while browsing), compute the flat fee, the upsell
     * hint, and the per-shipment dates.
     *
     * @param array<string,float> $subtotalPerItem
     * @param CartItem[] $unfulfillable
     */
    public function buildResult(PlanState $state, array $subtotalPerItem, array $unfulfillable, UserLocation $loc, array $opts = []): OptimizationResult
    {
        $firm = (bool) ($opts['firm'] ?? false);
        $cod = (bool) ($opts['cod'] ?? false);
        $codAmount = (float) ($opts['codAmount'] ?? 0.0);

        // Price all legs at once so the firm path can batch.
        $requests = [];
        foreach ($state->activeLegs() as $legKey => $leg) {
            $cartItems = array_map(fn ($ik) => $state->cart[$ik], array_keys($leg['items']));
            $requests[] = new QuoteRequest(
                $leg['vendorId'],
                $leg['rail'],
                $cartItems,
                $state->legWeightG($legKey),
                $leg['baseCost'],
                $leg['perKgCost'],
                $loc->pincode,
                $firm,
                $cod,
                $codAmount,
            );
        }
        $quotes = $requests ? $this->quoter->quoteMany($requests) : [];

        // Anchor every shipment date to ONE "now" so legs of the same plan can't straddle a
        // midnight boundary and disagree by a day.
        $now = Carbon::now();

        $shipments = [];
        $internalTrueCost = 0.0;
        $sources = [];
        $index = 0;
        foreach ($state->activeLegs() as $legKey => $leg) {
            $legItemKeys = array_keys($leg['items']);
            $cartItems = array_map(fn ($ik) => $state->cart[$ik], $legItemKeys);

            $etaMin = null;
            $etaMax = null;
            foreach ($legItemKeys as $ik) {
                $eta = $state->itemViable[$ik][$legKey]->etaDays;
                $etaMin = $etaMin === null ? $eta : min($etaMin, $eta);
                $etaMax = $etaMax === null ? $eta : max($etaMax, $eta);
            }
            $etaMin = (int) ($etaMin ?? 0);
            $etaMax = (int) ($etaMax ?? 0);

            $quote = $quotes[$legKey] ?? new QuoteResult(
                $this->costModel->legFee($leg['baseCost'], $leg['perKgCost'], $state->legWeightG($legKey)),
                $etaMax,
                QuoteResult::ESTIMATE,
            );
            $internalTrueCost += $quote->fee;
            $sources[] = $quote->source;

            // On the firm path, a real carrier ETA must not be UNDER-promised by the estimate —
            // widen the upper bound (and date) to the carrier's value so we never over-promise.
            if ($quote->isFirm() && $quote->etaDays > 0) {
                $etaMax = max($etaMax, $quote->etaDays);
            }

            $shipments[] = new Shipment(
                'S' . (++$index),
                $leg['vendorId'],
                $leg['rail'],
                $cartItems,
                $quote->fee,
                $etaMin,
                $etaMax,
                $now->copy()->addDays($etaMin)->toDateString(),
                $now->copy()->addDays($etaMax)->toDateString(),
                $quote->source,
            );
        }

        // Customer subtotal from the ACTUALLY-CHOSEN candidate per item (the price the customer
        // pays on that leg), not the global min across vendors — so the free-delivery gate and
        // the displayed subtotal match the selected plan.
        $subtotal = 0.0;
        foreach ($state->assignment as $ik => $legKey) {
            $cand = $state->itemViable[$ik][$legKey] ?? null;
            $price = ($cand && $cand->sellingPrice !== null) ? $cand->sellingPrice : ($subtotalPerItem[$ik] ?? 0.0);
            $subtotal += $price * $state->cart[$ik]->qty;
        }

        // Free-delivery gate basis: prefer the server-authoritative order amount when the caller
        // supplies it (checkout) so the preview matches CheckoutRepository::verify; else subtotal.
        $feeBasis = isset($opts['amount']) ? (float) $opts['amount'] : $subtotal;
        [$flatFee, $upsell] = $this->customerFee($feeBasis);

        return new OptimizationResult(
            $shipments,
            $flatFee,
            $internalTrueCost,
            $subtotal,
            $upsell,
            $this->reduceSources($sources),
            $unfulfillable,
        );
    }

    private function shouldReopt(PlanState $state, string $legKey, bool $openedNewLeg, int $eventCount): bool
    {
        if ($openedNewLeg) {
            $fee = $state->legFee($legKey, $this->costModel);
            if ($fee > $this->config->marginalReoptThreshold()) {
                return true;
            }
        }
        $n = $this->config->fullReoptEveryNEvents();
        return $n > 0 && $eventCount > 0 && ($eventCount % $n === 0);
    }

    /** @param \Marvel\Services\DeliveryOptimizer\Dto\Candidate[] $cands */
    private function minSellingPrice(array $cands): float
    {
        $prices = [];
        foreach ($cands as $c) {
            if ($c->sellingPrice !== null) {
                $prices[] = $c->sellingPrice;
            }
        }
        return !empty($prices) ? (float) min($prices) : 0.0;
    }

    /** @return array{0: float, 1: array{gap_to_free_delivery: float, threshold: float}|null} */
    private function customerFee(float $subtotal): array
    {
        $threshold = $this->config->freeDeliveryThreshold();
        if ($this->config->freeDeliveryEnabled() && $subtotal >= $threshold) {
            return [0.0, null];
        }
        $upsell = null;
        if ($this->config->freeDeliveryEnabled() && $threshold > 0) {
            $upsell = [
                'gap_to_free_delivery' => round(max(0.0, $threshold - $subtotal), 2),
                'threshold'            => round($threshold, 2),
            ];
        }
        return [$this->config->baseFlatFee(), $upsell];
    }

    /** @param string[] $sources */
    private function reduceSources(array $sources): string
    {
        if (empty($sources)) {
            return QuoteResult::ESTIMATE;
        }
        $unique = array_values(array_unique($sources));
        if (count($unique) === 1) {
            return $unique[0];
        }
        return 'mixed';
    }
}
