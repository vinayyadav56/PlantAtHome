<?php

namespace Marvel\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Type;

/**
 * Tier 2 — safe for all environments (updateOrCreate).
 * Seeds all 193 plant categories from the CSV catalog. Each is linked to the
 * 'plants' type. Images are Unsplash URLs matched by category keyword group;
 * unknown categories fall back to a generic green-plant image.
 */
class PlantAtHomeCategoryBulkSeeder extends Seeder
{
    /** Unsplash photo ID → Pickbazar image JSON. */
    private function img(int $id, string $photoId): array
    {
        $base = "https://images.unsplash.com/photo-{$photoId}?auto=format&fit=crop&q=80";
        return [
            'id'        => $id,
            'original'  => "{$base}&w=900",
            'thumbnail' => "{$base}&w=400",
        ];
    }

    /**
     * Keyword-based Unsplash image lookup. Keys are lowercase category-name
     * fragments; value is an Unsplash photo ID.
     */
    private function unsplashForCategory(string $name): string
    {
        $n = strtolower($name);

        // Exact + starts-with matches first
        $map = [
            'cactus'         => '1459411552884-841db9b3cc2a',
            'succulent'      => '1509423350716-97f2360af2e4',
            'orchid'         => '1504785651975-454e19397e3e',
            'rose'           => '1490750967868-88aa4486c946',
            'palm'           => '1446071103084-c257b5f70672',
            'fern'           => '1597305877032-0c6b8e64e2e3',
            'bamboo'         => '1507003211169-0a1dd7228f2d',
            'bonsai'         => '1520412099551-62b6bafeb5bb',
            'herb'           => '1466692476868-aef1dfb1e735',
            'lavender'       => '1528360983277-13d401cdc186',
            'aquatic'        => '1518495973542-4542adad0205',
            'aquarium'       => '1535591273051-8e3b28de4b0c',
            'floating'       => '1518495973542-4542adad0205',
            'wetland'        => '1518495973542-4542adad0205',
            'marsh'          => '1518495973542-4542adad0205',
            'carnivorous'    => '1509423350716-97f2360af2e4',
            'air plant'      => '1593482892290-f54927ae1bb6',
            'bromeliad'      => '1585320806297-9794b3e4aaae',
            'grass'          => '1558618666-fcd25c85cd64',
            'climber'        => '1416879595882-3373a0480b5b',
            'vine'           => '1416879595882-3373a0480b5b',
            'tropical'       => '1567591370168-b7cf3d6fa7af',
            'foliage'        => '1545241047-6083a3684587',
            'indoor'         => '1545241047-6083a3684587',
            'outdoor'        => '1416879595882-3373a0480b5b',
            'flowering'      => '1490750967868-88aa4486c946',
            'fruit'          => '1619566636858-adf3ef46400b',
            'vegetable'      => '1542838132-92c53300491e',
            'medicinal'      => '1466692476868-aef1dfb1e735',
            'tree'           => '1567591370168-b7cf3d6fa7af',
            'shrub'          => '1599598425947-5202edd56bde',
            'rare'           => '1614594975525-e45190c55d0b',
            'native'         => '1416879595882-3373a0480b5b',
            'sacred'         => '1545241047-6083a3684587',
            'terrarium'      => '1509423350716-97f2360af2e4',
            'hanging'        => '1622547748225-3fc4abd2cca0',
            'ground cover'   => '1558618666-fcd25c85cd64',
            'landscape'      => '1416879595882-3373a0480b5b',
            'conifer'        => '1567591370168-b7cf3d6fa7af',
            'cycad'          => '1567591370168-b7cf3d6fa7af',
            'topiary'        => '1520412099551-62b6bafeb5bb',
            'hedge'          => '1416879595882-3373a0480b5b',
            'seasonal'       => '1490750967868-88aa4486c946',
            'epiphytic'      => '1585320806297-9794b3e4aaae',
            'spice'          => '1466692476868-aef1dfb1e735',
            'plantation'     => '1416879595882-3373a0480b5b',
            'timber'         => '1567591370168-b7cf3d6fa7af',
            'shade'          => '1567591370168-b7cf3d6fa7af',
            'rainforest'     => '1567591370168-b7cf3d6fa7af',
            'alpine'         => '1416879595882-3373a0480b5b',
            'coastal'        => '1416879595882-3373a0480b5b',
            'desert'         => '1459411552884-841db9b3cc2a',
            'trailing'       => '1622547748225-3fc4abd2cca0',
            'fodder'         => '1558618666-fcd25c85cd64',
            'fragrant'       => '1490750967868-88aa4486c946',
        ];

        foreach ($map as $keyword => $photoId) {
            if (str_contains($n, $keyword)) {
                return $photoId;
            }
        }

        // Generic fallback: lush indoor plant
        return '1545241047-6083a3684587';
    }

