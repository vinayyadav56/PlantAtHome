<?php

if (!function_exists('getResourceData')) {
    function getResourceData($data, array $extra = [])
    {
        $item = [
            'id'   => $data?->id,
            'name' => $data?->name,
            'slug' => $data?->slug,
            'logo' => $data?->logo
        ];

        if (!empty($extra)) {
            foreach ($extra as $key) {
                if (isset($key)) {
                    $item[$key] = $data?->$key;
                }
            }
        }
        return $item;
    }
}

if (!function_exists('getResourceCollection')) {
    function getResourceCollection($data, array $extra = [])
    {
        return collect($data)->map(function ($item) use ($extra) {
            $item = [
                'id'   => $item['id'],
                'name' => $item['name'],
                'slug' => $item['slug'],
            ];

            if (!empty($extra)) {
                foreach ($extra as $key) {
                    if (isset($item[$key])) {
                        $item[$key] = $item[$key];
                    }
                }
            }
            return $item;
        })->toArray();
    }
}

if (!function_exists('getVariations')) {
    function getVariations($data)
    {
        $variations = [];
        // One row per distinct (attribute, value): clients render one chip per
        // row and group them by attribute slug, so a product attached to the
        // same value twice showed "Small Medium Large Small Medium Large".
        // 2026_09_17_100000 cleaned the data; this keeps the web and the mobile
        // app right even if a stray duplicate ever reappears.
        $seen = [];
        foreach ($data as $item) {
            $key = mb_strtolower(trim((string) ($item['attribute']['slug'] ?? $item['attribute_id'])) . '|' . trim((string) $item['value']));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $variations[] = [
                'id'           => $item['id'],
                'slug'         => $item['slug'],
                'attribute_id' => $item['attribute_id'],
                'value'        => $item['value'],
                'language'     => $item['language'],
                'meta'         => $item['meta'],
                'translated_languages' => $item['translated_languages'],
                'attribute' => $item['attribute'],
            ];
        }
        return $variations;
    }
}
