<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

if (!function_exists('gateway_path')) {
    /**
     * Get the path to the base of the install.
     *
     * @param  string  $path
     * @return string
     */
    function gateway_path($path = '')
    {
        return __DIR__ . '/';
    }

    if (!function_exists('globalSlugify')) {

        /**
         * It takes a string, a model,  a key, and a divider, and returns a slugified string with a number
         * appended to it if the slug already exists in the database.
         * 
         * Here's a more detailed explanation:
         * 
         * The function takes three parameters:
         * 
         * - ``: The string to be slugified.
         * - ``: The model to check against. Model must pass as Product::class
         * - ``: The key to check The column name of the slug in the database.
         * - ``: The divider to use between the slug and the number.
         * 
         * The function first slugifies the string and then checks the database to see if the slug
         * already exists. If it doesn't, it returns the slug. If it does, it returns the slug with a
         * number appended to it.
         * 
         * Here's an example of how to use the function:
         * 
         * @param string slugText The text you want to slugify
         * @param string model The model you want to check against.
         * @param string key The column name of the slug in the database.
         * @param string divider The divider to use when appending the slug count to the slug.
         * 
         * @return string slug is being returned.
         */
        function globalSlugify(string $slugText, string $model, string $key = '', string $divider = '-', ?int $update = null): string
        {
            try {
                if ($update) {
                    $query = $model::where('id', '!=', $update);
                } else {
                    $query = $model::query();
                }
                $cleanString      = preg_replace("/[~`{}.'\"\!\@\#\$\%\^\&\*\(\)\_\=\+\/\?\>\<\,\[\]\:\;\|\\\]/", "", $slugText);
                $cleanString = preg_replace("/[\/_|+ -]+/", '-', $slugText);
                $slug = strtolower($cleanString);
                if ($key) {
                    $slugCount = $query->where($key, $slug)->count();
                } else {
                    $slugCount = $query->where('slug', $slug)->count();
                }
                $randomString = Str::random(3);

                if (empty($slugCount)) {
                    $slug = is_numeric($slug) ? "{$slug}{$divider}{$randomString}" : $slug;
                    return $slug;
                }
                return "{$slug}{$divider}{$randomString}";
            } catch (\Throwable $th) {
                throw $th;
            }
        }
    }

    if (!function_exists('server_environment_info')) {
        function server_environment_info()
        {
            return [
                "upload_max_filesize" => parseAttachmentUploadSize(ini_get('upload_max_filesize')) / 1024,
                "memory_limit" => ini_get('memory_limit'),
                "max_execution_time" => ini_get('max_execution_time'),
                "max_input_time" => ini_get('max_input_time'),
                "post_max_size" => parseAttachmentUploadSize(ini_get('post_max_size')) / 1024,
            ];
        }
    }

    if (!function_exists('parseAttachmentUploadSize')) {
        function parseAttachmentUploadSize($size)
        {
            $unit = preg_replace('/[^bkmgtpezy]/i', '', $size); // Remove the non-unit characters from the size.
            $size = preg_replace('/[^0-9\.]/', '', $size); // Remove the non-numeric characters from the size.
            if ($unit) {
                // Find the position of the unit in the ordered string which is the power of magnitude to multiply a kilobyte by.
                return round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
            } else {
                return round($size);
            }
        }
    }

    if (!function_exists('formatAPIResourcePaginate')) {
        function formatAPIResourcePaginate($data)
        {
            return response()->json([
                "data"           => $data['data'] ?? [],
                "current_page"   => $data['meta']['current_page'] ?? 0,
                "from"           => $data['meta']['from'] ?? 0,
                "to"             => $data['meta']['to'] ?? 0,
                "last_page"      => $data['meta']['last_page'] ?? 0,
                "path"           => $data['meta']['path'] ?? "",
                "per_page"       => $data['meta']['per_page'] ?? 0,
                "total"          => $data['meta']['total'] ?? 0,
                "next_page_url"  => $data['links']['next'] ?? "",
                "prev_page_url"  => $data['links']['prev'] ?? "",
                "last_page_url"  => $data['links']['last'] ?? "",
                "first_page_url" => $data['links']['first'] ?? "",
            ]);
        }
    }
    if (!function_exists('formatLicenseAPIResourcePaginate')) {
        function formatLicenseAPIResourcePaginate($data)
        {
            return [
                "data"           => $data['data'] ?? [],
                "current_page"   => $data['meta']['current_page'] ?? 0,
                "from"           => $data['meta']['from'] ?? 0,
                "to"             => $data['meta']['to'] ?? 0,
                "last_page"      => $data['meta']['last_page'] ?? 0,
                "path"           => $data['meta']['path'] ?? "",
                "per_page"       => $data['meta']['per_page'] ?? 0,
                "total"          => $data['meta']['total'] ?? 0,
                "next_page_url"  => $data['links']['next'] ?? "",
                "prev_page_url"  => $data['links']['prev'] ?? "",
                "last_page_url"  => $data['links']['last'] ?? "",
                "first_page_url" => $data['links']['first'] ?? "",
            ];
        }
    }
    if (!function_exists("Role")) {

        function Role(User $user): string
        {
            if ($user->hasPermissionTo(Permission::SUPER_ADMIN)) {
                return Permission::SUPER_ADMIN;
            } else if ($user->hasPermissionTo(Permission::STORE_OWNER) && !$user->hasPermissionTo(Permission::SUPER_ADMIN)) {
                return Permission::STORE_OWNER;
            } else if ($user->hasPermissionTo(Permission::STAFF)) {
                return Permission::STAFF;
            } else {
                return Permission::CUSTOMER;
            }
        }
    }




    if (!function_exists("getConfig")) {
        function getConfig(): array | bool
        {
            try {
                $folderPath =  storage_path('app/shop/');
                if (!File::exists($folderPath)) {
                    File::makeDirectory($folderPath, 0777, true, true);
                }
                $fileName = $folderPath . "shop.config.json";
                if (file_exists($fileName)) {
                    $json_data = file_get_contents($fileName);
                    $data = Crypt::decrypt($json_data);
                    $json_data_to_array = json_decode($data, true);
                    return $json_data_to_array;
                }
                return false;
            } catch (Exception $e) {
                return false;
            }
        }
    }

    if (!function_exists("formatCurrency")) {
        function formatCurrency(float $amount, string $currency = "USD", string $locale = "en_US")
        {
            $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
            return $formatter->formatCurrency($amount, $currency);
        }
    }

    if (!function_exists('sizeValueIds')) {
        /**
         * The Size attribute's value ids, keyed by size name — the ONE place that
         * resolves them.
         *
         * Four copies of this used to live in the size-pricing commands and the pot
         * seeder, each keying firstOrCreate on a differently-derived `shop_id`. A
         * differing shop_id minted a second attribute named "Size", products ended
         * up attached to values of both, and product pages rendered the size chips
         * twice (2026_09_17_100000_merge_duplicate_attributes cleaned that up).
         *
         * Attributes are GLOBAL in the single-shop model (2026_07_12_000200 nulls
         * shop_id), so shop_id is a create-time default here and never a lookup key.
         *
         * @param  array<int, string> $sizes
         * @return array<string, int> size name => attribute_value_id
         */
        function sizeValueIds(array $sizes): array
        {
            $attribute = \Marvel\Database\Models\Attribute::firstOrCreate(
                ['slug' => 'size', 'language' => 'en'],
                ['name' => 'Size', 'shop_id' => null]
            );

            $ids = [];
            foreach ($sizes as $size) {
                $ids[$size] = (int) \Marvel\Database\Models\AttributeValue::firstOrCreate(
                    ['attribute_id' => $attribute->id, 'value' => $size, 'language' => 'en'],
                    ['slug' => Str::slug($size), 'meta' => null]
                )->id;
            }

            return $ids;
        }
    }

    if (!function_exists('allSizeValueIds')) {
        /**
         * Every value id under the Size attribute — for detaching stale sizes
         * before re-attaching the current set.
         *
         * @return array<int, int>
         */
        function allSizeValueIds(): array
        {
            return \Marvel\Database\Models\AttributeValue::whereHas(
                'attribute',
                fn ($q) => $q->where('slug', 'size')->where('language', 'en')
            )->pluck('id')->map(fn ($id) => (int) $id)->all();
        }
    }
}
