<?php

namespace App\Modules\Search\Http\Controllers;

use App\Modules\Search\Application\SearchService;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public product search + autocomplete (Section 12). */
final class SearchController extends ApiController
{
    public function __construct(private readonly SearchService $search)
    {
    }

    /** GET /api/v1/search?q=&filter[category]=&city=&limit= */
    public function search(Request $request): JsonResponse
    {
        $filters = (array) $request->query('filter', []);

        $result = $this->search->search(
            (string) $request->query('q', ''),
            $filters,
            $request->query('city'),
            min((int) $request->query('limit', 20), 100),
        );

        return $this->ok($result['hits'], [
            'total'         => $result['total'],
            'facets'        => $result['facets'],
            'backend'       => $result['backend'],
            'city_filtered' => $result['city_filtered'],
        ]);
    }

    /** GET /api/v1/search/autocomplete?q= */
    public function autocomplete(Request $request): JsonResponse
    {
        $prefix = (string) $request->query('q', '');
        if (mb_strlen($prefix) < 1) {
            return $this->ok([]);
        }

        return $this->ok($this->search->suggest($prefix, min((int) $request->query('limit', 10), 25)));
    }
}
