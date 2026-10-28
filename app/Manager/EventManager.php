<?php

namespace App\Manager;

use App\Models\Event;
use App\Models\EventSeat;
use App\Models\EventSection;
use App\Models\EventTag;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EventManager
{
    public function index(array $params = [], int $perPage = 20)
    {
        $query = Event::published()
            ->active()
            ->with('organizer')
            ->withAvg('reviews', 'rating')
            ->withCount(['reviews', 'saves', 'tickets']);

        if (! empty($params['upcoming'])) {
            if (filter_var($params['upcoming'], FILTER_VALIDATE_BOOLEAN)) {
                $query->upcoming();
            }
        } else {
            $query->upcoming();
        }

        if (! empty($params['location'])) {
            $query->where('location', 'like', "%{$params['location']}%");
        }

        if (! empty($params['city']) && strtolower(trim($params['city'])) !== 'any city') {
            $city = trim($params['city']);
            $query->where(function ($q) use ($city) {
                $q->where('city', 'like', "%{$city}%")
                    ->orWhere('location', 'like', "%{$city}%")
                    ->orWhereHas('organizer', function ($q2) use ($city) {
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
                    ->orWhereHas('organizer', function ($q2) use ($country) {
                        $q2->where('location', 'like', "%{$country}%")
                            ->orWhereHas('user', function ($q3) use ($country) {
                                $q3->where('country', 'like', "%{$country}%");
                            });
                    });
            });
        }

        if (isset($params['min_price']) && $params['min_price'] !== null && $params['min_price'] !== '') {
            $query->where('price', '>=', (float) $params['min_price']);
        }

        if (isset($params['max_price']) && $params['max_price'] !== null && $params['max_price'] !== '') {
            $query->where('price', '<=', (float) $params['max_price']);
        }

        if (isset($params['min_rating']) && $params['min_rating'] !== null && $params['min_rating'] !== '') {
            $minRating = (float) $params['min_rating'];
            $query->whereRaw('(SELECT COALESCE(AVG(rating), 0) FROM event_reviews WHERE event_reviews.event_id = events.id) >= ?', [$minRating]);
        }

        if (! empty($params['search'])) {
            $search = $params['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Filter by tag
        if (! empty($params['tag'])) {
            $query->byTag($params['tag']);
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
            } elseif ($sort === 'date_asc') {
                $sortField = 'event_date';
                $sortDir = 'asc';
            } elseif ($sort === 'date_desc') {
                $sortField = 'event_date';
                $sortDir = 'desc';
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
                $query->orderByRaw('(tickets_count * 3 + saves_count * 2 + reviews_count) DESC');
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

        $query = Event::byOrganizer($profileId)
            ->latestFirst()
            ->with(['organizer', 'tags', 'sections.seats']);

        if (! empty($params['search'])) {
            $search = $params['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $query->get()
            ->map(fn (Event $event) => $this->format($event))
            ->all();
    }

    public function store(array $data): Event
    {
        $profileId = (int) $this->profileId();

        $event = Event::create([
            'organizer_id' => $profileId,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'country' => $data['country'] ?? null,
            'city' => $data['city'] ?? null,
            'location' => $data['location'],
            'event_date' => $data['event_date'],
            'end_date' => $data['end_date'] ?? null,
            'price' => $data['price'] ?? null,
            'currency' => $data['currency'] ?? 'USD',
            'max_attendees' => $data['max_attendees'] ?? null,
            'ticket_prefix' => $data['ticket_prefix'] ?? null,
            'is_published' => $data['is_published'] ?? true,
        ]);

        // Handle image upload
        if (isset($data['image'])) {
            $event->image_url = app(ImageManager::class)->upload($data['image'], 'events');
        }

        // Handle video upload
        if (isset($data['video'])) {
            $event->video_url = $this->uploadVideo($data['video'], 'events');
        }

        $event->save();

        // Sync tags
        if (! empty($data['tags'])) {
            $this->syncTags($event, $data['tags']);
        }

        // Create sections and seats
        if (! empty($data['sections'])) {
            $this->syncSections($event, $data['sections']);
        }

        return $event->load(['organizer', 'tags', 'sections.seats']);
    }

    public function update(Event $event, array $data): Event
    {
        $this->ensureOwned($event);

        $fillable = [
            'title', 'description', 'country', 'city', 'location',
            'event_date', 'end_date', 'price', 'currency',
            'max_attendees', 'ticket_prefix', 'is_active', 'is_published',
        ];

        foreach ($fillable as $field) {
            if (array_key_exists($field, $data)) {
                $event->$field = $data[$field];
            }
        }

        // Handle image: remove or upload
        if (! empty($data['remove_image'])) {
            app(ImageManager::class)->delete($event->image_url);
            $event->image_url = null;
        } elseif (isset($data['image'])) {
            app(ImageManager::class)->delete($event->image_url);
            $event->image_url = app(ImageManager::class)->upload($data['image'], 'events');
        }

        // Handle video: remove or upload
        if (! empty($data['remove_video'])) {
            $this->deleteVideo($event->video_url);
            $event->video_url = null;
        } elseif (isset($data['video'])) {
            $this->deleteVideo($event->video_url);
            $event->video_url = $this->uploadVideo($data['video'], 'events');
        }

        $event->save();

        // Sync tags
        if (array_key_exists('tags', $data)) {
            $this->syncTags($event, $data['tags'] ?? []);
        }

        // Sync sections and seats (replaces all existing)
        if (array_key_exists('sections', $data)) {
            // Delete existing sections and seats
            $event->sections()->each(function (EventSection $section) {
                $section->seats()->delete();
            });
            $event->sections()->delete();

            if (! empty($data['sections'])) {
                $this->syncSections($event, $data['sections']);
            }
        }

        return $event->load(['organizer', 'tags', 'sections.seats']);
    }

    public function show(Event $event): Event
    {
        return $event->load(['organizer', 'tags', 'sections.seats']);
    }

    public function destroy(Event $event): void
    {
        $this->ensureOwned($event);

        app(ImageManager::class)->delete($event->image_url);
        $this->deleteVideo($event->video_url);

        $event->delete();
    }

    // ─────────────────────────────────────────────────────────────────
    //  Tag Helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Sync tags for an event.
     * Creates new tags if they don't exist, then syncs the relationship.
     */
    protected function syncTags(Event $event, array $tagNames): void
    {
        $tagIds = [];
        foreach (array_unique($tagNames) as $name) {
            $tag = EventTag::firstOrCreate(['name' => trim($name)]);
            $tagIds[] = $tag->id;
        }
        $event->tags()->sync($tagIds);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Section & Seat Helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Create sections and their seats for an event.
     * Handles both individual seats and bulk seat ranges.
     * Uses batch inserts for performance with large numbers of seats.
     */
    protected function syncSections(Event $event, array $sections): void
    {
        foreach ($sections as $sectionData) {
            $section = $event->sections()->create([
                'section_name' => $sectionData['name'],
                'price' => $sectionData['price'] ?? null,
            ]);

            $existingSeatNumbers = [];
            $now = now();

            // Collect individual seats
            if (! empty($sectionData['seats'])) {
                foreach ($sectionData['seats'] as $seatNumber) {
                    $seatNumber = trim((string) $seatNumber);
                    if (! empty($seatNumber) && ! in_array($seatNumber, $existingSeatNumbers)) {
                        $existingSeatNumbers[] = $seatNumber;
                    }
                }
            }

            // Collect bulk seat ranges (e.g., "A1-A20", "VIP1-VIP100")
            if (! empty($sectionData['seat_ranges'])) {
                foreach ($sectionData['seat_ranges'] as $range) {
                    $range = trim($range);
                    if (empty($range)) {
                        continue;
                    }

                    $generatedSeats = $this->generateSeatRange($range);
                    foreach ($generatedSeats as $seatNumber) {
                        if (! in_array($seatNumber, $existingSeatNumbers)) {
                            $existingSeatNumbers[] = $seatNumber;
                        }
                    }
                }
            }

            // Batch insert all seats
            if (! empty($existingSeatNumbers)) {
                $seatRows = [];
                foreach ($existingSeatNumbers as $seatNumber) {
                    $seatRows[] = [
                        'section_id' => $section->id,
                        'seat_number' => $seatNumber,
                        'price' => null,
                        'status' => 'available',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                EventSeat::insert($seatRows);
            }
        }
    }

    /**
     * Generate seat numbers from a range string.
     *
     * Supports formats like:
     * - "A1-A20"  → A1, A2, A3, ..., A20
     * - "B1-B50"  → B1, B2, ..., B50
     * - "VIP1-VIP100" → VIP1, VIP2, ..., VIP100
     * - "1-30"    → 1, 2, 3, ..., 30
     *
     * @return string[]
     */
    protected function generateSeatRange(string $range): array
    {
        $parts = explode('-', $range);
        if (count($parts) !== 2) {
            return [];
        }

        $start = trim($parts[0]);
        $end = trim($parts[1]);

        if (empty($start) || empty($end)) {
            return [];
        }

        // Extract prefix (letters) and number from start
        preg_match('/^([a-zA-Z]*)(\d+)$/', $start, $startMatches);
        preg_match('/^([a-zA-Z]*)(\d+)$/', $end, $endMatches);

        // If no letter prefix, treat as plain numeric range
        if (empty($startMatches) && empty($endMatches) && is_numeric($start) && is_numeric($end)) {
            return $this->buildNumericRange('', (int) $start, (int) $end);
        }

        if (empty($startMatches) || empty($endMatches)) {
            return [];
        }

        $prefix = $startMatches[1];
        $endPrefix = $endMatches[1];

        // Prefixes must match (or both empty)
        if ($prefix !== $endPrefix) {
            return [];
        }

        $startNum = (int) $startMatches[2];
        $endNum = (int) $endMatches[2];

        return $this->buildNumericRange($prefix, $startNum, $endNum);
    }

    /**
     * Build an array of seat numbers for a given prefix and numeric range.
     *
     * @return string[]
     */
    protected function buildNumericRange(string $prefix, int $start, int $end): array
    {
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        // Limit to prevent massive generation
        $maxSeats = 10000;
        if (($end - $start + 1) > $maxSeats) {
            $end = $start + $maxSeats - 1;
        }

        $seats = [];
        for ($i = $start; $i <= $end; $i++) {
            $seats[] = $prefix.$i;
        }

        return $seats;
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

    protected function format(Event $event): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'description' => $event->description,
            'country' => $event->country,
            'city' => $event->city,
            'location' => $event->location,
            'event_date' => $event->event_date?->toIso8601String(),
            'end_date' => $event->end_date?->toIso8601String(),
            'price' => $event->price,
            'currency' => $event->currency,
            'max_attendees' => $event->max_attendees,
            'ticket_prefix' => $event->ticket_prefix,
            'image_url' => ImageManager::fileUrl($event->image_url),
            'video_url' => ImageManager::fileUrl($event->video_url),
            'is_active' => (bool) $event->is_active,
            'is_published' => (bool) $event->is_published,
            'tags' => $event->relationLoaded('tags') ? $event->tags->pluck('name') : [],
            'sections' => $event->relationLoaded('sections')
                ? $event->sections->map(fn (EventSection $section) => [
                    'id' => $section->id,
                    'name' => $section->section_name,
                    'price' => $section->price,
                    'seats' => $section->relationLoaded('seats')
                        ? $section->seats->map(fn (EventSeat $seat) => [
                            'id' => $seat->id,
                            'seat_number' => $seat->seat_number,
                            'price' => $seat->price,
                            'status' => $seat->status,
                        ])
                        : [],
                ])
                : [],
            'created_at' => $event->created_at?->toIso8601String(),
            'updated_at' => $event->updated_at?->toIso8601String(),
        ];
    }

    protected function ensureOwned(Event $event): void
    {
        if ((int) $event->organizer_id !== (int) $this->profileId()) {
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
