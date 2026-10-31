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
     * Generate or improve an e-commerce UGC video using Gemini Omni Video Interactions API,
     * with automatic video upload handling, smart length trimming, prompt sanitization,
     * and context-aware multimodal product conditioning.
     */
    public function generateUgcVideo(Request $request): JsonResponse
    {
        try {
            set_time_limit(300);
            ini_set('memory_limit', '512M');

            $rules = [
                'title' => ['nullable', 'string', 'max:255'],
                'name' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:10000'],
                'prompt_override' => ['nullable', 'string', 'max:2000'],
                'duration' => ['nullable', 'integer', 'min:3', 'max:60'],
                'aspect_ratio' => ['nullable', 'string', 'in:9:16,16:9,1:1'],
                'video_url' => ['nullable'],
                'video_urls' => ['nullable'],
                'image_url' => ['nullable'],
                'image_urls' => ['nullable'],
            ];

            // Flexible validation for video file keys (single or bracketed array: video, videos, video[], videos[])
            $videoKeys = ['video', 'videos', 'video[]', 'videos[]'];
            foreach ($videoKeys as $vKey) {
                $rules[$vKey] = ['nullable'];
                if ($request->hasFile($vKey)) {
                    $vFiles = $request->file($vKey);
                    if (is_array($vFiles)) {
                        $rules["{$vKey}.*"] = ['file', 'mimetypes:video/mp4,video/quicktime,video/webm,video/x-msvideo,video/x-matroska,video/3gpp', 'max:102400'];
                    } else {
                        $rules[$vKey] = ['file', 'mimetypes:video/mp4,video/quicktime,video/webm,video/x-msvideo,video/x-matroska,video/3gpp', 'max:102400'];
                    }
                }
            }

            // Flexible validation for image file keys (single or bracketed array: image, images, image[], images[])
            $imageKeys = ['image', 'images', 'image[]', 'images[]'];
            foreach ($imageKeys as $imgKey) {
                $rules[$imgKey] = ['nullable'];
                if ($request->hasFile($imgKey)) {
                    $imgFiles = $request->file($imgKey);
                    if (is_array($imgFiles)) {
                        $rules["{$imgKey}.*"] = ['file', 'image', 'max:10240'];
                    } else {
                        $rules[$imgKey] = ['file', 'image', 'max:10240'];
                    }
                }
            }

            // Validation for video URLs
            if ($request->has('video_url')) {
                if (is_array($request->input('video_url'))) {
                    $rules['video_url.*'] = ['nullable', 'url'];
                } else {
                    $rules['video_url'] = ['nullable', 'url'];
                }
            }
            if ($request->has('video_urls') && is_array($request->input('video_urls'))) {
                $rules['video_urls.*'] = ['nullable', 'url'];
            }

            // Validation for reference image URLs
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

            $validated = $request->validate($rules);

            $apiKey = config('ai.providers.gemini.key') ?? env('GEMINI_API_KEY');
            if (empty($apiKey)) {
                throw new \RuntimeException('GEMINI_API_KEY is not configured on the server.');
            }

            $title = $validated['title'] ?? ($validated['name'] ?? null);
            $description = $validated['description'] ?? null;
            $promptOverride = $validated['prompt_override'] ?? null;

            // Gemini Omni 1.1 Flash generates clips between 3 and 10 seconds.
            // Any user-requested duration is smoothly mapped into this range.
            $requestedDuration = isset($validated['duration']) ? (int) $validated['duration'] : null;
            $duration = $requestedDuration ? min(max($requestedDuration, 3), 10) : 5;
            $aspectRatio = $validated['aspect_ratio'] ?? '9:16';

            $uuid = (string) Str::uuid();
            $thumbnailFileName = null;
            $videoParts = [];
            $imageParts = [];
            $originalVideoUrl = null;
            $tempFilesToCleanup = [];

            // ── 1. Process Reference Images First (So Product Photo is Preferred as Thumbnail) ──
            $uploadedImageFiles = [];
            foreach (['images', 'images[]', 'image', 'image[]'] as $key) {
                if ($request->hasFile($key)) {
                    $files = $request->file($key);
                    if (is_array($files)) {
                        $uploadedImageFiles = array_merge($uploadedImageFiles, $files);
                    } elseif ($files) {
                        $uploadedImageFiles[] = $files;
                    }
                }
            }

            foreach ($uploadedImageFiles as $file) {
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

            // Process reference image URLs
            $rawImageUrls = [];
            foreach (['image_urls', 'image_url', 'image_urls[]', 'image_url[]'] as $key) {
                if ($request->filled($key)) {
                    $val = $request->input($key);
                    if (is_array($val)) {
                        $rawImageUrls = array_merge($rawImageUrls, $val);
                    } elseif (is_string($val)) {
                        $decoded = json_decode($val, true);
                        if (is_array($decoded)) {
                            $rawImageUrls = array_merge($rawImageUrls, $decoded);
                        } else {
                            $rawImageUrls = array_merge($rawImageUrls, array_map('trim', explode(',', $val)));
                        }
                    }
                }
            }

            foreach (array_unique(array_filter($rawImageUrls)) as $url) {
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

            // ── 2. Process Uploaded Video File(s) ──
            $uploadedVideoFiles = [];
            foreach (['videos', 'videos[]', 'video', 'video[]'] as $key) {
                if ($request->hasFile($key)) {
                    $vFiles = $request->file($key);
                    if (is_array($vFiles)) {
                        $uploadedVideoFiles = array_merge($uploadedVideoFiles, $vFiles);
                    } elseif ($vFiles) {
                        $uploadedVideoFiles[] = $vFiles;
                    }
                }
            }

            foreach ($uploadedVideoFiles as $vFile) {
                if ($vFile && $vFile->isValid()) {
                    $ext = $vFile->getClientOriginalExtension() ?: 'mp4';
                    $rawPath = $vFile->getRealPath();

                    // Persist full original video to public storage
                    $inputVideoFileName = "{$uuid}_original.{$ext}";
                    Storage::disk('public')->putFileAs('ugc-videos', $vFile, $inputVideoFileName);
                    if ($originalVideoUrl === null) {
                        $originalVideoUrl = asset("storage/ugc-videos/{$inputVideoFileName}");
                    }

                    // If no product image was uploaded, extract video frame as fallback thumbnail
                    if ($thumbnailFileName === null) {
                        $thumbnailFileName = "{$uuid}_thumb.jpg";
                        $thumbFullPath = Storage::disk('public')->path("ugc-videos/{$thumbnailFileName}");
                        if (! $this->extractVideoThumbnail($rawPath, $thumbFullPath)) {
                            $thumbnailFileName = null;
                        }
                    }

                    // Automatically trim video to 10 seconds if it exceeds Gemini's 10s source limit
                    $workingPath = $this->trimVideoIfNeeded($rawPath, 10);
                    if ($workingPath !== $rawPath) {
                        $tempFilesToCleanup[] = $workingPath;
                    }

                    $fileSize = filesize($workingPath);
                    $mime = 'video/mp4';

                    $part = $this->prepareVideoPart($apiKey, $workingPath, $mime, $fileSize);
                    if ($part !== null) {
                        $videoParts[] = $part;
                    }
                }
            }

            // Process video URL(s)
            $rawVideoUrls = [];
            foreach (['video_urls', 'video_url', 'video_urls[]', 'video_url[]'] as $key) {
                if ($request->filled($key)) {
                    $val = $request->input($key);
                    if (is_array($val)) {
                        $rawVideoUrls = array_merge($rawVideoUrls, $val);
                    } elseif (is_string($val)) {
                        $decoded = json_decode($val, true);
                        if (is_array($decoded)) {
                            $rawVideoUrls = array_merge($rawVideoUrls, $decoded);
                        } else {
                            $rawVideoUrls = array_merge($rawVideoUrls, array_map('trim', explode(',', $val)));
                        }
                    }
                }
            }

            foreach (array_unique(array_filter($rawVideoUrls)) as $vUrl) {
                if (is_string($vUrl) && filter_var($vUrl, FILTER_VALIDATE_URL)) {
                    try {
                        $tempPath = tempnam(sys_get_temp_dir(), 'ugc_vid_');
                        $tempFilesToCleanup[] = $tempPath;
                        $sink = fopen($tempPath, 'w+');
                        $res = Http::withHeaders([
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                        ])->timeout(60)->sink($sink)->get($vUrl);

                        if ($res->successful() && file_exists($tempPath) && filesize($tempPath) > 0) {
                            $inputVideoFileName = "{$uuid}_original.mp4";
                            Storage::disk('public')->put("ugc-videos/{$inputVideoFileName}", file_get_contents($tempPath));
                            if ($originalVideoUrl === null) {
                                $originalVideoUrl = asset("storage/ugc-videos/{$inputVideoFileName}");
                            }

                            if ($thumbnailFileName === null) {
                                $thumbnailFileName = "{$uuid}_thumb.jpg";
                                $thumbFullPath = Storage::disk('public')->path("ugc-videos/{$thumbnailFileName}");
                                if (! $this->extractVideoThumbnail($tempPath, $thumbFullPath)) {
                                    $thumbnailFileName = null;
                                }
                            }

                            $workingPath = $this->trimVideoIfNeeded($tempPath, 10);
                            if ($workingPath !== $tempPath) {
                                $tempFilesToCleanup[] = $workingPath;
                            }

                            $fileSize = filesize($workingPath);
                            $mime = 'video/mp4';

                            $part = $this->prepareVideoPart($apiKey, $workingPath, $mime, $fileSize);
                            if ($part !== null) {
                                $videoParts[] = $part;
                            }
                        }
                    } catch (Throwable $e) {
                        Log::warning("Failed to fetch reference video from URL {$vUrl}: ".$e->getMessage());
                    }
                }
            }

            // Ensure at least one context input is provided
            if (empty($title) && empty($description) && empty($promptOverride) && empty($imageParts) && empty($videoParts)) {
                throw ValidationException::withMessages([
                    'video' => ['Please provide a product video, image, title, description, or prompt override.'],
                ]);
            }

            $productName = $title ?: 'Product';
            $hasVideo = ! empty($videoParts);
            $hasImages = ! empty($imageParts);

            // ── 3. Construct Context-Aware, High-Fidelity Prompt ──
            $directives = [];

            // A. Primary Product Name & Subject
            if (! empty($title)) {
                $directives[] = "PRIMARY PRODUCT FOCUS: This commercial video is specifically created to promote and showcase '{$title}'. The product '{$title}' must be the primary, central subject of the video.";
            }

            // B. Product Details / Description
            if (! empty($description)) {
                $directives[] = "PRODUCT CONTEXT & SELLING POINTS: {$description}.";
            }

            // C. User Custom Instructions (Top Creative Priority)
            if (! empty($promptOverride)) {
                $cleanOverride = $this->sanitizePrompt($promptOverride);
                $directives[] = "USER'S CREATIVE INSTRUCTIONS (MUST FOLLOW): {$cleanOverride}.";
            }

            // D. Attached Product Image Directive
            if ($hasImages) {
                $directives[] = "CRITICAL PRODUCT IMAGE REFERENCE: Attached image(s) show the exact product to feature. The video MUST prominently display this exact product, matching its design, appearance, branding, color, and packaging. Ensure the presenter clearly displays, holds, or demonstrates this specific product.";
            }

            // E. Attached Video Directive
            if ($hasVideo) {
                if (! empty($title) || $hasImages) {
                    $directives[] = "VIDEO FOOTAGE INTEGRATION: A reference video clip is provided. Use the human presenter, action, energy, natural motion, and aesthetic environment from the reference video, but seamlessly integrate and feature the primary product ('{$productName}') into the scene so the commercial is centered around '{$productName}'.";
                } else {
                    $directives[] = "VIDEO ENHANCEMENT: Enhance and elevate the provided reference video into a high-converting, viral social media commercial video. Upgrade the lighting, colors, sharpness, and motion dynamics to look like a high-end commercial.";
                }
            }

            // F. Production Quality & Format
            $directives[] = "FORMAT & QUALITY: Vertical {$aspectRatio} UGC (User-Generated Content) e-commerce advertisement for TikTok, Instagram Reels, and YouTube Shorts. Dynamic hook, engaging pacing, crisp 4K commercial lighting, vibrant colors, brand-safe, and realistic motion.";

            $directives[] = "DURATION: Exactly {$duration} seconds.";

            $prompt = implode(' ', $directives);

            Log::info("Generating UGC video for '{$productName}' with prompt: {$prompt}", [
                'has_video' => $hasVideo,
                'has_images' => $hasImages,
                'duration' => $duration,
                'aspect_ratio' => $aspectRatio,
            ]);

            // ── 4. Assemble Multimodal Payload in Optimal Order ──
            // Reference media parts first (images then video), followed by instructional text prompt
            $primaryPayload = array_merge($imageParts, $videoParts, [['type' => 'text', 'text' => $prompt]]);
            $videoBase64 = null;

            // ── ATTEMPT 1: Primary prompt + uploaded video / reference images ──
            $response1 = $this->callGeminiVideoApi($apiKey, $primaryPayload);

            if ($response1['success']) {
                $videoBase64 = $this->extractVideoBase64($response1['data']);
            }

            // ── ATTEMPT 2: Safe Commercial Fallback with Preserved Context ─────
            if (empty($videoBase64)) {
                Log::warning('First UGC video attempt failed or was blocked by safety. Falling back to safe commercial prompt with preserved context.', [
                    'error' => $response1['error'] ?? 'No video payload',
                    'original_prompt' => $prompt,
                ]);

                $fallbackDirectives = [];
                if (! empty($title)) {
                    $fallbackDirectives[] = "Commercial advertisement video specifically showcasing '{$title}'.";
                }
                if (! empty($description)) {
                    $fallbackDirectives[] = "Product details: {$description}.";
                }
                if (! empty($promptOverride)) {
                    $fallbackDirectives[] = "Creative direction: {$this->sanitizePrompt($promptOverride)}.";
                }
                if ($hasImages) {
                    $fallbackDirectives[] = "Featuring the product shown in the attached reference image.";
                }
                if ($hasVideo) {
                    $fallbackDirectives[] = "Incorporating the presenter, movement, and scene from the reference video.";
                }
                $fallbackDirectives[] = "Style: Sparkling clean studio lighting, smooth camera movement, aesthetic tabletop and lifestyle presentation, commercial grade, family-friendly. Vertical {$aspectRatio}, duration: {$duration} seconds.";

                $safeFallbackPrompt = implode(' ', $fallbackDirectives);

                $fallbackPayload = array_merge($imageParts, $videoParts, [['type' => 'text', 'text' => $safeFallbackPrompt]]);
                $response2 = $this->callGeminiVideoApi($apiKey, $fallbackPayload);

                if ($response2['success']) {
                    $videoBase64 = $this->extractVideoBase64($response2['data']);
                }
            }

            // ── ATTEMPT 3: Safe Commercial Prompt (Text/Image Only fallback) ──
            if (empty($videoBase64)) {
                Log::warning('Second UGC video attempt failed. Falling back to clean commercial video prompt.', [
                    'error' => $response2['error'] ?? 'No video payload',
                ]);

                $bulletproofPrompt = "Cinematic 9:16 vertical commercial advertisement showcase for '{$productName}'. "
                    . (! empty($description) ? "Description: {$description}. " : '')
                    . "Pristine lighting, macro product close-up details, smooth camera glide, vibrant colors, premium commercial presentation. Duration: {$duration} seconds.";

                $attempt3Parts = ! empty($imageParts)
                    ? array_merge($imageParts, [['type' => 'text', 'text' => $bulletproofPrompt]])
                    : [['type' => 'text', 'text' => $bulletproofPrompt]];

                $response3 = $this->callGeminiVideoApi($apiKey, $attempt3Parts);

                if ($response3['success']) {
                    $videoBase64 = $this->extractVideoBase64($response3['data']);
                }
            }

            if (empty($videoBase64)) {
                $lastError = $response3['error'] ?? ($response2['error'] ?? ($response1['error'] ?? 'Video generation could not be completed.'));
                Log::error('All UGC video generation attempts failed', ['last_error' => $lastError]);
                throw new \RuntimeException('Failed to generate UGC video: '.$lastError);
            }

            // ── 5. Save generated improved video to public storage ──
            $videoBytes = base64_decode($videoBase64);
            $videoFileName = "{$uuid}.mp4";
            Storage::disk('public')->put("ugc-videos/{$videoFileName}", $videoBytes);

            $videoUrl = asset("storage/ugc-videos/{$videoFileName}");
            $thumbnailUrl = $thumbnailFileName
                ? asset("storage/ugc-videos/{$thumbnailFileName}")
                : $videoUrl;

            $detectedGenDuration = $this->getMp4Duration($videoBytes);
            $actualDuration = $detectedGenDuration !== null ? (int) round($detectedGenDuration) : $duration;

            $responseData = [
                'video_url' => $videoUrl,
                'thumbnail_url' => $thumbnailUrl,
                'duration' => $actualDuration,
                'type' => $hasVideo ? 'enhancement' : 'generation',
            ];

            if ($originalVideoUrl !== null) {
                $responseData['original_video_url'] = $originalVideoUrl;
            }

            $this->data = $responseData;
            $this->status_message = $hasVideo
                ? 'UGC video improved successfully'
                : 'UGC video generated successfully';
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
        } finally {
            // Clean up temporary trimmed video files
            foreach ($tempFilesToCleanup ?? [] as $tmp) {
                if (! empty($tmp) && file_exists($tmp)) {
                    @unlink($tmp);
                }
            }
        }

        return $this->JsonResponseFormat();
    }

    /**
     * Automatically trim an input video to a maximum duration (e.g. 10s) using FFmpeg.
     */
    protected function trimVideoIfNeeded(string $inputPath, int $maxDuration = 10): string
    {
        $duration = $this->getMp4DurationFromFile($inputPath);

        // If duration is already within the limit, return as is
        if ($duration !== null && $duration <= $maxDuration + 0.5) {
            return $inputPath;
        }

        $trimmedPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ugc_trimmed_'.uniqid().'.mp4';
        $ffmpegPath = $this->getFfmpegPath();

        $cmd = sprintf(
            '%s -y -ss 0 -i %s -t %d -c:v libx264 -preset ultrafast -crf 23 -c:a aac -movflags +faststart %s 2>&1',
            escapeshellarg($ffmpegPath),
            escapeshellarg($inputPath),
            $maxDuration,
            escapeshellarg($trimmedPath)
        );

        exec($cmd, $out, $ret);

        if ($ret === 0 && file_exists($trimmedPath) && filesize($trimmedPath) > 0) {
            return $trimmedPath;
        }

        Log::warning('FFmpeg trimming failed or unavailable: '.implode("\n", $out));

        return $inputPath;
    }

    /**
     * Extract a single JPEG frame at 1s as a thumbnail from the video using FFmpeg.
     */
    protected function extractVideoThumbnail(string $videoPath, string $destinationPath): bool
    {
        $ffmpegPath = $this->getFfmpegPath();

        // Try extracting at 1 second
        $cmd1 = sprintf(
            '%s -y -ss 00:00:01 -i %s -vframes 1 -q:v 2 %s 2>&1',
            escapeshellarg($ffmpegPath),
            escapeshellarg($videoPath),
            escapeshellarg($destinationPath)
        );

        exec($cmd1, $out, $ret);

        if ($ret === 0 && file_exists($destinationPath) && filesize($destinationPath) > 0) {
            return true;
        }

        // Fallback: extract at first frame (0s)
        $cmd0 = sprintf(
            '%s -y -ss 00:00:00 -i %s -vframes 1 -q:v 2 %s 2>&1',
            escapeshellarg($ffmpegPath),
            escapeshellarg($videoPath),
            escapeshellarg($destinationPath)
        );

        exec($cmd0, $out0, $ret0);

        return $ret0 === 0 && file_exists($destinationPath) && filesize($destinationPath) > 0;
    }

    /**
     * Resolve the FFmpeg executable path.
     */
    protected function getFfmpegPath(): string
    {
        $candidates = [
            'ffmpeg',
            'C:\\Users\\muham\\.config\\herd\\bin\\ffmpeg.exe',
            'C:\\Program Files\\Herd\\resources\\app.asar.unpacked\\resources\\bin\\ffmpeg\\ffmpeg.exe',
            'C:\\Users\\muham\\AppData\\Local\\Microsoft\\WinGet\\Packages\\Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe\\ffmpeg-9.0.2-full_build\\bin\\ffmpeg.exe',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === 'ffmpeg') {
                return 'ffmpeg';
            }
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return 'ffmpeg';
    }

    /**
     * Prepare a video part for Gemini Interactions API.
     * Uses inline base64 for files <= 15MB, or Gemini Files API for larger files.
     */
    protected function prepareVideoPart(string $apiKey, string $filePath, string $mime, int $fileSize): ?array
    {
        // 15MB limit for inline base64 (safely below Gemini's 20MB request limit)
        if ($fileSize <= 15 * 1024 * 1024) {
            $content = file_get_contents($filePath);
            if ($content !== false && ! empty($content)) {
                return [
                    'type' => 'video',
                    'mime_type' => $mime,
                    'data' => base64_encode($content),
                ];
            }
        } else {
            // For larger files up to 100MB, upload to Gemini Files API
            $fileUri = $this->uploadFileToGemini($apiKey, $filePath, $mime);
            if (! empty($fileUri)) {
                return [
                    'type' => 'video',
                    'mime_type' => $mime,
                    'uri' => $fileUri,
                ];
            }
        }

        return null;
    }

    /**
     * Upload a larger video file to Google Gemini Files API and return its URI.
     */
    protected function uploadFileToGemini(string $apiKey, string $filePath, string $mime): ?string
    {
        try {
            $fileSize = filesize($filePath);
            $displayName = 'ugc_input_'.basename($filePath);

            // Step 1: Initialize resumable upload
            $initResponse = Http::withHeaders([
                'X-Goog-Upload-Protocol' => 'resumable',
                'X-Goog-Upload-Command' => 'start',
                'X-Goog-Upload-Header-Content-Length' => (string) $fileSize,
                'X-Goog-Upload-Header-Content-Type' => $mime,
                'Content-Type' => 'application/json',
            ])->post("https://generativelanguage.googleapis.com/upload/v1beta/files?key={$apiKey}", [
                'file' => [
                    'display_name' => $displayName,
                ],
            ]);

            if (! $initResponse->successful()) {
                Log::warning('Failed to initialize Gemini File upload: '.$initResponse->body());

                return null;
            }

            $uploadUrl = $initResponse->header('X-Goog-Upload-URL');
            if (empty($uploadUrl)) {
                Log::warning('Gemini File upload did not return X-Goog-Upload-URL header.');

                return null;
            }

            // Step 2: Upload file bytes
            $uploadResponse = Http::timeout(180)->withHeaders([
                'Content-Length' => (string) $fileSize,
                'X-Goog-Upload-Offset' => '0',
                'X-Goog-Upload-Command' => 'upload, finalize',
            ])->withBody(file_get_contents($filePath), $mime)->post($uploadUrl);

            if ($uploadResponse->successful()) {
                $fileData = $uploadResponse->json();

                return $fileData['file']['uri'] ?? null;
            }

            Log::warning('Gemini File upload finalize failed: '.$uploadResponse->body());
        } catch (Throwable $e) {
            Log::error('Exception uploading file to Gemini Files API: '.$e->getMessage());
        }

        return null;
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
     * Parse actual playback duration in seconds directly from an MP4 file.
     */
    protected function getMp4DurationFromFile(string $filePath): ?float
    {
        if (! file_exists($filePath) || filesize($filePath) === 0) {
            return null;
        }

        $fileSize = filesize($filePath);
        $handle = @fopen($filePath, 'rb');
        if (! $handle) {
            return null;
        }

        // 1. Check first 1MB
        $startChunk = fread($handle, min($fileSize, 1024 * 1024));
        $duration = $this->getMp4Duration($startChunk);
        if ($duration !== null) {
            fclose($handle);

            return $duration;
        }

        // 2. Check last 1MB (common location for moov atom in MP4s)
        if ($fileSize > 1024 * 1024) {
            $readBytes = min($fileSize, 1024 * 1024);
            fseek($handle, $fileSize - $readBytes);
            $endChunk = fread($handle, $readBytes);
            $duration = $this->getMp4Duration($endChunk);
            if ($duration !== null) {
                fclose($handle);

                return $duration;
            }
        }

        // 3. Fallback: If under 20MB, scan full content
        if ($fileSize <= 20 * 1024 * 1024) {
            rewind($handle);
            $fullContent = stream_get_contents($handle);
            fclose($handle);

            return $fullContent ? $this->getMp4Duration($fullContent) : null;
        }

        fclose($handle);

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
