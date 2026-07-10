<?php

namespace App\Modules\Cms\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    use HasUuid;

    protected $table = 'cms_pages';

    protected $fillable = ['uuid', 'slug', 'title', 'body', 'city_uuid', 'status', 'updated_by'];
}
