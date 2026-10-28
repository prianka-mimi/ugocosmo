<?php

namespace App\Http\Controllers;

use App\Ai\Agents\ImageTransformerAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
                'prompt_override' => 'nullable|string',
            ]);

            $fallbackUrl = $request->image_url;

            if ($request->hasFile('image')) {
                $path = $request->file('image')->store('products/originals', 'public');
                $attachment = AiImage::fromStorage($path, 'public');
                $fallbackUrl = asset('storage/' . $path);
            } else {
                $imageResponse = \Illuminate\Support\Facades\Http::get($request->image_url);
                if (!$imageResponse->successful()) {
                    throw new \Exception("Failed to download image from the provided URL.");
                }
                $path = 'temp/' . uniqid() . '.jpg';
                \Illuminate\Support\Facades\Storage::disk('local')->put($path, $imageResponse->body());
                $attachment = AiImage::fromStorage($path, 'local');
            }

            $basePrompt = 'Upscale and enhance this image. Improve sharpness, lighting, and clarity. Maintain the original subject, colors, and background exactly as they are. Make it look like a high quality professional product photo.';
            
            if ($request->filled('prompt_override')) {
                $basePrompt = $request->prompt_override.'. '.$basePrompt;
            }

            try {
                // Generate the new enhanced image based on the text prompt, passing the original image as a base
                $newImage = Image::of($basePrompt)
                    ->attachments([$attachment])
                    ->quality('high')
                    ->generate();

                $storedPath = $newImage->store(disk: 'public'); // Store to public disk so it can be accessed via URL
                $finalImageUrl = asset('storage/' . $storedPath);
                $message = 'Product image improved successfully';
            } catch (\Throwable $aiException) {
                \Illuminate\Support\Facades\Log::warning('AI image improvement failed. Fallback to original. Error: ' . $aiException->getMessage());
                $finalImageUrl = $fallbackUrl;
                $message = 'AI enhancement rejected or failed, returning original image.';
            }

            $this->data = ['image_url' => $finalImageUrl];
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
