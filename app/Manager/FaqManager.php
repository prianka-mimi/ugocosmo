<?php

namespace App\Manager;

use App\Models\Faq;

class FaqManager
{
    public function all(?string $status = null)
    {
        $query = Faq::query();

        if ($status) {
            $query->where('status', $status);
        }

        return $query->orderBy('sort_order', 'asc')->get();
    }

    public function create(array $data): Faq
    {
        return Faq::create([
            'sort_order' => $data['sort_order'] ?? 0,
            'question' => $data['question'],
            'answer' => $data['answer'],
            'status' => $data['status'] ?? 'active',
        ]);
    }

    public function update(Faq $faq, array $data): Faq
    {
        $faq->update([
            'sort_order' => $data['sort_order'] ?? $faq->sort_order,
            'question' => $data['question'] ?? $faq->question,
            'answer' => $data['answer'] ?? $faq->answer,
            'status' => $data['status'] ?? $faq->status,
        ]);

        return $faq->fresh();
    }

    public function delete(Faq $faq): bool
    {
        return $faq->delete();
    }

    public function toggleStatus(Faq $faq): Faq
    {
        $faq->update(['status' => $faq->status === 'active' ? 'inactive' : 'active']);

        return $faq->fresh();
    }
}
