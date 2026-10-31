<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductIndexRequest;
use App\Http\Requests\ProductStoreRequest;
use App\Http\Requests\ProductUpdateRequest;
use App\Http\Resources\ProductResource;
use App\Manager\ProductManager;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class ProductController extends Controller
{
    protected ProductManager $productManager;

    public function __construct(ProductManager $productManager)
    {
        $this->productManager = $productManager;
    }

    public function index(ProductIndexRequest $request): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 20);
            $this->data = ProductResource::collection($this->productManager->index($request->validated(), $perPage))->response()->getData();
            $this->status_message = 'Products retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Products retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function mine(Request $request): JsonResponse
    {
        try {
            $result = $this->productManager->mine($request->all());
            if ($result instanceof LengthAwarePaginator) {
                $this->data = ProductResource::collection($result)->response()->getData();
            } else {
                $this->data = $result;
            }
            $this->status_message = 'Your products retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('My products retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function store(ProductStoreRequest $request): JsonResponse
    {
        try {
            $this->data = ProductResource::make($this->productManager->store($request->validated()));
            $this->status_message = 'Product created successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Product creation error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Resolve a product model from internal ID or external ID (AliExpress, Shopify, Etsy).
     */
    protected function resolveProduct(string|int|Product $product): Product
    {
        $user = Auth::guard('jwt')->user() ?? Auth::user();
        $candidateProfileIds = [];
        if ($user) {
            $candidateProfileIds = Profile::where('user_id', $user->id)->pluck('id')->all();
            if ($user->profile?->id && ! in_array((int) $user->profile->id, $candidateProfileIds, true)) {
                $candidateProfileIds[] = (int) $user->profile->id;
            }
        }
        $candidateProfileIds = array_values(array_filter(array_unique($candidateProfileIds)));

        if ($product instanceof Product) {
            // If the resolved model belongs to another seller, but this user owns a listing
            // with the same external product ID, swap to the authenticated user's product.
            if ($user && ! ($user->role === 'admin' || (method_exists($user, 'hasRole') && $user->hasRole('admin')))) {
                $isOwner = in_array((int) $product->seller_id, $candidateProfileIds, true)
                    || ($product->seller && (int) $product->seller->user_id === (int) $user->id)
                    || ($product->aliexpressAccount && (int) $product->aliexpressAccount->user_id === (int) $user->id);

                if (! $isOwner) {
                    $extId = $product->aliexpress_product_id ?: ($product->shopify_product_id ?: $product->etsy_listing_id);
                    if ($extId) {
                        $owned = Product::where(function ($q) use ($candidateProfileIds, $user) {
                            if (! empty($candidateProfileIds)) {
                                $q->whereIn('seller_id', $candidateProfileIds);
                            }
                            $q->orWhereHas('aliexpressAccount', fn ($aq) => $aq->where('user_id', $user->id))
                                ->orWhereHas('seller', fn ($sq) => $sq->where('user_id', $user->id));
                        })->where(function ($q) use ($extId) {
                            $q->where('aliexpress_product_id', (string) $extId)
                                ->orWhere('shopify_product_id', (string) $extId)
                                ->orWhere('etsy_listing_id', (string) $extId);
                        })->first();

                        if ($owned) {
                            return $owned;
                        }
                    }
                }
            }

            return $product;
        }

        $id = (string) $product;

        // 1. If user is authenticated, prioritize product owned by this seller
        if ($user) {
            $userProduct = Product::where(function ($q) use ($candidateProfileIds, $user) {
                if (! empty($candidateProfileIds)) {
                    $q->whereIn('seller_id', $candidateProfileIds);
                }
                $q->orWhereHas('aliexpressAccount', fn ($aq) => $aq->where('user_id', $user->id))
                    ->orWhereHas('seller', fn ($sq) => $sq->where('user_id', $user->id));
            })->where(function ($q) use ($id) {
                $q->where('aliexpress_product_id', $id)
                    ->orWhere('shopify_product_id', $id)
                    ->orWhere('etsy_listing_id', $id)
                    ->orWhere('id', $id);
            })->first();

            if ($userProduct) {
                return $userProduct;
            }
        }

        // 2. Try finding by primary key (id)
        if (is_numeric($id) && strlen($id) <= 10) {
            $model = Product::find($id);
            if ($model) {
                return $model;
            }
        }

        // 3. Try finding by external IDs or string ID
        $model = Product::where('aliexpress_product_id', $id)
            ->orWhere('shopify_product_id', $id)
            ->orWhere('etsy_listing_id', $id)
            ->orWhere('id', $id)
            ->first();

        if ($model) {
            return $model;
        }

        throw new ModelNotFoundException("Product {$product} not found.");
    }

    public function show(string|int|Product $product): JsonResponse
    {
        try {
            $model = $this->resolveProduct($product);
            $this->data = ProductResource::make($this->productManager->show($model));
            $this->status_message = 'Product retrieved successfully';
            $this->status_code = 200;
        } catch (ModelNotFoundException $e) {
            $this->status_message = 'Product not found.';
            $this->status_code = 404;
        } catch (Throwable $e) {
            Log::error('Product retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage() ?: 'Product retrieval failed. Please try again.';
            $this->status_code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
        }

        return $this->JsonResponseFormat();
    }

    public function update(ProductUpdateRequest $request, string|int|Product $product): JsonResponse
    {
        try {
            $model = $this->resolveProduct($product);
            $this->data = ProductResource::make($this->productManager->update($model, $request->validated()));
            $this->status_message = 'Product updated successfully';
            $this->status_code = 200;
        } catch (ModelNotFoundException $e) {
            $this->status_message = 'Product not found.';
            $this->status_code = 404;
        } catch (HttpException $e) {
            $this->status_message = $e->getMessage() ?: 'Product not found.';
            $this->status_code = $e->getStatusCode();
        } catch (Throwable $e) {
            Log::error('Product update error: '.$e->getMessage());
            $this->status_message = $e->getMessage() ?: 'Product update failed. Please try again.';
            $this->status_code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
        }

        return $this->JsonResponseFormat();
    }

    public function destroy(string|int|Product $product): JsonResponse
    {
        try {
            $model = $this->resolveProduct($product);
            $this->productManager->destroy($model);
            $this->data = [];
            $this->status_message = 'Product deleted successfully';
            $this->status_code = 200;
        } catch (ModelNotFoundException $e) {
            $this->status_message = 'Product not found.';
            $this->status_code = 404;
        } catch (HttpException $e) {
            $this->status_message = $e->getMessage() ?: 'Product not found.';
            $this->status_code = $e->getStatusCode();
        } catch (Throwable $e) {
            Log::error('Product deletion error: '.$e->getMessage());
            $this->status_message = $e->getMessage() ?: 'Product deletion failed. Please try again.';
            $this->status_code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Toggle Product Active Status
     */
    /**
     * GET /api/products/{product}/shipping-options
     *
     * Returns the shipping options the seller has enabled for this product.
     * Used by buyer on product detail, cart, and checkout pages.
     */
    public function shippingOptions(string|int|Product $product): JsonResponse
    {
        try {
            $model = $this->resolveProduct($product);
            $options = array_map(fn ($opt) => $opt->toArray(), $model->getEnabledShippingOptions());
            $this->data = ['shipping_options' => $options];
            $this->status_message = 'Product shipping options retrieved successfully';
            $this->status_code = 200;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $this->status_message = 'Product not found.';
            $this->status_code = 404;
        } catch (Throwable $e) {
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function toggleStatus(Request $request, string|int|Product $product): JsonResponse
    {
        try {
            $model = $this->resolveProduct($product);

            $targetActive = null;
            if ($request->has('is_active')) {
                $targetActive = filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            } elseif ($request->has('status')) {
                $statusVal = strtolower((string) $request->input('status'));
                if (in_array($statusVal, ['active', '1', 'true'], true)) {
                    $targetActive = true;
                } elseif (in_array($statusVal, ['inactive', '0', 'false', 'pending'], true)) {
                    $targetActive = false;
                }
            }

            $this->data = ProductResource::make($this->productManager->toggleStatus($model, $targetActive));
            $this->status_message = 'Product status updated successfully';
            $this->status_code = 200;
        } catch (ModelNotFoundException $e) {
            $this->status_message = 'Product not found.';
            $this->status_code = 404;
        } catch (HttpException $e) {
            $this->status_message = $e->getMessage() ?: 'Product not found.';
            $this->status_code = $e->getStatusCode();
        } catch (Throwable $e) {
            Log::error('Product status toggle error: '.$e->getMessage());
            $this->status_message = $e->getMessage() ?: 'Product status update failed. Please try again.';
            $this->status_code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
        }

        return $this->JsonResponseFormat();
    }

    public function activate(Request $request, string|int|Product $product): JsonResponse
    {
        $request->merge(['is_active' => true]);

        return $this->toggleStatus($request, $product);
    }

    public function deactivate(Request $request, string|int|Product $product): JsonResponse
    {
        $request->merge(['is_active' => false]);

        return $this->toggleStatus($request, $product);
    }
}
