<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AliExpressCategoryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'aliexpress_category_id' => $this->aliexpress_category_id,
            'parent_id' => $this->parent_id,
            'name' => $this->category_name,
            'locale' => $this->locale,
            'is_leaf' => (bool) $this->is_leaf,
            'level' => $this->level,
            'children_count' => $this->children_count,
        ];
    }
}
