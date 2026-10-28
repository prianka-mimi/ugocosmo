<?php

declare(strict_types=1);

namespace Modules\Communication\Traits;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

trait FileManagementTrait
{
    public function uploadFile($file, string $path, ?string $disk = null): string
    {
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw new InvalidArgumentException('Invalid file upload payload.');
        }

        // Use the provided disk or fallback to the application default
        $disk = $disk ?? config('filesystems.default');

        // Fallback to public if S3 is requested but not configured locally
        if ($disk === 's3' && empty(config('filesystems.disks.s3.key'))) {
            $disk = 'public';
        }

        $storedPath = $file->store($path, $disk);
        if ($storedPath === false) {
            throw new \RuntimeException('Failed to store the uploaded file.');
        }

        return Storage::disk($disk)->url($storedPath);
    }

    public function deleteFile(string $path, ?string $disk = null): bool
    {
        if ($path === '') {
            return false;
        }

        // Use the provided disk or fallback to the application default
        $disk = $disk ?? config('filesystems.default');

        // Fallback to public if S3 is requested but not configured locally
        if ($disk === 's3' && empty(config('filesystems.disks.s3.key'))) {
            $disk = 'public';
        }
        $storageDisk = Storage::disk($disk);
        $normalizedPath = $path;

        // Extract the path if a full URL is provided
        if (Str::startsWith($normalizedPath, ['http://', 'https://'])) {
            $parsedPath = (string) parse_url($normalizedPath, PHP_URL_PATH);
            $normalizedPath = ltrim($parsedPath, '/');
        }

        // Strip the 'storage/' prefix only if using the local public disk
        if ($disk === 'public' && Str::startsWith($normalizedPath, 'storage/')) {
            $normalizedPath = Str::after($normalizedPath, 'storage/');
        }

        if (! $storageDisk->exists($normalizedPath)) {
            return false;
        }

        return $storageDisk->delete($normalizedPath);
    }
}
