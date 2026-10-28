<?php

namespace App\Manager;

use App\Models\Page;

class PageManager
{
    public function all(?string $status = null)
    {
        $query = Page::query();

        if ($status) {
            $query->where('status', $status);
        }

        return $query->orderBy('sort_order', 'asc')->get();
    }

    public function create(array $data): Page
    {
        return Page::create([
            'sort_order' => $data['sort_order'] ?? 0,
            'slug' => $data['slug'],
            'title' => $data['title'],
            'content' => $data['content'],
            'status' => $data['status'] ?? 'active',
        ]);
    }

    public function update(Page $page, array $data): Page
    {
        $page->update([
            'sort_order' => $data['sort_order'] ?? $page->sort_order,
            'slug' => $data['slug'] ?? $page->slug,
            'title' => $data['title'] ?? $page->title,
            'content' => $data['content'] ?? $page->content,
            'status' => $data['status'] ?? $page->status,
        ]);

        return $page->fresh();
    }

    public function delete(Page $page): bool
    {
        return $page->delete();
    }

    public function toggleStatus(Page $page): Page
    {
        $page->update(['status' => $page->status === 'active' ? 'inactive' : 'active']);

        return $page->fresh();
    }
}
