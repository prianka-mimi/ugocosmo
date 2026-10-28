<?php

namespace App\Http\Controllers;

use App\Manager\SearchManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class SearchController extends Controller
{
    protected SearchManager $searchManager;

    public function __construct(SearchManager $searchManager)
    {
        $this->searchManager = $searchManager;
    }

    public function search(Request $request): JsonResponse
    {
        try {
            $query = $request->query('q', '');
            $type = $request->query('type', 'all');
            $userType = $request->query('user_type', 'all');
            $perPage = (int) $request->query('per_page', 20);
            if ($perPage < 1) {
                $perPage = 20;
            }

            if (empty($query)) {
                $this->data = [];
                $this->status_message = 'Search query is required';
                $this->status_code = 200;

                return $this->JsonResponseFormat();
            }

            $this->data = $this->searchManager->search($query, $type, $userType, $perPage);
            $this->status_message = 'Search results retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Search error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
