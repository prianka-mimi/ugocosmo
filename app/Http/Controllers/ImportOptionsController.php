<?php

namespace App\Http\Controllers;

use App\Models\AliExpressAccount;
use App\Models\EtsyAccount;
use App\Repositories\ShopifyStoreRepository;
use App\Support\ApiResponse;
use App\Traits\ResolvesAuthUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImportOptionsController extends Controller
{
    use ResolvesAuthUser;

    public function __construct(
        protected ShopifyStoreRepository $shopifyStores,
    ) {}

    /**
     * Return all available import sources with their connection status.
     *
     * Flutter uses this single endpoint to render the "Import product" modal.
     * Each source includes:
     *   - connected: bool
     *   - connect_message: shown when not connected
     *   - connect_endpoint: POST endpoint to initiate OAuth / upload
     *   - import_endpoint: POST endpoint to trigger import (null when not connected)
     *   - account: summary of the connected account (null when not connected)
     *
     * GET /api/import/options
     */
    public function index(): JsonResponse
    {
        try {
            $user = $this->user();

            $aliAccount = AliExpressAccount::byUser($user->id)->active()->first();
            $etsyAccount = EtsyAccount::byUser($user->id)->active()->first();
            $shopifyStore = $this->shopifyStores->getByUser($user->id)->first();

            $sources = [
                [
                    'source' => 'aliexpress',
                    'label' => 'AliExpress',
                    'connected' => $aliAccount !== null,
                    'connect_message' => 'Connect AliExpress with your app to import products from AliExpress',
                    'connect_endpoint' => url('api/aliexpress/connect'),
                    'import_endpoint' => $aliAccount ? url('api/aliexpress/import') : null,
                    'search_endpoint' => $aliAccount ? url('api/aliexpress/products/search') : null,
                    'account' => $aliAccount ? [
                        'id' => $aliAccount->id,
                        'account_name' => $aliAccount->account_name,
                        'region' => $aliAccount->region,
                        'connected_at' => $aliAccount->connected_at?->toIso8601String(),
                    ] : null,
                ],
                [
                    'source' => 'shopify',
                    'label' => 'Shopify',
                    'connected' => $shopifyStore !== null,
                    'connect_message' => 'Connect Shopify with your app to import products from Shopify',
                    'connect_endpoint' => url('api/shopify/connect'),
                    'import_endpoint' => $shopifyStore ? url('api/shopify/import') : null,
                    'account' => $shopifyStore ? [
                        'id' => $shopifyStore->id,
                        'store_domain' => $shopifyStore->store_domain,
                        'shop_name' => $shopifyStore->shop_name,
                        'connected_at' => $shopifyStore->installed_at?->toIso8601String(),
                    ] : null,
                ],
                [
                    'source' => 'etsy',
                    'label' => 'Etsy',
                    'connected' => $etsyAccount !== null,
                    'connect_message' => 'Connect Etsy with your app to import products from Etsy',
                    'connect_endpoint' => url('api/etsy/connect'),
                    'import_endpoint' => $etsyAccount ? url('api/etsy/import') : null,
                    'search_endpoint' => $etsyAccount ? url('api/etsy/products') : null,
                    'account' => $etsyAccount ? [
                        'id' => $etsyAccount->id,
                        'account_name' => $etsyAccount->account_name,
                        'shop_name' => $etsyAccount->shop_name,
                        'connected_at' => $etsyAccount->connected_at?->toIso8601String(),
                    ] : null,
                ],
                [
                    'source' => 'csv',
                    'label' => 'CSV',
                    'connected' => true,
                    'connect_message' => null,
                    'connect_endpoint' => null,
                    'import_endpoint' => url('api/csv/import'),
                    'account' => null,
                ],
            ];

            return ApiResponse::success(['sources' => $sources], 'Import options retrieved.');
        } catch (Throwable $e) {
            Log::error('Import options failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 500);
        }
    }
}
