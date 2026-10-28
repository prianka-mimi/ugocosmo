<?php

namespace App\Http\Controllers;

use App\Manager\TermsGuidelinesManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class TermsGuidelinesController extends Controller
{
    protected TermsGuidelinesManager $termsGuidelinesManager;

    public function __construct(TermsGuidelinesManager $termsGuidelinesManager)
    {
        $this->termsGuidelinesManager = $termsGuidelinesManager;
    }

    public function index(): JsonResponse
    {
        try {
            $this->data = $this->termsGuidelinesManager->index();
            $this->status_message = 'Terms and guidelines retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Terms and guidelines retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
