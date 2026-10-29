<?php

namespace App\Http\Controllers;

use App\Ai\Agents\ImageTransformerAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Files\Image as AiImage;
use Laravel\Ai\Image;

class ImageController extends Controller
{
    public function improveProductImage(Request $request): JsonResponse
    {
        try {
            set_time_limit(300); // Increase time limit to 5 minutes for slow AI calls

            $request->validate([
                'image_url' => 'required_without:image|url',
                'image' => 'required_without:image_url|image|max:10240',
                'prompt_override' => 'nullable|string|max:2000',
                'quality' => 'nullable|string|in:low,medium,high',
            ]);

            $fallbackUrl = $request->input('image_url');

            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $path = $file->store('products/originals', 'public');
                $attachment = AiImage::fromStorage($path, 'public');
                $fallbackUrl = asset('storage/' . $path);
            } else {
                $imageResponse = Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                ])->timeout(30)->get($request->input('image_url'));

                if (! $imageResponse->successful() || empty($imageResponse->body())) {
                    throw new \Exception("Failed to download image from the provided URL (HTTP {$imageResponse->status()}).");
                }

                $mime = explode(';', $imageResponse->header('Content-Type') ?: 'image/jpeg')[0];
                $attachment = AiImage::fromBase64(base64_encode($imageResponse->body()), $mime);
            }

            if ($request->filled('prompt_override')) {
                $userOverride = rtrim(trim($request->input('prompt_override')), '.');
                $prompt = "Generate a transformed high-definition commercial product photo based on this image. {$userOverride}. CRITICAL: Keep the primary product/subject identical, sharp, well-lit, and perfectly preserved. High quality, professional e-commerce product photography.";
            } else {
                $prompt = 'Upscale and enhance this product image. Improve sharpness, lighting, contrast, and clarity. Maintain the original subject, colors, and background exactly as they are. Make it look like a high quality professional commercial product photo.';
            }

            Log::info('Generating product image with prompt: '.$prompt);

            $quality = $request->input('quality', 'medium');
            $aiError = null;

            try {
                // Generate the new enhanced image based on the text prompt, passing the original image as a base
                $imageGenerator = Image::of($prompt)
                    ->attachments([$attachment])
                    ->quality($quality);

                $newImage = $imageGenerator->generate();

                $storedPath = $newImage->store(disk: 'public'); // Store to public disk so it can be accessed via URL
                $finalImageUrl = asset('storage/' . $storedPath);
                $message = 'Product image improved successfully';
            } catch (\Throwable $aiException) {
                // If high quality failed, attempt retry with standard quality
                if ($quality === 'high') {
                    try {
                        Log::warning('High-quality image generation failed, retrying with standard quality: ' . $aiException->getMessage());
                        $newImage = Image::of($prompt)
                            ->attachments([$attachment])
                            ->generate();

                        $storedPath = $newImage->store(disk: 'public');
                        $finalImageUrl = asset('storage/' . $storedPath);
                        $message = 'Product image improved successfully';
                    } catch (\Throwable $retryException) {
                        Log::warning('AI image improvement retry failed. Fallback to original. Error: ' . $retryException->getMessage());
                        $finalImageUrl = $fallbackUrl;
                        $aiError = $retryException->getMessage();
                        $message = 'AI enhancement rejected or failed, returning original image: ' . $aiError;
                    }
                } else {
                    Log::warning('AI image improvement failed. Fallback to original. Error: ' . $aiException->getMessage());
                    $finalImageUrl = $fallbackUrl;
                    $aiError = $aiException->getMessage();
                    $message = 'AI enhancement rejected or failed, returning original image: ' . $aiError;
                }
            }

            $this->data = [
                'image_url' => $finalImageUrl,
                'prompt_used' => $prompt,
            ];

            if (! empty($aiError)) {
                $this->data['ai_error'] = $aiError;
            }

            $this->status_message = $message;
            $this->status_code = 200;
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->status_message = $e->getMessage();
            $this->status_code = 422;
            $this->data = $e->errors();
        } catch (\Throwable $e) {
            Log::error('Product image improve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
