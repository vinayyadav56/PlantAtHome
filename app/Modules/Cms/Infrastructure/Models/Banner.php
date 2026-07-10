<?php

namespace App\Modules\Cms\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    use HasUuid;

    protected $table = 'cms_banners';

    protected $fillable = ['uuid', 'title', 'image_url', 'link', 'position', 'city_uuid', 'sort', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'sort' => 'integer'];
}
