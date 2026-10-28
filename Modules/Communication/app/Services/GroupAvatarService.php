<?php

declare(strict_types=1);

namespace Modules\Communication\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\Communication\Traits\UniqueIdentifierTrait;

class GroupAvatarService
{
    use UniqueIdentifierTrait;

    /**
     * @param  array<int>  $participantIds
     */
    public function generateConversationAvatar(int $conversationId, array $participantIds): ?string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $uniqueParticipantIds = collect($participantIds)
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($uniqueParticipantIds === []) {
            return null;
        }

        $participants = User::query()
            ->whereIn('id', $uniqueParticipantIds)
            ->get(['id', 'name', 'avatar'])
            ->keyBy('id');

        $avatarItems = [];
        foreach ($uniqueParticipantIds as $participantId) {
            $participant = $participants->get($participantId);

            if (! $participant instanceof User) {
                continue;
            }

            $avatarItems[] = [
                'name' => $this->userDisplayName($participant),
                'avatar' => (string) ($participant->avatar ?? ''),
            ];

            if (count($avatarItems) === 4) {
                break;
            }
        }

        if ($avatarItems === []) {
            return null;
        }

        $canvas = $this->buildGroupAvatarCanvas($avatarItems);

        if ($canvas === null || $canvas === false) {
            return null;
        }

        ob_start();
        imagepng($canvas, null, 8);
        $pngBinary = ob_get_clean();
        imagedestroy($canvas);

        if (! is_string($pngBinary) || $pngBinary === '') {
            return null;
        }

        $relativePath = sprintf('communication/conversations/avatars/group_%d_%s.png', $conversationId, $this->generateUniqueId('av_'));

        Storage::disk('public')->put($relativePath, $pngBinary);

