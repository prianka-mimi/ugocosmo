<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AliExpressCategory extends Model
{
    use HasFactory;

    protected $table = 'aliexpress_categories';

    protected $fillable = [
        'aliexpress_category_id',
        'parent_id',
        'category_name',
        'locale',
        'is_leaf',
        'level',
        'children_count',
        'raw_data',
    ];

    protected $casts = [
        'aliexpress_category_id' => 'integer',
        'parent_id' => 'integer',
        'is_leaf' => 'boolean',
        'level' => 'integer',
        'children_count' => 'integer',
        'raw_data' => 'array',
    ];

    public function scopeByParent($query, ?int $parentId)
    {
        return $parentId === null
            ? $query->whereNull('parent_id')
            : $query->where('parent_id', $parentId);
    }

    public function scopeLeaves($query)
    {
        return $query->where('is_leaf', true);
    }
}
