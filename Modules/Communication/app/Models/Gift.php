<?php

declare(strict_types=1);

namespace Modules\Communication\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Gift extends Model
{
    protected $table = 'gifts';

    protected $fillable = [
        'title',
        'secondary_title',
        'icon_url',
        'sort_order',
        'coin_cost',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'coin_cost' => 'integer',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function getIconUrlAttribute($value): ?string
    {
        if (empty($value)) {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $appUrl = (string) config('app.url');
            if (str_contains($value, $appUrl)) {
                $value = parse_url($value, PHP_URL_PATH);
            }
        }

        return filter_var($value, FILTER_VALIDATE_URL) ? $value : url($value);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
