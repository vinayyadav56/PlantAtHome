<?php

namespace Marvel\Services;

use Illuminate\Support\Collection;
use Marvel\Database\Models\PlantAttributeDefinition;

/**
 * Which attribute definitions apply to a product: its vertical's definitions (and any with no
 * vertical) that either have no category scope, or are scoped to one of the product's
 * categories or one of their primary ancestors. A template is therefore set once, on a parent
 * ("Pruning and Cutting Tools" → cutting capacity), and every tool type beneath inherits it.
 * "Required" holds when any matching scope says so.
 */
class ProductAttributeSchema
{
    /**
     * @return Collection<int, PlantAttributeDefinition> ordered by sort then name, each with a
     *         computed `is_required`, terms loaded (retired ones included: a product may still
     *         carry one; pickers offer only the active ones).
     */
    public function forProduct(?int $typeId, array $categoryIds, bool $withInactive = false): Collection
    {
        $tree = app(CategoryTree::class);
        $context = [];
        foreach ($categoryIds as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $context[$id] = true;
            foreach ($tree->ancestors($id) as $ancestor) {
                $context[$ancestor['id']] = true;
            }
        }

        $definitions = PlantAttributeDefinition::query()
            ->when(!$withInactive, fn ($q) => $q->where('is_active', true))
            ->where(fn ($q) => $q->whereNull('type_id')->when($typeId, fn ($w) => $w->orWhere('type_id', $typeId)))
            ->with(['terms', 'categories:id'])
            ->orderBy('sort')->orderBy('name')
            ->get();

        return $definitions->filter(function (PlantAttributeDefinition $def) use ($context) {
            if ($def->categories->isEmpty()) {
                $def->setAttribute('is_required', false);

                return true;
            }
            $matching = $def->categories->filter(fn ($c) => isset($context[(int) $c->id]));
            if ($matching->isEmpty()) {
                return false;
            }
            $def->setAttribute('is_required', $matching->contains(fn ($c) => (bool) $c->pivot->is_required));

            return true;
        })->values();
    }
}
