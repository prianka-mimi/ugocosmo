<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductCategoryStoreRequest;
use App\Http\Requests\ProductCategoryUpdateRequest;
use App\Http\Resources\ProductCategoryResource;
use App\Models\ProductCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProductCategoryController extends Controller
{
    public function getActive(Request $request): JsonResponse
    {
        try {
            $query = ProductCategory::active();

            if ($request->has('product_type')) {
                $query->where('product_type', $request->input('product_type'));
            }

            $this->data = ProductCategoryResource::collection(
                $query->orderBy('name')->get()
            );
            $this->status_message = 'Active product categories retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Active product categories retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $query = ProductCategory::query();

            if ($request->boolean('active_only')) {
                $query->active();
            }

            if ($request->has('product_type')) {
                $query->where('product_type', $request->input('product_type'));
            }

            $this->data = ProductCategoryResource::collection($query->orderBy('name')->get());
            $this->status_message = 'Product categories retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Product categories retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function store(ProductCategoryStoreRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            $category = ProductCategory::create([
                'name' => $request->input('name'),
                'product_type' => $request->input('product_type', 'physical'),
                'is_active' => $request->boolean('is_active', true),
            ]);

            DB::commit();

            $this->data = ProductCategoryResource::make($category);
            $this->status_message = 'Product category created successfully';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Product category creation error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function show(ProductCategory $productCategory): JsonResponse
    {
        try {
            $this->data = ProductCategoryResource::make($productCategory);
            $this->status_message = 'Product category retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Product category retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function update(ProductCategoryUpdateRequest $request, ProductCategory $productCategory): JsonResponse
    {
        try {
            DB::beginTransaction();

            if ($request->has('name')) {
                $productCategory->name = $request->input('name');
            }
            if ($request->has('product_type')) {
                $productCategory->product_type = $request->input('product_type');
            }
            if ($request->has('is_active')) {
                $productCategory->is_active = $request->boolean('is_active');
            }
            $productCategory->save();

            DB::commit();

            $this->data = ProductCategoryResource::make($productCategory->fresh());
            $this->status_message = 'Product category updated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Product category update error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function destroy(ProductCategory $productCategory): JsonResponse
    {
        try {
            DB::beginTransaction();
            $productCategory->delete();
            DB::commit();

            $this->data = [];
            $this->status_message = 'Product category deleted successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Product category deletion error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