    /** All 193 categories from the plant CSV. */
    private array $categories = [
        'Air Plant','Alpine Flower','Aquarium Carpet Plant','Aquarium Moss',
        'Aquarium Plant','Aquatic Aroid','Aquatic Fern','Aquatic Flowering Plant',
        'Aquatic Giant Plant','Aquatic Plant','Aquatic Sacred Plant','Araucaria',
        'Aromatic Grass','Bamboo','Bonsai','Bonsai Plant','Bromeliad',
        'Butterfly Plant','Cactus','Cactus Fruit','Cactus Fruit Plant',
        'Carnivorous Aquatic Plant','Carnivorous Plant','Caudiciform Plant',
        'Climber','Coastal Plant','Conifer','Cycad','Desert Plant',
        'Epiphytic Fern','Epiphytic Plant','Exotic Flowering Climber',
        'Exotic Flowering Tree','Exotic Fruit Tree','Fern','Floating Plant',
        'Flowering Climber','Flowering Herb','Flowering Plant','Flowering Shrub',
        'Flowering Succulent','Flowering Tree','Flowering Vine','Fodder Plant',
        'Foliage Plant','Foliage Shrub','Foliage Tree','Fragrant Plant',
        'Fragrant Tree','Fruit Plant','Fruit Shrub','Fruit Tree','Fruit Vine',
        'Fruiting Ornamental','Fruiting Shrub','Giant Climber','Giant Fern',
        'Grass','Ground Cover','Ground Orchid','Hanging Cactus','Hanging Plant',
        'Hanging Succulent','Hedge Plant','Hedge Shrub','Herb','Herb Tree',
        'Herb Vegetable','Herbaceous Flower','Herbal Ornamental',
        'Indoor Fern','Indoor Flowering Plant','Indoor Foliage Plant',
        'Indoor Hanging Plant','Indoor Palm','Indoor Plant','Indoor Premium Plant',
        'Indoor Tree','Indoor Vine','Indoor/Outdoor','Landscape Plant',
        'Landscape Tree','Large Seed Climber','Lavender','Leafy Vegetable',
        'Marsh Plant','Medicinal Alpine Herb','Medicinal Climber',
        'Medicinal Flower','Medicinal Fruit Tree','Medicinal Grass',
        'Medicinal Groundcover','Medicinal Herb','Medicinal Himalayan Plant',
        'Medicinal Plant','Medicinal Shrub','Medicinal Succulent',
        'Medicinal Succulent Vine','Medicinal Tree','National Aquatic Flower',
        'Native Flowering Plant','Native Flowering Shrub','Native Orchid',
        'Native Rare Plant','Native Shrub','Native Tree','Orchid','Orchid Hybrid',
        'Orchid Vine','Ornamental Climber','Ornamental Grass','Ornamental Tree',
        'Outdoor Accent Plant','Palm','Pink Flowering Vine','Plantation Crop',
        'Rainforest Tree','Rare Alpine Flower','Rare Aroid','Rare Begonia',
        'Rare Climber','Rare Conifer','Rare Endangered Plant',
        'Rare Endangered Tree','Rare Endemic Plant','Rare Exotic Flower',
        'Rare Exotic Plant','Rare Fern','Rare Flowering Climber',
        'Rare Flowering Plant','Rare Flowering Shrub','Rare Flowering Tree',
        'Rare Foliage Plant','Rare Fruit Plant','Rare Fruit Shrub',
        'Rare Fruit Tree','Rare Gesneriad','Rare Ground Orchid',
        'Rare Groundcover','Rare Himalayan Plant',
        'Rare Medicinal Alpine Plant','Rare Medicinal Herb',
        'Rare Medicinal Orchid','Rare Medicinal Plant','Rare Medicinal Vine',
        'Rare Native Climber','Rare Native Flower','Rare Native Lily',
        'Rare Native Orchid','Rare Native Plant','Rare Native Shrub',
        'Rare Native Tree','Rare Orchid','Rare Palm','Rare Shrub','Rare Tree',
        'Root Vegetable','Rose','Sacred Alpine Flower','Sacred Conifer',
        'Sacred Flowering Tree','Sacred Tree','Seasonal Flower',
        'Seasonal Plant','Shade Tree','Spice Orchid','Spice Plant',
        'Spice Tree','Spice Vine','Succulent','Succulent Bonsai',
        'Succulent Flower','Succulent Peperomia','Succulent Plant',
        'Succulent Shrub','Succulent Tree','Terrarium Plant','Terrarium Vine',
        'Timber Tree','Topiary Plant','Trailing Plant','Trailing Succulent',
        'Tree','Tropical Flower','Tropical Foliage','Tropical Plant',
        'Tropical Vine','Vegetable','Vegetable Crop','Vegetable Herb',
        'Vegetable Vine','Wetland Grass','Wetland Plant',
    ];

    public function run(): void
    {
        $type = Type::where('slug', 'plants')->where('language', 'en')->first();

        if (!$type) {
            $this->command->warn('[Tier 2] Plants type not found — run PlantAtHomeTypeSeeder first.');
            return;
        }

        $imgId = 19000;
        $total = 0;

        foreach ($this->categories as $name) {
            $slug = Str::slug($name);
            Category::updateOrCreate(
                ['slug' => $slug, 'language' => 'en'],
                [
                    'name'     => $name,
                    'icon'     => 'Leaf',
                    'details'  => null,
                    'type_id'  => $type->id,
                    'language' => 'en',
                    'parent'   => null,
                    'image'    => $this->img(++$imgId, $this->unsplashForCategory($name)),
                ]
            );
            $total++;
        }

        $this->command->info("[Tier 2] PlantAtHome bulk categories seeded: {$total}");
    }
}
