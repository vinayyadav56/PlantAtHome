<?php

namespace Marvel\Http\Controllers;

use Marvel\Database\Models\Category;
use Marvel\Exceptions\MarvelException;
use Marvel\Facades\Ai;
use Marvel\Http\Requests\AiDescriptionRequest;
use Marvel\Http\Requests\SuggestCategoriesRequest;

class AiController extends CoreController
{

    public function generateDescription(AiDescriptionRequest $request): mixed
    {
        try {
            return Ai::generateDescription($request);
        } catch (MarvelException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG, $e->getMessage());
        }
    }

    /**
     * Suggest the best-fitting EXISTING categories for a product. The candidate
     * list is fetched server-side (scoped to the product's vertical/type) so the
     * model can only ever return categories that already exist.
     */
    public function suggestCategories(SuggestCategoriesRequest $request): mixed
    {
        $type = $request->input('type');

        $candidates = Category::query()
            ->when($type, fn ($q) => $q->whereHas('type', fn ($t) => $t->where('slug', $type)))
            ->limit(400)
            ->get(['id', 'name', 'slug'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug])
            ->all();

        // Hand the candidates to the AI layer via the request bag.
        $request->merge(['categories' => $candidates]);

        try {
            return Ai::suggestCategories($request);
        } catch (MarvelException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG, $e->getMessage());
        }
    }
}
