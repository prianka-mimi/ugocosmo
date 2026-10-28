<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductUgcVideoController extends Controller
{
    /**
     * Generate an e-commerce UGC video using Gemini Omni Video Interactions API,
     * with automatic prompt sanitization and multi-stage safe fallback retry.
     */
    public function generateUgcVideo(Request $request): JsonResponse
    {
        try {
            set_time_limit(300);

            $rules = [
                'title' => ['nullable', 'string', 'max:255'],
                'name' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:10000'],
                'prompt_override' => ['nullable', 'string', 'max:2000'],
                'duration' => ['nullable', 'integer', 'min:3', 'max:30'],
                'aspect_ratio' => ['nullable', 'string', 'in:9:16,16:9,1:1'],
                'image' => ['nullable'],
                'images' => ['nullable'],
                'image_url' => ['nullable'],
                'image_urls' => ['nullable'],
            ];

            if ($request->has('image_url')) {
                if (is_array($request->input('image_url'))) {
                    $rules['image_url.*'] = ['nullable', 'url'];
                } else {
                    $rules['image_url'] = ['nullable', 'url'];
                }
            }

            if ($request->has('image_urls') && is_array($request->input('image_urls'))) {
                $rules['image_urls.*'] = ['nullable', 'url'];
            }

            if ($request->hasFile('image')) {
                if (is_array($request->file('image'))) {
                    $rules['image.*'] = ['file', 'image', 'max:10240'];
                } else {
                    $rules['image'] = ['file', 'image', 'max:10240'];
                }
            }

            if ($request->hasFile('images')) {
                if (is_array($request->file('images'))) {
                    $rules['images.*'] = ['file', 'image', 'max:10240'];
                } else {
                    $rules['images'] = ['file', 'image', 'max:10240'];
                }
            }

            $validated = $request->validate($rules);

            $title = $validated['title'] ?? ($validated['name'] ?? null);
            $description = $validated['description'] ?? null;
            $promptOverride = $validated['prompt_override'] ?? null;
            $requestedDuration = (int) ($validated['duration'] ?? 5);
            // Note: Gemini Omni 1.1 Flash generates a single clip of maximum 10 seconds.
            $duration = min(max($requestedDuration, 3), 10);
            $aspectRatio = $validated['aspect_ratio'] ?? '9:16';

            $uuid = (string) Str::uuid();
            $thumbnailFileName = null;
            $imageParts = [];

            // 1. Process uploaded reference image files
            $uploadedFiles = [];
            if ($request->hasFile('images')) {
                $files = $request->file('images');
                $uploadedFiles = is_array($files) ? $files : [$files];
            } elseif ($request->hasFile('image')) {
                $files = $request->file('image');
                $uploadedFiles = is_array($files) ? $files : [$files];
            }

            foreach ($uploadedFiles as $file) {
                if ($file && $file->isValid()) {
                    $mime = $file->getMimeType() ?: 'image/jpeg';
                    $content = file_get_contents($file->getRealPath());
                    if ($content !== false && ! empty($content)) {
                        $imageParts[] = [
                            'type' => 'image',
                            'mime_type' => $mime,
                            'data' => base64_encode($content),
                        ];

                        if ($thumbnailFileName === null) {
                            $ext = $file->getClientOriginalExtension() ?: 'jpg';
                            $thumbnailFileName = "{$uuid}_thumb.{$ext}";
                            Storage::disk('public')->put("ugc-videos/{$thumbnailFileName}", $content);
                        }
                    }
                }
            }

            // 2. Process reference image URLs
            $rawUrls = [];
            foreach (['image_urls', 'image_url'] as $key) {
                if ($request->filled($key)) {
                    $val = $request->input($key);
                    if (is_array($val)) {
                        $rawUrls = array_merge($rawUrls, $val);
                    } elseif (is_string($val)) {
                        $decoded = json_decode($val, true);
                        if (is_array($decoded)) {
                            $rawUrls = array_merge($rawUrls, $decoded);
                        } else {
                            $rawUrls = array_merge($rawUrls, array_map('trim', explode(',', $val)));
                        }
                    }
                }
            }

            foreach (array_unique(array_filter($rawUrls)) as $url) {
                if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL)) {
                    try {
                        $res = Http::withHeaders([
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                            'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                        ])->timeout(15)->get($url);

                        if ($res->successful() && ! empty($res->body())) {
                            $mime = explode(';', $res->header('Content-Type') ?: 'image/jpeg')[0];
                            $body = $res->body();

                            $imageParts[] = [
                                'type' => 'image',
                                'mime_type' => $mime,
                                'data' => base64_encode($body),
                            ];

                            if ($thumbnailFileName === null) {
                                $thumbnailFileName = "{$uuid}_thumb.jpg";
                                Storage::disk('public')->put("ugc-videos/{$thumbnailFileName}", $body);
                            }
                        }
                    } catch (Throwable $e) {
                        Log::warning("Failed to fetch reference image from URL {$url}: ".$e->getMessage());
                    }
                }
            }

            // Ensure at least one context input is provided
            if (empty($title) && empty($description) && empty($promptOverride) && empty($imageParts)) {
                throw ValidationException::withMessages([
                    'title' => ['Please provide a product title, description, prompt override, or reference image.'],
                ]);
            }

            $productName = $title ?: 'Product';
            $productDesc = $description ?: '';

            // 3. Construct Primary UGC Video Prompt
            if (! empty($promptOverride)) {
                $cleanOverride = $this->sanitizePrompt($promptOverride);
                // Place user's override as the leading, primary directive so the model follows it
                $prompt = "{$cleanOverride}. ";
                if (! empty($title)) {
                    $prompt .= "Featuring product: '{$title}'. ";
                }
                if (! empty($description)) {
                    $prompt .= "Product Details: {$description}. ";
                }
                $prompt .= "Format: Vertical {$aspectRatio} video, cinematic high quality, realistic motion, brand-safe. Duration: {$duration} seconds.";
            } else {
                $prompt = "Create a high-energy, vertical {$aspectRatio} UGC (User-Generated Content) product commercial video for '{$productName}'. ";
                if (! empty($productDesc)) {
                    $prompt .= "Description: {$productDesc}. ";
                }
                $prompt .= "Style: Authentic social media product showcase, clean aesthetic lighting, dynamic product movements, close-up lifestyle shots, cinematic and engaging. Duration: {$duration} seconds.";
            }

            // 4. Multi-Stage Resilient Video Generation
            $apiKey = config('ai.providers.gemini.key') ?? env('GEMINI_API_KEY');
            if (empty($apiKey)) {
                throw new \RuntimeException('GEMINI_API_KEY is not configured on the server.');
            }

            $videoBase64 = null;

            // ── ATTEMPT 1: Primary prompt + reference images ───────────────
            $primaryPayload = array_merge([['type' => 'text', 'text' => $prompt]], $imageParts);
            $response1 = $this->callGeminiVideoApi($apiKey, $primaryPayload);

            if ($response1['success']) {
                $videoBase64 = $this->extractVideoBase64($response1['data']);
            }

            // ── ATTEMPT 2: Safe Commercial Fallback + reference images ─────
            // If attempt 1 failed (e.g., safety block, harmful content classifier, or error)
            if (empty($videoBase64)) {
                Log::warning('First UGC video attempt failed or was blocked by safety. Falling back to guaranteed safe commercial prompt.', [
                    'error' => $response1['error'] ?? 'No video payload',
                    'original_prompt' => $prompt,
                ]);

                $safeFallbackPrompt = "Create a high-end, vertical {$aspectRatio} commercial product advertisement video showcasing '{$productName}'. "
                    . (! empty($productDesc) ? "Product Details: {$productDesc}. " : '')
                    . "Style: Sparkling clean studio lighting, smooth rotational product view, aesthetic tabletop presentation, commercial grade, family-friendly. Duration: {$duration} seconds.";

                $fallbackPayload = array_merge([['type' => 'text', 'text' => $safeFallbackPrompt]], $imageParts);
                $response2 = $this->callGeminiVideoApi($apiKey, $fallbackPayload);

                if ($response2['success']) {
                    $videoBase64 = $this->extractVideoBase64($response2['data']);
                }
            }

            // ── ATTEMPT 3: Safe Commercial Prompt TEXT-ONLY ────────────────
            // If attempt 2 also failed (e.g., uploaded image itself triggered safety filter), remove image and generate clean text-only
            if (empty($videoBase64)) {
                Log::warning('Second UGC video attempt failed. Falling back to clean text-only commercial video prompt.', [
                    'error' => $response2['error'] ?? 'No video payload',
                ]);

                $bulletproofPrompt = "Cinematic 9:16 vertical commercial advertisement showcase for '{$productName}'. "
                    . (! empty($productDesc) ? "Description: {$productDesc}. " : '')
                    . "Pristine lighting, macro product close-up details, smooth camera glide, vibrant colors, premium commercial presentation. Duration: {$duration} seconds.";

                $textOnlyPayload = [['type' => 'text', 'text' => $bulletproofPrompt]];
                $response3 = $this->callGeminiVideoApi($apiKey, $textOnlyPayload);

                if ($response3['success']) {
                    $videoBase64 = $this->extractVideoBase64($response3['data']);
                }
            }

            if (empty($videoBase64)) {
                $lastError = $response3['error'] ?? ($response2['error'] ?? ($response1['error'] ?? 'Video generation could not be completed.'));
                Log::error('All UGC video generation attempts failed', ['last_error' => $lastError]);
                throw new \RuntimeException('Failed to generate UGC video: '.$lastError);
            }

            // 5. Save generated video to public storage
            $videoBytes = base64_decode($videoBase64);
            $videoFileName = "{$uuid}.mp4";
            Storage::disk('public')->put("ugc-videos/{$videoFileName}", $videoBytes);

            $videoUrl = asset("storage/ugc-videos/{$videoFileName}");
            $thumbnailUrl = $thumbnailFileName
                ? asset("storage/ugc-videos/{$thumbnailFileName}")
                : $videoUrl;

            $detectedDuration = $this->getMp4Duration($videoBytes);
            $actualDuration = $detectedDuration !== null ? (int) round($detectedDuration) : $duration;

            $responseData = [
                'video_url' => $videoUrl,
                'thumbnail_url' => $thumbnailUrl,
                'duration' => $actualDuration,
            ];

            $this->data = $responseData;
            $this->status_message = 'UGC video generated successfully';
            $this->status_code = 200;
        } catch (ValidationException $e) {
            $this->status_message = $e->getMessage();
            $this->status_code = 422;
            $this->data = $e->errors();
        } catch (Throwable $e) {
            Log::error('Error generating product UGC video: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            $this->status_message = 'Failed to generate UGC video: '.$e->getMessage();
            $this->status_code = 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Sanitize user prompt to neutralize sensitive keywords that trigger AI safety blocks.
     */
    protected function sanitizePrompt(?string $text): string
    {
        if (empty($text)) {
            return '';
        }

        $replacements = [
            '/\b(girl|woman|female)\s+dancing\b/i' => 'model cheerfully presenting the product with energetic movement',
            '/\b(boy|man|male)\s+dancing\b/i' => 'presenter energetically showcasing the product',
            '/\bdancing\b/i' => 'joyful and dynamic movement presenting the product',
            '/\bsexy\b/i' => 'stylish and attractive',
            '/\bhot\b/i' => 'modern and sleek',
            '/\bbikini|swimwear|underwear|lingerie\b/i' => 'casual lifestyle attire',
            '/\bnude|naked\b/i' => 'minimalist',
            '/\bkill|death|blood|violent|gun|weapon\b/i' => 'bold visual impact',
        ];

        $sanitized = preg_replace(array_keys($replacements), array_values($replacements), $text);

        return trim((string) $sanitized);
    }

    /**
     * Execute Gemini Omni Video Interactions API request.
     *
     * @return array{success: bool, status: int, data: ?array, error: ?string}
     */
    protected function callGeminiVideoApi(string $apiKey, array $inputParts): array
    {
        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/interactions?key='.$apiKey;

        $requestPayload = [
            'model' => 'gemini-omni-1.1-flash',
            'input' => $inputParts,
        ];

        try {
            $apiResponse = Http::timeout(180)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post($endpoint, $requestPayload);

            $body = $apiResponse->json();
            $isSuccess = $apiResponse->successful();
            $errorMsg = null;

            if (! $isSuccess) {
                $errorMsg = $body['error']['message'] ?? $apiResponse->body();
            }

            return [
                'success' => $isSuccess,
                'status' => $apiResponse->status(),
                'data' => $body,
                'error' => $errorMsg,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'status' => 500,
                'data' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Extract base64-encoded MP4 video from Gemini response steps.
     */
    protected function extractVideoBase64(?array $responseData): ?string
    {
        if (empty($responseData) || ! isset($responseData['steps']) || ! is_array($responseData['steps'])) {
            return null;
        }

        foreach ($responseData['steps'] as $step) {
            if (isset($step['content']) && is_array($step['content'])) {
                foreach ($step['content'] as $contentItem) {
                    if (($contentItem['type'] ?? '') === 'video' && ! empty($contentItem['data'])) {
                        return (string) $contentItem['data'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Parse actual playback duration in seconds directly from MP4 binary headers (mvhd box).
     */
    protected function getMp4Duration(string $raw): ?float
    {
        $pos = strpos($raw, 'mvhd');
        if ($pos === false || strlen($raw) < $pos + 28) {
            return null;
        }

        $offset = $pos + 4;
        $version = ord($raw[$offset]);
        $offset += 4; // skip version and flags

        if ($version === 1) {
            if (strlen($raw) < $offset + 28) {
                return null;
            }
            $offset += 16; // skip 8-byte creation and 8-byte modification
            $timescaleData = substr($raw, $offset, 4);
            $timescale = unpack('N', $timescaleData)[1] ?? 0;
            $offset += 4;
            $durationData = substr($raw, $offset, 8);
            $duration = unpack('J', $durationData)[1] ?? 0;
        } else {
            if (strlen($raw) < $offset + 16) {
                return null;
            }
            $offset += 8; // skip 4-byte creation and 4-byte modification
            $timescaleData = substr($raw, $offset, 4);
            $timescale = unpack('N', $timescaleData)[1] ?? 0;
            $offset += 4;
            $durationData = substr($raw, $offset, 4);
            $duration = unpack('N', $durationData)[1] ?? 0;
        }

        if ($timescale > 0 && $duration > 0) {
            return round($duration / $timescale, 2);
        }

        return null;
    }
}
