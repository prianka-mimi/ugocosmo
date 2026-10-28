<?php

namespace App\Http\Controllers;

use App\Http\Requests\FaqStoreRequest;
use App\Http\Requests\FaqUpdateRequest;
use App\Manager\FaqManager;
use App\Models\Faq;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class FaqController extends Controller
{
    protected FaqManager $faqManager;

    public function __construct(FaqManager $faqManager)
    {
        $this->faqManager = $faqManager;
    }

    public function apiIndex(): JsonResponse
    {
        try {
            $this->data = $this->faqManager->all('active');
            $this->status_message = 'FAQs retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('FAQ API retrieve failed: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function index()
    {
        $statusFilter = request('status');
        $faqs = $this->faqManager->all($statusFilter);

        return view('modules.faq.index', compact('faqs', 'statusFilter'));
    }

    public function create()
    {
        return view('modules.faq.form', ['faq' => null]);
    }

    public function store(FaqStoreRequest $request)
    {
        try {
            DB::beginTransaction();
            $this->faqManager->create($request->validated());
            DB::commit();

            return redirect()->route('faq.index')->with('success', 'FAQ created successfully.');
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('FAQ creation failed: '.$e->getMessage());

            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show(Faq $faq)
    {
        return view('modules.faq.show', compact('faq'));
    }

    public function edit(Faq $faq)
    {
        return view('modules.faq.form', compact('faq'));
    }

    public function update(FaqUpdateRequest $request, Faq $faq)
    {
        try {
            DB::beginTransaction();
            $this->faqManager->update($faq, $request->validated());
            DB::commit();

            return redirect()->route('faq.index')->with('success', 'FAQ updated successfully.');
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('FAQ update failed: '.$e->getMessage());

            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function destroy(Faq $faq)
    {
        try {
            DB::beginTransaction();
            $this->faqManager->delete($faq);
            DB::commit();

            return redirect()->route('faq.index')->with('success', 'FAQ deleted successfully.');
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('FAQ deletion failed: '.$e->getMessage());

            return back()->with('error', $e->getMessage());
        }
    }

    public function toggleStatus(Faq $faq)
    {
        try {
            DB::beginTransaction();
            $this->faqManager->toggleStatus($faq);
            DB::commit();

            return back()->with('success', 'FAQ status updated.');
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('FAQ status toggle failed: '.$e->getMessage());

            return back()->with('error', $e->getMessage());
        }
    }
}
