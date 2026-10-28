<?php

namespace App\Manager;

use App\Models\ServiceCategory;

class ServiceCategoryManager
{
    public function all(?string $status = null)
    {
        $query = ServiceCategory::query();

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        return $query->orderBy('name', 'asc')->get();
    }

    public function create(array $data): ServiceCategory
    {
        return ServiceCategory::create([
            'name' => $data['name'],
            'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : true,
        ]);
    }

    public function update(ServiceCategory $category, array $data): ServiceCategory
    {
        $category->update([
            'name' => $data['name'] ?? $category->name,
            'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : $category->is_active,
        ]);

        return $category->fresh();
    }

    public function delete(ServiceCategory $category): bool
    {
        return $category->delete();
    }

    public function toggleStatus(ServiceCategory $category): ServiceCategory
    {
        $category->update(['is_active' => ! $category->is_active]);

        return $category->fresh();
    }

    public function active()
    {
        return ServiceCategory::active()->orderBy('name', 'asc')->get();
    }
}
