<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $product->title }} - Castilo Marketplace</title>
    <meta name="description" content="{{ \Illuminate\Support\Str::limit(strip_tags($product->description ?? ''), 160) }}">
    <meta property="og:title" content="{{ $product->title }}">
    <meta property="og:description" content="{{ \Illuminate\Support\Str::limit(strip_tags($product->description ?? ''), 200) }}">
    @if(!empty($product->images[0]))
    <meta property="og:image" content="{{ $product->images[0] }}">
    @endif
    <meta property="og:url" content="{{ $webUrl }}">
    <meta property="og:type" content="product">
    <meta property="product:price:amount" content="{{ $product->price }}">
    <meta property="product:price:currency" content="{{ strtoupper($product->currency ?? 'USD') }}">
    <script type="application/ld+json">
    {
        "@context": "https://schema.org/",
        "@type": "Product",
        "name": @json($product->title),
        "description": @json(\Illuminate\Support\Str::limit(strip_tags($product->description ?? ''), 5000)),
        @if(!empty($product->images[0]))
        "image": @json($product->images[0]),
        @endif
        "sku": @json($product->sku),
        "offers": {
            "@type": "Offer",
            "url": @json($webUrl),
            "priceCurrency": @json(strtoupper($product->currency ?? 'USD')),
            "price": @json($product->price),
            "availability": {{ $product->is_active ? '"https://schema.org/InStock"' : '"https://schema.org/OutOfStock"' }}
        }
    }
    </script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8f9fa; color: #1a1a1a; }
        .container { max-width: 800px; margin: 0 auto; padding: 20px; }
        .product-card { background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
        .product-image { width: 100%; aspect-ratio: 1; object-fit: cover; }
        .product-info { padding: 24px; }
        .product-title { font-size: 24px; font-weight: 700; margin-bottom: 8px; }
        .product-price { font-size: 28px; font-weight: 700; color: #2563eb; margin-bottom: 16px; }
        .product-description { color: #4b5563; line-height: 1.6; margin-bottom: 24px; }
        .open-app { display: block; width: 100%; padding: 16px; background: #2563eb; color: #fff; text-align: center; border-radius: 12px; font-size: 18px; font-weight: 600; text-decoration: none; }
        .open-app:hover { background: #1d4ed8; }
        .badge { display: inline-block; padding: 4px 12px; background: #dcfce7; color: #166534; border-radius: 999px; font-size: 13px; font-weight: 500; margin-bottom: 12px; }
        .seller { color: #6b7280; font-size: 14px; margin-top: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="product-card">
            @if(!empty($product->images[0]))
                <img src="{{ $product->images[0] }}" alt="{{ $product->title }}" class="product-image" loading="lazy">
            @endif
            <div class="product-info">
                <span class="badge">Castilo Marketplace</span>
                <h1 class="product-title">{{ $product->title }}</h1>
                <div class="product-price">{{ strtoupper($product->currency ?? 'USD') }} {{ number_format($product->price, 2) }}</div>
                @if($product->is_active)
                    <p class="product-description">{{ $product->description }}</p>
                @else
                    <p class="product-description" style="color: #dc2626;">This product is currently unavailable.</p>
                @endif
                @if($product->is_active)
                    <a href="{{ $appUrl }}" class="open-app">Open in Castilo App</a>
                @endif
                <p class="seller">Sold on Castilo Marketplace</p>
            </div>
        </div>
    </div>
</body>
</html>
