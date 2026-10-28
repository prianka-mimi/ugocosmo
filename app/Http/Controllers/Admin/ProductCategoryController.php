<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProductCategoryController extends Controller
{
    /**
     * Display a listing of the resource (Admin View).
     */
    public function index()
    {
        $statusFilter = request('status');
        $typeFilter = request('product_type');

        $query = ProductCategory::query();

        if ($statusFilter === 'active') {
            $query->where('is_active', true);
        } elseif ($statusFilter === 'inactive') {
            $query->where('is_active', false);
        }

        if ($typeFilter) {
            $query->where('product_type', $typeFilter);
        }

        $categories = $query->orderBy('name')->get();

        return view('modules.product_categories.index', compact('categories', 'statusFilter', 'typeFilter'));
    }

    /**
     * Show the form for creating a new resource (Admin View).
     */
    public function create()
    {
        return view('modules.product_categories.form', ['category' => null]);
    }

    /**
     * Store a newly created resource in storage (Admin Action).
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:product_categories,name'],
            'product_type' => ['required', 'string', 'in:physical,digital'],
        ]);

        try {
            DB::beginTransaction();

            ProductCategory::create([
                'name' => $request->input('name'),
                'product_type' => $request->input('product_type'),
                'is_active' => $request->has('is_active'),
            ]);

            DB::commit();

            return redirect()->route('product-categories.index')->with('success', 'Product category created successfully.');
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Product category creation failed: '.$e->getMessage());

            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Display the specified resource (Admin View).
     */
    public function show(ProductCategory $category)
    {
        return view('modules.product_categories.show', compact('category'));
    }

    /**
     * Show the form for editing the specified resource (Admin View).
     */
    public function edit(ProductCategory $category)
    {
        return view('modules.product_categories.form', compact('category'));
    }

    /**
     * Update the specified resource in storage (Admin Action).
     */
    public function update(Request $request, ProductCategory $category)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:product_categories,name,'.$category->id],
            'product_type' => ['required', 'string', 'in:physical,digital'],
        ]);

        try {
            DB::beginTransaction();

            $category->update([
                'name' => $request->input('name'),
                'product_type' => $request->input('product_type'),
                'is_active' => $request->has('is_active'),
            ]);

            DB::commit();

            return redirect()->route('product-categories.index')->with('success', 'Product category updated successfully.');
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Product category update failed: '.$e->getMessage());

            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Remove the specified resource from storage (Admin Action).
     */
    public function destroy(ProductCategory $category)
    {
        try {
            DB::beginTransaction();
            $category->delete();
            DB::commit();

            return redirect()->route('product-categories.index')->with('success', 'Product category deleted successfully.');
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Product category deletion failed: '.$e->getMessage());

            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Toggle the active status of a category (Admin Action).
     */
    public function toggleStatus(ProductCategory $category)
    {
        try {
            DB::beginTransaction();
            $category->is_active = ! $category->is_active;
            $category->save();
            DB::commit();

            return back()->with('success', 'Product category status updated successfully.');
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Product category status toggle failed: '.$e->getMessage());

            return back()->with('error', $e->getMessage());
        }
    }
}
