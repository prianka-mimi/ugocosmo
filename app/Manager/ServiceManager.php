<?php

namespace App\Manager;

use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\ServiceTag;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ServiceManager
{
    public function index(array $params = [], int $perPage = 20)
    {
        $query = Service::active()
            ->with(['provider', 'category', 'tags'])
            ->withAvg('reviews', 'rating')
            ->withCount(['reviews', 'saves', 'bookings']);

        $categoryId = $params['category_id'] ?? $params['category'] ?? null;
        if (! empty($categoryId)) {
            if (is_numeric($categoryId)) {
                $query->where('category_id', (int) $categoryId);
            } else {
                $query->whereHas('category', function ($q) use ($categoryId) {
                    $q->where('name', 'like', "%{$categoryId}%");
                });
            }
        }

        if (! empty($params['service_type'])) {
            $query->where('service_type', $params['service_type']);
        }

        if (! empty($params['price_type'])) {
            $query->where('price_type', $params['price_type']);
        }

        if (isset($params['min_price']) && $params['min_price'] !== null && $params['min_price'] !== '') {
            $query->where('price', '>=', (float) $params['min_price']);
        }

        if (isset($params['max_price']) && $params['max_price'] !== null && $params['max_price'] !== '') {
            $query->where('price', '<=', (float) $params['max_price']);
        }

        if (isset($params['min_rating']) && $params['min_rating'] !== null && $params['min_rating'] !== '') {
            $minRating = (float) $params['min_rating'];
            $query->whereRaw('(SELECT COALESCE(AVG(rating), 0) FROM service_reviews WHERE service_reviews.service_id = services.id) >= ?', [$minRating]);
        }

        if (! empty($params['city']) && strtolower(trim($params['city'])) !== 'any city') {
            $city = trim($params['city']);
            $query->where(function ($q) use ($city) {
                $q->where('city', 'like', "%{$city}%")
                    ->orWhere('location', 'like', "%{$city}%")
                    ->orWhereHas('provider', function ($q2) use ($city) {
                        $q2->where('location', 'like', "%{$city}%")
                            ->orWhereHas('user', function ($q3) use ($city) {
                                $q3->where('city', 'like', "%{$city}%");
                            });
                    });
            });
        }

        if (! empty($params['country']) && strtolower(trim($params['country'])) !== 'any country') {
            $country = trim($params['country']);
            $query->where(function ($q) use ($country) {
                $q->where('country', 'like', "%{$country}%")
                    ->orWhere('location', 'like', "%{$country}%")
                    ->orWhereHas('provider', function ($q2) use ($country) {
                        $q2->where('location', 'like', "%{$country}%")
                            ->orWhereHas('user', function ($q3) use ($country) {
                                $q3->where('country', 'like', "%{$country}%");
                            });
                    });
            });
        }

        if (! empty($params['tag'])) {
            $query->byTag($params['tag']);
        }

        if (isset($params['is_insured'])) {
            $query->where('is_insured', filter_var($params['is_insured'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($params['is_certified'])) {
            $query->where('is_certified', filter_var($params['is_certified'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($params['search'])) {
            $search = $params['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $sortField = 'created_at';
        $sortDir = 'desc';
        if (! empty($params['sort'])) {
            $sort = $params['sort'];
            if (in_array($sort, ['newest', 'latest'])) {
                $sortField = 'created_at';
                $sortDir = 'desc';
            } elseif ($sort === 'oldest') {
                $sortField = 'created_at';
                $sortDir = 'asc';
            } elseif (in_array($sort, ['price_asc', 'price_low_high'])) {
                $sortField = 'price';
                $sortDir = 'asc';
            } elseif (in_array($sort, ['price_desc', 'price_high_low'])) {
                $sortField = 'price';
                $sortDir = 'desc';
            } elseif (in_array($sort, ['highest_rated', 'rating_desc'])) {
                $query->orderByRaw('COALESCE(reviews_avg_rating, 0) DESC');
                $sortField = null;
            } elseif (in_array($sort, ['most_popular', 'popular'])) {
                $query->orderByRaw('(bookings_count * 3 + saves_count * 2 + reviews_count) DESC');
                $sortField = null;
            }
        }

        if ($sortField) {
            $query->orderBy($sortField, $sortDir);
        }

        return $query->paginate($perPage);
    }

    public function mine(array $params = []): array
    {
        $profileId = (int) $this->profileId();

        $query = Service::byProvider($profileId)
            ->latestFirst()
            ->with(['provider', 'category', 'packages', 'tags']);

        if (! empty($params['search'])) {
            $search = $params['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $query->get()
            ->map(fn (Service $service) => $this->format($service))
            ->all();
    }

    public function store(array $data): Service
    {
        $profileId = (int) $this->profileId();

        if (($data['service_type'] ?? null) === 'online') {
            $data['delivery_location'] = null;
            $data['address'] = null;
            $data['location'] = null;
            $data['country'] = null;
            $data['city'] = null;
            $data['service_area'] = null;
            $data['areas_of_coverage'] = null;
        }

        $service = Service::create([
            'provider_id' => $profileId,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'price' => $data['price'],
            'currency' => $data['currency'] ?? 'USD',
            'price_type' => $data['price_type'] ?? null,
            'service_type' => $data['service_type'] ?? null,
            'delivery_location' => $data['delivery_location'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'estimated_delivery_time' => $data['estimated_delivery_time'] ?? null,
            'is_insured' => $data['is_insured'] ?? false,
            'is_certified' => $data['is_certified'] ?? false,
            'location' => $data['location'] ?? null,
            'address' => $data['address'] ?? null,
            'country' => $data['country'] ?? null,
            'city' => $data['city'] ?? null,
            'service_area' => $data['service_area'] ?? null,
            'areas_of_coverage' => $data['areas_of_coverage'] ?? null,
        ]);

        // Handle single image upload
        if (isset($data['image'])) {
            $service->image_url = app(ImageManager::class)->upload($data['image'], 'services');
        }

        // Handle multiple images upload
        if (isset($data['images'])) {
            $paths = [];
            foreach ($data['images'] as $image) {
                $paths[] = app(ImageManager::class)->upload($image, 'services');
            }
            $service->images = $paths;
        }

        // Handle video upload
        if (isset($data['video'])) {
            $service->video_url = $this->uploadVideo($data['video'], 'services');
        }

        $service->save();

        // Create packages
        if (! empty($data['packages'])) {
            $this->syncPackages($service, $data['packages']);
        }

        // Sync tags
        if (! empty($data['tags'])) {
            $this->syncTags($service, $data['tags']);
        }

        return $service->load(['provider', 'category', 'packages', 'tags']);
    }

    public function show(Service $service): Service
    {
        return $service->load(['provider', 'category', 'packages', 'tags']);
    }

    public function update(Service $service, array $data): Service
    {
        $this->ensureOwned($service);

        $serviceType = $data['service_type'] ?? $service->service_type;
        $deliveryLocation = array_key_exists('delivery_location', $data) ? $data['delivery_location'] : $service->delivery_location;
        $address = array_key_exists('address', $data) ? $data['address'] : $service->address;

        if ($serviceType === 'online') {
            $data['delivery_location'] = null;
            $data['address'] = null;
            $data['location'] = null;
            $data['country'] = null;
            $data['city'] = null;
            $data['service_area'] = null;
            $data['areas_of_coverage'] = null;
        } else {
            if (empty($deliveryLocation)) {
                throw new \Exception('Delivery location is required for in-person/hybrid services.', 422);
            }
            if (in_array($deliveryLocation, ['At my location (customer comes to me)', 'Both options available']) && empty($address)) {
                throw new \Exception('Service address is required for the selected delivery location.', 422);
            }
        }

        $fillable = [
            'title', 'description', 'price', 'currency', 'price_type',
            'service_type', 'delivery_location', 'category_id', 'estimated_delivery_time',
            'is_insured', 'is_certified', 'location', 'address', 'country',
            'city', 'service_area', 'areas_of_coverage', 'is_active',
        ];

        foreach ($fillable as $field) {
            if (array_key_exists($field, $data)) {
                $service->$field = $data[$field];
            }
        }

        // Handle single image: remove or upload
        if (! empty($data['remove_image'])) {
            app(ImageManager::class)->delete($service->image_url);
            $service->image_url = null;
        } elseif (isset($data['image'])) {
            app(ImageManager::class)->delete($service->image_url);
            $service->image_url = app(ImageManager::class)->upload($data['image'], 'services');
        }

        // Handle multiple images (always replaces entire array)
        if (isset($data['images'])) {
            foreach (($service->images ?? []) as $oldImage) {
                app(ImageManager::class)->delete($oldImage);
            }
            $paths = [];
            foreach ($data['images'] as $image) {
                $paths[] = app(ImageManager::class)->upload($image, 'services');
            }
            $service->images = $paths;
        }

        // Handle video: remove or upload
        if (! empty($data['remove_video'])) {
            $this->deleteVideo($service->video_url);
            $service->video_url = null;
        } elseif (isset($data['video'])) {
            $this->deleteVideo($service->video_url);
            $service->video_url = $this->uploadVideo($data['video'], 'services');
        }

        $service->save();

        // Sync packages (replaces all existing)
        if (array_key_exists('packages', $data)) {
            $this->syncPackages($service, $data['packages'] ?? []);
        }

        // Sync tags
        if (array_key_exists('tags', $data)) {
            $this->syncTags($service, $data['tags'] ?? []);
        }

        return $service->load(['provider', 'category', 'packages', 'tags']);
    }

    public function destroy(Service $service): void
    {
        $this->ensureOwned($service);

        // Clean up images
        app(ImageManager::class)->delete($service->image_url);

        foreach (($service->images ?? []) as $image) {
            app(ImageManager::class)->delete($image);
        }

        // Clean up video
        $this->deleteVideo($service->video_url);

        // Delete packages and pivot tags (handled by cascade)
        $service->delete();
    }

    // ─────────────────────────────────────────────────────────────────
    //  Package Helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Sync packages for a service (replaces all existing).
     * Supports both creating new packages and updating existing ones by ID.
     */
    protected function syncPackages(Service $service, array $packages): void
    {
        $existingIds = $service->packages()->pluck('id')->toArray();
        $incomingIds = [];

        foreach ($packages as $packageData) {
            if (! empty($packageData['id'])) {
                // Update existing package
                $package = ServicePackage::find($packageData['id']);
                if ($package && (int) $package->service_id === (int) $service->id) {
                    $package->update([
                        'title' => $packageData['title'],
                        'description' => $packageData['description'] ?? null,
                        'price' => $packageData['price'],
                    ]);
                    $incomingIds[] = (int) $package->id;
                }
            } else {
                // Create new package
                $package = $service->packages()->create([
                    'title' => $packageData['title'],
                    'description' => $packageData['description'] ?? null,
                    'price' => $packageData['price'],
                ]);
                $incomingIds[] = (int) $package->id;
            }
        }

        // Delete packages that were not in the incoming set
        $toDelete = array_diff($existingIds, $incomingIds);
        if (! empty($toDelete)) {
            ServicePackage::whereIn('id', $toDelete)->delete();
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  Tag Helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Sync tags for a service.
     * Creates new tags if they don't exist, then syncs the relationship.
     */
    protected function syncTags(Service $service, array $tagNames): void
    {
        $tagIds = [];
        foreach (array_unique($tagNames) as $name) {
            $tag = ServiceTag::firstOrCreate(['name' => trim($name)]);
            $tagIds[] = $tag->id;
        }
        $service->tags()->sync($tagIds);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Video Upload Helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Upload a video file to storage.
     */
    protected function uploadVideo($file, string $folder): ?string
    {
        if (! $file) {
            return null;
        }

        $extension = $file->getClientOriginalExtension();
        $filename = Str::random(10).'_'.time().'.'.$extension;
        $path = "uploads/{$folder}/videos/{$filename}";

        Storage::disk('public')->makeDirectory("uploads/{$folder}/videos");
        Storage::disk('public')->put($path, file_get_contents($file));

        return $path;
    }

    /**
     * Delete a video file from storage.
     */
    protected function deleteVideo(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  Formatting & Helpers
    // ─────────────────────────────────────────────────────────────────

    protected function format(Service $service): array
    {
        return [
            'id' => $service->id,
            'title' => $service->title,
            'description' => $service->description,
            'price' => $service->price,
            'currency' => $service->currency,
            'price_type' => $service->price_type,
            'service_type' => $service->service_type,
            'category_id' => $service->category_id,
            'category' => $service->relationLoaded('category') && $service->category
                ? [
                    'id' => $service->category->id,
                    'name' => $service->category->name,
                ]
                : null,
            'estimated_delivery_time' => $service->estimated_delivery_time,
            'is_insured' => (bool) $service->is_insured,
            'is_certified' => (bool) $service->is_certified,
            'image_url' => ImageManager::fileUrl($service->image_url),
            'images' => $service->images
                ? array_map(fn ($img) => ImageManager::fileUrl($img), $service->images)
                : [],
            'video_url' => ImageManager::fileUrl($service->video_url),
            'location' => $service->location,
            'delivery_location' => $service->delivery_location,
            'address' => $service->address,
            'country' => $service->country,
            'city' => $service->city,
            'service_area' => $service->service_area,
            'areas_of_coverage' => $service->areas_of_coverage ?? [],
            'is_active' => (bool) $service->is_active,
            'packages' => $service->relationLoaded('packages')
                ? $service->packages->map(fn ($p) => [
                    'id' => $p->id,
                    'title' => $p->title,
                    'description' => $p->description,
                    'price' => $p->price,
                ])
                : [],
            'tags' => $service->relationLoaded('tags')
                ? $service->tags->pluck('name')
                : [],
            'created_at' => $service->created_at?->toIso8601String(),
            'updated_at' => $service->updated_at?->toIso8601String(),
        ];
    }

    protected function ensureOwned(Service $service): void
    {
        if ((int) $service->provider_id !== (int) $this->profileId()) {
            abort(404);
        }
    }

    protected function profileId(): int
    {
        $user = Auth::guard('jwt')->user();
        $profile = $user->profile;

        if (! $profile) {
            throw new \Exception('Profile not found. Complete your profile first.', 400);
        }

        return (int) $profile->id;
    }
}
