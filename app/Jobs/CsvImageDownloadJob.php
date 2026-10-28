<?php

namespace App\Jobs;

use App\Manager\ImageManager;
use App\Models\Product;
use App\Services\CsvImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Downloads remote product images referenced by CSV rows.
 *
 * Unlike the AliExpress variant (which trusts AliExpress CDN hosts), every
 * URL here is user-supplied, so each one passes the SSRF guard in
 * {@see CsvImportService::isSafeImageUrl()} before anything is fetched.
 */
class CsvImageDownloadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Runs on the default queue — see CsvProductImportJob. */
    public int $timeout = 300;

    public int $tries = 2;

    public int $backoff = 15;

    public int $maxExceptions = 2;

    private const MAX_IMAGE_BYTES = 20 * 1024 * 1024; // 20 MB

    public function __construct(
        protected int $productId,
        protected array $urls,
        protected ?string $mainUrl = null,
    ) {}

    public function handle(ImageManager $imageManager, CsvImportService $importService): void
    {
        $product = Product::find($this->productId);
        if (! $product) {
            Log::warning('CsvImageDownloadJob: product not found', ['product_id' => $this->productId]);

            return;
        }

        $uploaded = [];
        foreach (array_slice($this->urls, 0, 10) as $url) {
            if (! $importService->isSafeImageUrl((string) $url)) {
                Log::warning('CsvImageDownloadJob: rejected unsafe image URL', [
                    'product_id' => $this->productId,
                    'url' => $url,
                ]);

                continue;
            }

            $path = $this->download($imageManager, (string) $url);
            if ($path) {
                $uploaded[] = $path;
            }
        }

        if ($uploaded === []) {
            Log::warning('CsvImageDownloadJob: no images downloaded', [
                'product_id' => $this->productId,
                'urls' => count($this->urls),
            ]);

            return;
        }

        $mainImage = $uploaded[0];
        if ($this->mainUrl !== null && in_array($this->mainUrl, $this->urls, true)) {
            $mainIndex = array_search($this->mainUrl, $this->urls, true);
            $mainImage = $uploaded[$mainIndex] ?? $uploaded[0];
        }

        $product->update([
            'image_url' => $mainImage,
            'images' => $uploaded,
        ]);

        Log::info('CsvImageDownloadJob: images attached', [
            'product_id' => $this->productId,
            'count' => count($uploaded),
        ]);
    }

    protected function download(ImageManager $imageManager, string $url): ?string
    {
        try {
            $response = Http::timeout(30)
                ->connectTimeout(10)
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $contentLength = (int) $response->header('Content-Length', 0);
            if ($contentLength > self::MAX_IMAGE_BYTES) {
                Log::warning('CsvImageDownloadJob: image exceeds size limit', ['url' => $url]);

                return null;
            }

            $tempPath = tempnam(sys_get_temp_dir(), 'csv_import_');
            file_put_contents($tempPath, $response->body());

            $uploaded = $imageManager->upload(
                new UploadedFile($tempPath, basename(parse_url($url, PHP_URL_PATH) ?: 'image.jpg'), $response->header('Content-Type')),
                'products'
            );

            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            return $uploaded;
        } catch (\Throwable $e) {
            Log::warning('CsvImageDownloadJob: download failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
