<?php

namespace App\Http\Controllers;

use App\Manager\HomepageManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class HomepageController extends Controller
{
    protected HomepageManager $homepageManager;

    public function __construct(HomepageManager $homepageManager)
    {
        $this->homepageManager = $homepageManager;
    }

    public function index(): JsonResponse
    {
        try {
            $this->data = $this->homepageManager->index();
            $this->status_message = 'Homepage data retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Homepage retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
