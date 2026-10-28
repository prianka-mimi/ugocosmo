<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceCategoryResource;
use App\Manager\ServiceCategoryManager;
use App\Models\ServiceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ServiceCategoryController extends Controller
{
    protected ServiceCategoryManager $categoryManager;

    public function __construct(ServiceCategoryManager $categoryManager)
    {
        $this->categoryManager = $categoryManager;
    }

    /**
     * Display a listing of the resource (Admin View).
     */
    public function index()
    {
        $statusFilter = request('status');
        $categories = $this->categoryManager->all($statusFilter);

        return view('modules.service_categories.index', compact('categories', 'statusFilter'));
    }

    /**
     * Show the form for creating a new resource (Admin View).
     */
    public function create()
    {
        return view('modules.service_categories.form', ['category' => null]);
    }

    /**
     * Store a newly created resource in storage (Admin Action).
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:service_categories,name'],
        ]);

        try {
            DB::beginTransaction();
            $data = $request->only('name');
            $data['is_active'] = $request->has('is_active');

            $this->categoryManager->create($data);
            DB::commit();

            return redirect()->route('service-categories.index')->with('success', 'Service category created successfully.');
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Service category creation failed: '.$e->getMessage());

            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Display the specified resource (Admin View).
     */
    public function show(ServiceCategory $category)
    {
        return view('modules.service_categories.show', compact('category'));
    }

    /**
     * Show the form for editing the specified resource (Admin View).
     */
    public function edit(ServiceCategory $category)
    {
        return view('modules.service_categories.form', compact('category'));
    }

    /**
     * Update the specified resource in storage (Admin Action).
     */
    public function update(Request $request, ServiceCategory $category)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:service_categories,name,'.$category->id],
        ]);

        try {
            DB::beginTransaction();
            $data = $request->only('name');
            $data['is_active'] = $request->has('is_active');

            $this->categoryManager->update($category, $data);
            DB::commit();

            return redirect()->route('service-categories.index')->with('success', 'Service category updated successfully.');
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Service category update failed: '.$e->getMessage());

            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Remove the specified resource from storage (Admin Action).
     */
    public function destroy(ServiceCategory $category)
    {
        try {
            DB::beginTransaction();
            $this->categoryManager->delete($category);
            DB::commit();

            return redirect()->route('service-categories.index')->with('success', 'Service category deleted successfully.');
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Service category deletion failed: '.$e->getMessage());

            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Toggle the active status of a category (Admin Action).
     */
    public function toggleStatus(ServiceCategory $category)
    {
        try {
            DB::beginTransaction();
            $this->categoryManager->toggleStatus($category);
            DB::commit();

            return back()->with('success', 'Service category status updated successfully.');
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Service category status toggle failed: '.$e->getMessage());

            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Get only active categories for API consumers.
     */
    public function getActive(): JsonResponse
    {
        try {
            $this->data = ServiceCategoryResource::collection($this->categoryManager->active());
            $this->status_message = 'Active service categories retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Active service categories retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
