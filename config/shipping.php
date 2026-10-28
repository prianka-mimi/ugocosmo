<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Source-Based Shipping Rate Requirement Rules
    |--------------------------------------------------------------------------
    |
    | Defines whether orders containing products from a specific source
    | require ShipEngine shipping rate selection before generating payment credentials.
    |
    | Sources:
    |   - 'local'      : Seller-created inventory (fulfilled locally via ShipEngine) -> true
    |   - 'csv'        : Bulk CSV-imported inventory (fulfilled locally)              -> true
    |   - 'aliexpress' : AliExpress dropshipped products (supplier-fulfilled)         -> false
    |   - 'shopify'    : Shopify synced listings (fulfilled via Shopify/external)     -> false
    |   - 'etsy'       : Etsy synced listings (fulfilled via Etsy/external)           -> false
    |
    | Future 3rd party platforms (e.g. WooCommerce, Amazon, CJ Dropshipping,
    | Printful, etc.) can simply be added to this array or configured via
    | environment variables without modifying core checkout/payment controllers.
    |
    */
    'source_requires_rate' => [
        'local' => (bool) env('SHIPPING_RATE_REQUIRED_LOCAL', true),
        'csv' => (bool) env('SHIPPING_RATE_REQUIRED_CSV', true),
        'aliexpress' => (bool) env('SHIPPING_RATE_REQUIRED_ALIEXPRESS', false),
        'shopify' => (bool) env('SHIPPING_RATE_REQUIRED_SHOPIFY', false),
        'etsy' => (bool) env('SHIPPING_RATE_REQUIRED_ETSY', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Digital Product Types
    |--------------------------------------------------------------------------
    |
    | Product types that are non-physical and never require shipping rates.
    |
    */
    'digital_product_types' => [
        'digital',
        'digital_download',
        'virtual',
        'service',
    ],

];

