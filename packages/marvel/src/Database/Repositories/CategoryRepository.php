<?php


namespace Marvel\Database\Repositories;

use Illuminate\Http\Request;
use Marvel\Database\Models\Category;
use Marvel\Http\Requests\CategoryCreateRequest;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;



class CategoryRepository extends BaseRepository
{
    /**
     * @var array
     */
    protected $fieldSearchable = [
        'name'        => 'like',
        'parent',
        'language',
        'type.slug',
    ];

    /**
     * Write whitelist — saveCategory/updateCategory do $request->only($dataArray),
     * so a column missing from this list is SILENTLY DROPPED. An admin toggle for
     * such a column appears to save and never persists.
     */
    protected $dataArray = [
        'name',
        'slug',
        'type_id',
        'icon',
        'image',
        'banner_image',
        'details',
        'language',
        'parent',
        'show_on_homepage',
        'homepage_sort_order',
        'is_active',
        'seo_title',
        'seo_description',
        'noindex',
        // GST: every product in this category inherits this rate unless it sets
        // its own tax_rate_id. GstService::categoryTaxRates already READS the
        // column — it was just never writable, so rates had to be applied
        // product by product.
        'tax_rate_id',
    ];

    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
            //
        }
    }


    /**
     * Configure the Model
     **/
    public function model()
    {
        return Category::class;
    }

    /**
     * A native <select> submits "" for its empty option, and tax_rate_id is a
     * nullable FK — "" would fail the constraint. Mirrors the same guard in
     * ProductRepository.
     */
    protected function normalizeNullableIds(array $data): array
    {
        foreach (['tax_rate_id'] as $col) {
            if (array_key_exists($col, $data) && is_string($data[$col]) && trim($data[$col]) === '') {
                $data[$col] = null;
            }
        }
        return $data;
    }

    public function saveCategory(Request $request) {
        $data = $this->normalizeNullableIds($request->only($this->dataArray));
        $data['slug'] = $this->makeSlug($request);
        return $this->create($data);
    }
    
    public function updateCategory($request, $category)
    {
        $data = $this->normalizeNullableIds($request->only($this->dataArray));
        if (!empty($request->slug) &&  $request->slug != $category['slug']) {
            $data['slug'] = $this->makeSlug($request);
        }
        $category->update($data);
        return $this->findOrFail($category->id);
    }
}
