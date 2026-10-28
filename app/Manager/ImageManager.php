<?php

namespace App\Manager;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager as InterventionImageManager;

class ImageManager
{
    protected InterventionImageManager $manager;

    public function __construct()
    {
        $this->manager = new InterventionImageManager(new Driver);
    }

    public function upload(
        $file,
        string $folder,
        int $width = 800,
        int $height = 800,
        int $quality = 80
    ): ?string {

        if (! $file) {
            return null;
        }

        if (! str_starts_with($file->getMimeType(), 'image/')) {
            throw new \Exception('Invalid image file.');
        }

        $filename = Str::random(10).'_'.time().'.webp';
        $path = "uploads/{$folder}/{$filename}";

        Storage::disk('public')->makeDirectory("uploads/{$folder}");

        // Decode uploaded file
        $image = $this->manager->decode($file);

        // Resize
        $image->scaleDown(
            width: $width,
            height: $height
        );

        // Encode as WebP
        $encoded = $image->encode(new WebpEncoder(quality: $quality));

        Storage::disk('public')->put($path, (string) $encoded);

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    public static function getImageUrl(?string $path): ?string
    {
        if (! $path) {
            return asset('img/default.png');
        }

        return asset('storage/'.ltrim($path, '/'));
    }

    public static function fileUrl(?string $path): array|string|null
    {
        if (empty($path)) {
            return null;
        }

        $decoded = json_decode($path, true);

        if (is_array($decoded)) {
            return array_map(function ($item) {
                if (! $item) {
                    return null;
                }

                return str_starts_with($item, 'http://') || str_starts_with($item, 'https://')
                    ? $item
                    : asset('storage/'.ltrim($item, '/'));
            }, $decoded);
        }

        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            ? $path
            : asset('storage/'.ltrim($path, '/'));
    }

    public static function avatarUrl(?string $path): string
    {
        if (empty($path)) {
            return asset('img/default.png');
        }

        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            ? $path
            : asset('storage/'.ltrim($path, '/'));
    }
}
