<?php

namespace App\Manager;

use App\Models\Page;

class TermsGuidelinesManager
{
    public function index(): array
    {
        $slugs = [
            'terms-and-conditions',
            'community-guidelines',
        ];

        $pages = Page::active()
            ->whereIn('slug', $slugs)
            ->get()
            ->keyBy('slug');

        if (! $pages->has('terms-and-conditions') || ! $pages->has('community-guidelines')) {
            abort(404);
        }

        return [
            'terms_and_conditions' => $this->format($pages->get('terms-and-conditions'), 'I have read and accept the Terms & Conditions'),
            'community_guidelines' => $this->format($pages->get('community-guidelines'), 'I have read and accept the Community Guidelines'),
        ];
    }

    protected function format(Page $page, string $acceptanceText): array
    {
        return [
            'slug' => $page->slug,
            'title' => $page->title,
            'content' => $page->content,
            'acceptance_text' => $acceptanceText,
        ];
    }
}
