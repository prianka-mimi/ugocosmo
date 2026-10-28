<?php

declare(strict_types=1);

namespace Modules\Communication\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait UniqueIdentifierTrait
{
    public function generateUniqueId(string $prefix = ''): string
    {
        return $prefix.Str::lower(Str::ulid()->toBase32());
    }

    /**
     * @param  class-string<Model>|null  $modelClass
     */
    public function generateSlug(string $string, $modelClass = null): string
    {
        $baseSlug = Str::slug($string);
        $slug = $baseSlug;

        if ($modelClass === null) {
            return $slug;
        }

        $suffix = 1;
        while ($modelClass::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
