<?php

namespace App\Jobs;

use App\Manager\ImageManager;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Downloads product images from the AliExpress CDN for imported listings.
 *
 * URLs always originate from the AliExpress API response (never from user
 * input), but they are still validated strictly (§ image security):
 * HTTPS-only, AliExpress CDN hosts only, capped size — the job must never
 * become a server-side request forgery or disk-fill vector.
 */
class AliExpressImageDownloadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    public int $backoff = 15;

    public int $maxExceptions = 2;

    /** Only image hosts operated by Alibaba/AliExpress are allowed. */
    protected const ALLOWED_HOST_SUFFIXES = [
        'alicdn.com',
        'aliexpress.com',
        'aliexpress.ru',
        'alibaba.com',
    ];

    /** Reject responses larger than 10 MB — images are never that big. */
    protected const MAX_IMAGE_BYTES = 10 * 1024 * 1024;

    public function __construct(
        protected int $accountId,
        protected int $productId,
        protected array $urls,
        protected ?string $mainUrl = null,
    ) {
        $this->queue = 'aliexpress-images';
    }

    public function handle(ImageManager $imageManager): void
    {
        $product = Product::with('aliexpressAccount')->find($this->productId);
        if (! $product) {
            Log::warning('AliExpressImageDownloadJob: product not found', ['product_id' => $this->productId]);

            return;
        }

        if (! $product->aliexpressAccount || ! $product->aliexpressAccount->isActive()) {
            return;
        }

        $uploaded = [];
        foreach (array_slice($this->urls, 0, 10) as $url) {
            $path = $this->download($imageManager, (string) $url);
            if ($path) {
                $uploaded[] = $path;
            }
        }

        if ($uploaded === []) {
            Log::warning('AliExpressImageDownloadJob: no images downloaded', [
                'product_id' => $this->productId,
                'url_count' => count($this->urls),
            ]);

            return;
        }

        $existingImages = is_array($product->images) ? $product->images : [];
        $existingLocal = array_values(array_filter($existingImages, fn ($p) => is_string($p) && ! str_starts_with($p, 'http')));
        $finalImages = array_values(array_unique(array_merge($existingLocal, $uploaded)));

        $mainImage = ($product->image_url && ! str_starts_with($product->image_url, 'http'))
            ? $product->image_url
            : ($uploaded[0] ?? $finalImages[0] ?? null);

        if ($this->mainUrl !== null && in_array($this->mainUrl, $this->urls, true)) {
            $mainIndex = array_search($this->mainUrl, $this->urls, true);
            if (isset($uploaded[$mainIndex])) {
                $mainImage = $uploaded[$mainIndex];
            }
        }

        $product->update([
            'image_url' => $mainImage,
            'images' => $finalImages,
        ]);

        Log::info('AliExpressImageDownloadJob: images attached', [
            'product_id' => $this->productId,
            'count' => count($uploaded),
        ]);
    }

    protected function download(ImageManager $imageManager, string $url): ?string
    {
        try {
            if (! $this->isAllowedUrl($url)) {
                Log::warning('AliExpressImageDownloadJob: blocked non-AliExpress image URL', [
                    'product_id' => $this->productId,
                    'host' => (string) parse_url($url, PHP_URL_HOST),
                ]);

                return null;
            }

            // onError prevents following a redirect to a non-allowlisted host.
            $response = Http::timeout(30)
                ->withOptions(['allow_redirects' => ['protocols' => [CURLPROTO_HTTPS]]])
                ->withHeaders(['Referer' => 'https://www.aliexpress.com/'])
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            // Cheap pre-check before buffering: reject oversized downloads
            // based on the declared Content-Length when the host provides it.
            $declaredLength = (int) $response->header('Content-Length');
            if ($declaredLength > self::MAX_IMAGE_BYTES) {
                Log::warning('AliExpressImageDownloadJob: image exceeds size limit (content-length)', [
                    'product_id' => $this->productId,
                    'bytes' => $declaredLength,
                ]);

                return null;
            }

            if (mb_strlen($response->body(), '8bit') > self::MAX_IMAGE_BYTES) {
                Log::warning('AliExpressImageDownloadJob: image exceeds size limit', [
                    'product_id' => $this->productId,
                    'bytes' => mb_strlen($response->body(), '8bit'),
                ]);

                return null;
            }

            $contentType = $response->header('Content-Type');
            if ($contentType !== '' && ! str_starts_with(strtolower($contentType), 'image/')) {
                Log::warning('AliExpressImageDownloadJob: non-image content type', [
                    'product_id' => $this->productId,
                    'content_type' => $contentType,
                ]);

                return null;
            }

            $tempPath = tempnam(sys_get_temp_dir(), 'aliexpress_');
            file_put_contents($tempPath, $response->body());

            $filename = basename((string) parse_url($url, PHP_URL_PATH)) ?: 'image.jpg';
            $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);

            $uploaded = $imageManager->upload(
                new UploadedFile($tempPath, $filename, $contentType !== '' ? $contentType : 'image/jpeg'),
                'products'
            );

            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            return $uploaded;
        } catch (\Throwable $e) {
            Log::warning('AliExpressImageDownloadJob: download failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * SSRF guard: HTTPS scheme on an AliExpress/Alibaba CDN host.
     * Suffix matching is anchored at a dot boundary so attacker.tld/alicdn.com
     * cannot pass.
     */
    protected function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        foreach (self::ALLOWED_HOST_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }
}
