<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Ai\Agents\ProductTextImproverAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Files\Image as AiImage;
use Throwable;

class ProductTextImprovementController extends Controller
{
    /**
     * Improve product title and description using AI, with optional reference images and image URLs.
     */
    public function improveProductText(Request $request): JsonResponse
    {
        try {
            set_time_limit(180);

            $rules = [
                'title' => ['nullable', 'string', 'max:255'],
                'name' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:10000'],
                'text' => ['nullable', 'string', 'max:10000'],
                'type' => ['nullable', 'string', 'in:title,name,description,features,bullet_points,general'],
                'tone' => ['nullable', 'string', 'max:50'],
                'language' => ['nullable', 'string', 'max:50'],
                'prompt_override' => ['nullable', 'string', 'max:1000'],
                'image' => ['nullable'],
                'images' => ['nullable'],
                'image_url' => ['nullable'],
                'image_urls' => ['nullable'],
            ];

            // Handle image_url when sent as array (e.g. image_url[] in form-data) vs single string
            if ($request->has('image_url')) {
                if (is_array($request->input('image_url'))) {
                    $rules['image_url.*'] = ['nullable', 'url'];
                } else {
                    $rules['image_url'] = ['nullable', 'url'];
                }
            }

            // Handle image_urls when sent as array (e.g. image_urls[] in form-data)
            if ($request->has('image_urls') && is_array($request->input('image_urls'))) {
                $rules['image_urls.*'] = ['nullable', 'url'];
            }

            // Validate uploaded files if present
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
            $text = $validated['text'] ?? null;
            $type = $validated['type'] ?? 'general';
            $tone = $validated['tone'] ?? 'persuasive and professional';
            $language = $validated['language'] ?? null;
            $promptOverride = $validated['prompt_override'] ?? null;

            // 1. Process uploaded reference image files
            $attachments = [];
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
                    $attachments[] = AiImage::fromUpload($file);
                }
            }

            // 2. Process reference image URLs (supports image_url[], image_urls[], comma-separated, or JSON array string)
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
                            $mime = $res->header('Content-Type') ?: 'image/jpeg';
                            $mime = explode(';', $mime)[0];
                            $attachments[] = AiImage::fromBase64(base64_encode($res->body()), $mime);
                        }
                    } catch (Throwable $e) {
                        Log::warning("Failed to fetch reference image from URL {$url}: ".$e->getMessage());
                    }
                }
            }

            // Ensure at least one piece of context is provided (title, description, text, prompt override, or reference image)
            if (empty($title) && empty($description) && empty($text) && empty($promptOverride) && empty($attachments)) {
                throw ValidationException::withMessages([
                    'title' => ['Please provide a product title, description, text, prompt override, or reference image(s).'],
                ]);
            }

            // Fallbacks in case AI generation fails or is unavailable
            $fallbackTitle = $title ?? (in_array($type, ['title', 'name'], true) ? $text : ($text ?: ($promptOverride ?: 'Product Title')));
            $fallbackDescription = $description ?? ($type === 'description' ? $text : ($text ?: ($promptOverride ?: 'Product Description')));

            // Construct contextual prompt for the AI agent
            $promptParts = ['Improve and expand the following product information into a high-converting title and rich description:'];

            if (! empty($title)) {
                $promptParts[] = "Current Title:\n\"{$title}\"";
            }
            if (! empty($description)) {
                $promptParts[] = "Current Description:\n\"{$description}\"";
            }
            if (! empty($text)) {
                $promptParts[] = "Additional Provided Text (Type: {$type}):\n\"{$text}\"";
            }
            if (! empty($attachments)) {
                $count = count($attachments);
                $promptParts[] = "Reference Images: {$count} product image(s) have been attached. Inspect visual features (colors, materials, construction, design) and incorporate them accurately.";
            }

            $promptParts[] = "Desired Tone: {$tone}";
            if (! empty($language)) {
                $promptParts[] = "Target Language: {$language}";
            }
            if (! empty($promptOverride)) {
                $promptParts[] = "Custom User Instructions: {$promptOverride}";
            }

            $promptParts[] = 'Requirements: Return an improved_title that is catchy and keyword-optimized, and an improved_description that is thorough, persuasive, and highlights key product benefits. FORMATTING: Return PLAIN TEXT ONLY. DO NOT include any HTML tags (no <p>, <br>, <strong>, <ul>, <li>, <div>, etc.). Use clean line breaks and bullet symbols (•) for formatting.';

            $prompt = implode("\n\n", $promptParts);
            Log::info('Generating product text with prompt: '.$prompt);

            $cleanPlainText = static function (?string $text): string {
                if (empty($text)) {
                    return '';
                }
                // Convert block-level HTML tags and line breaks to plain newlines
                $text = preg_replace('/<\s*\/\s*(p|div|tr|li|h[1-6])\s*>/i', "\n", $text);
                $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $text);
$text = preg_replace('/<\s*li\s*>/i', '• ', $text);
  $text = strip_tags($text);
  $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $text = preg_replace("/\r\n|\r/", "\n", $text);
  $text = preg_replace("/\n{3,}/", "\n\n", $text);

  return trim($text);
  };

  try {
  $agent = new ProductTextImproverAgent;
  /** @var \Laravel\Ai\Responses\StructuredAgentResponse|array $response */
  $response = $agent->prompt($prompt, attachments: $attachments);

  /** @var array<string, mixed> $aiResult */
    $aiResult = method_exists($response, 'toArray')
    ? $response->toArray()
    : (is_array($response) ? $response : (json_decode((string) $response, true) ?? []));

    $improvedTitle = $cleanPlainText(! empty($aiResult['improved_title']) ? (string) $aiResult['improved_title'] :
    $fallbackTitle);
    $improvedDescription = $cleanPlainText(! empty($aiResult['improved_description']) ? (string)
    $aiResult['improved_description'] : $fallbackDescription);

    $message = 'Product text improved successfully';
    } catch (Throwable $aiException) {
    Log::warning('AI product text improvement failed. Fallback to original. Error: '.$aiException->getMessage());

    $improvedTitle = $cleanPlainText($fallbackTitle);
    $improvedDescription = $cleanPlainText($fallbackDescription);
    $message = 'AI enhancement unavailable or failed, returning original text.';
    }

    $this->data = [
    'improved_title' => $improvedTitle,
    'improved_description' => $improvedDescription,
    ];
    $this->status_message = $message;
    $this->status_code = 200;
    } catch (ValidationException $e) {
    $this->status_message = $e->getMessage();
    $this->status_code = 422;
    $this->data = $e->errors();
    } catch (Throwable $e) {
    Log::error('Product text improve error: '.$e->getMessage());
    $this->status_message = $e->getMessage();
    $this->status_code = $e->getCode() ?: 500;
    }

    return $this->JsonResponseFormat();
    }
    }