        return url(Storage::url($relativePath));
    }

    /**
     * @param  array<int, array{name: string, avatar: string}>  $avatarItems
     */
    private function buildGroupAvatarCanvas(array $avatarItems)
    {
        $size = 512;
        $padding = 18;
        $gap = 8;

        $canvas = imagecreatetruecolor($size, $size);

        if ($canvas === false) {
            return null;
        }

        imagealphablending($canvas, true);
        imagesavealpha($canvas, true);

        $background = imagecolorallocate($canvas, 4, 74, 66);
        imagefilledrectangle($canvas, 0, 0, $size, $size, $background);

        $slots = $this->groupAvatarSlots(min(4, count($avatarItems)), $size, $padding, $gap);

        foreach ($slots as $index => $slot) {
            $avatarItem = $avatarItems[$index] ?? null;

            if (! is_array($avatarItem)) {
                continue;
            }

            $tile = $this->buildAvatarTile(
                (string) ($avatarItem['avatar'] ?? ''),
                (string) ($avatarItem['name'] ?? ''),
                (int) ($slot['width'] ?? 0),
                (int) ($slot['height'] ?? 0),
                $index
            );

            if ($tile === null || $tile === false) {
                continue;
            }

            imagecopy(
                $canvas,
                $tile,
                (int) ($slot['x'] ?? 0),
                (int) ($slot['y'] ?? 0),
                0,
                0,
                (int) ($slot['width'] ?? 0),
                (int) ($slot['height'] ?? 0)
            );

            imagedestroy($tile);
        }

        return $canvas;
    }

    /**
     * @return array<int, array{x: int, y: int, width: int, height: int}>
     */
    private function groupAvatarSlots(int $tileCount, int $size, int $padding, int $gap): array
    {
        $innerSize = $size - ($padding * 2);

        if ($tileCount <= 1) {
            return [[
                'x' => $padding,
                'y' => $padding,
                'width' => $innerSize,
                'height' => $innerSize,
            ]];
        }

        if ($tileCount === 2) {
            $tileWidth = (int) floor(($innerSize - $gap) / 2);

            return [
                ['x' => $padding, 'y' => $padding, 'width' => $tileWidth, 'height' => $innerSize],
                ['x' => $padding + $tileWidth + $gap, 'y' => $padding, 'width' => $tileWidth, 'height' => $innerSize],
            ];
        }

        $tileSize = (int) floor(($innerSize - $gap) / 2);

        if ($tileCount === 3) {
            return [
                [
                    'x' => $padding + (int) floor(($innerSize - $tileSize) / 2),
                    'y' => $padding,
                    'width' => $tileSize,
                    'height' => $tileSize,
                ],
                [
                    'x' => $padding,
                    'y' => $padding + $tileSize + $gap,
                    'width' => $tileSize,
                    'height' => $tileSize,
                ],
                [
                    'x' => $padding + $tileSize + $gap,
                    'y' => $padding + $tileSize + $gap,
                    'width' => $tileSize,
                    'height' => $tileSize,
                ],
            ];
        }

        return [
            ['x' => $padding, 'y' => $padding, 'width' => $tileSize, 'height' => $tileSize],
            ['x' => $padding + $tileSize + $gap, 'y' => $padding, 'width' => $tileSize, 'height' => $tileSize],
            ['x' => $padding, 'y' => $padding + $tileSize + $gap, 'width' => $tileSize, 'height' => $tileSize],
            ['x' => $padding + $tileSize + $gap, 'y' => $padding + $tileSize + $gap, 'width' => $tileSize, 'height' => $tileSize],
        ];
    }

    private function buildAvatarTile(string $avatar, string $name, int $width, int $height, int $index)
    {
        if ($width <= 0 || $height <= 0) {
            return null;
        }

        $tile = imagecreatetruecolor($width, $height);

        if ($tile === false) {
            return null;
        }

        imagealphablending($tile, true);
        imagesavealpha($tile, true);

        [$red, $green, $blue] = $this->avatarFallbackColor($index);
        $fallbackColor = imagecolorallocate($tile, $red, $green, $blue);
        imagefilledrectangle($tile, 0, 0, $width, $height, $fallbackColor);

        $source = $this->createImageFromAvatar($avatar);

        if ($source !== null && $source !== false) {
            $this->copyImageCover($source, $tile, $width, $height);
            imagedestroy($source);

            return $tile;
        }

        $this->drawAvatarInitials($tile, $name, $width, $height);

        return $tile;
    }

    private function createImageFromAvatar(string $avatar)
    {
        $imageBinary = $this->resolveAvatarImageBinary($avatar);

        if ($imageBinary === null) {
            return null;
        }

        $image = @imagecreatefromstring($imageBinary);

        return $image === false ? null : $image;
    }

    private function resolveAvatarImageBinary(string $avatar): ?string
    {
        $normalizedAvatar = trim($avatar);

        if ($normalizedAvatar === '') {
            return null;
        }

        $localPath = $this->resolveAvatarLocalPath($normalizedAvatar);

        if ($localPath !== null) {
            $contents = @file_get_contents($localPath);

            if (is_string($contents) && $contents !== '') {
                return $contents;
            }
        }

        if (! filter_var($normalizedAvatar, FILTER_VALIDATE_URL)) {
            return null;
        }

        try {
            $response = Http::timeout(5)->retry(1, 150)->get($normalizedAvatar);

            if (! $response->successful()) {
                return null;
            }

            $contents = $response->body();

            return $contents !== '' ? $contents : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveAvatarLocalPath(string $avatar): ?string
    {
        $path = $avatar;

        if (filter_var($avatar, FILTER_VALIDATE_URL)) {
            $path = (string) parse_url($avatar, PHP_URL_PATH);
        }

        $path = '/'.ltrim($path, '/');

        if ($path === '/') {
            return null;
        }

        if (str_starts_with($path, '/storage/')) {
            $relativeStoragePath = ltrim((string) substr($path, strlen('/storage/')), '/');
            $storageFile = storage_path('app/public/'.$relativeStoragePath);

            if (is_file($storageFile)) {
                return $storageFile;
            }
        }

        $publicFile = public_path(ltrim($path, '/'));

        return is_file($publicFile) ? $publicFile : null;
    }

    private function copyImageCover($sourceImage, $destinationImage, int $targetWidth, int $targetHeight): void
    {
        $sourceWidth = imagesx($sourceImage);
        $sourceHeight = imagesy($sourceImage);

        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            return;
        }

        $sourceRatio = $sourceWidth / $sourceHeight;
        $targetRatio = $targetWidth / $targetHeight;

        $cropWidth = $sourceWidth;
        $cropHeight = $sourceHeight;
        $cropX = 0;
        $cropY = 0;

        if ($sourceRatio > $targetRatio) {
            $cropWidth = (int) round($sourceHeight * $targetRatio);
            $cropX = (int) floor(($sourceWidth - $cropWidth) / 2);
        } else {
            $cropHeight = (int) round($sourceWidth / $targetRatio);
            $cropY = (int) floor(($sourceHeight - $cropHeight) / 2);
        }

        imagecopyresampled(
            $destinationImage,
            $sourceImage,
            0,
            0,
            $cropX,
            $cropY,
            $targetWidth,
            $targetHeight,
            $cropWidth,
            $cropHeight
        );
    }

    private function drawAvatarInitials($image, string $name, int $width, int $height): void
    {
        $initials = $this->avatarInitials($name);
        $font = 5;

        $textWidth = imagefontwidth($font) * strlen($initials);
        $textHeight = imagefontheight($font);

        $x = (int) max(0, floor(($width - $textWidth) / 2));
        $y = (int) max(0, floor(($height - $textHeight) / 2));

        $textColor = imagecolorallocate($image, 255, 255, 255);
        imagestring($image, $font, $x, $y, $initials, $textColor);
    }

    private function avatarInitials(string $name): string
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $name));

        if ($normalized === '') {
            return 'GC';
        }

        $initials = '';

        foreach (explode(' ', $normalized) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            $initials .= strtoupper(substr($part, 0, 1));

            if (strlen($initials) >= 2) {
                break;
            }
        }

        return $initials !== '' ? $initials : 'GC';
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function avatarFallbackColor(int $index): array
    {
        $palette = [
            [15, 118, 110],
            [14, 116, 144],
            [8, 145, 178],
            [21, 94, 117],
        ];

        return $palette[$index % count($palette)];
    }

    private function userDisplayName(?User $user): string
    {
        if ($user === null) {
            return 'Unknown';
        }

        $name = trim((string) ($user->name ?? ''));

        if ($name !== '') {
            return $name;
        }

        return 'User '.(int) $user->id;
    }
}
