<?php

namespace App\Manager;

use App\Models\JobListing;
use App\Models\JobQualifyingQuestion;
use App\Models\JobTag;
use Illuminate\Support\Facades\Auth;

class JobManager
{
    public function index(array $params = [], int $perPage = 20)
    {
        $query = JobListing::active()
            ->with('poster')
            ->withCount(['applications', 'saves']);

        if (! empty($params['job_type'])) {
            $query->where('job_type', $params['job_type']);
        }

        // Handle is_remote / remote boolean filter (properly supports false values)
        if (array_key_exists('is_remote', $params)) {
            $query->where('is_remote', filter_var($params['is_remote'], FILTER_VALIDATE_BOOLEAN));
        } elseif (array_key_exists('remote', $params)) {
            $query->where('is_remote', filter_var($params['remote'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($params['location'])) {
            $query->where('location', 'like', "%{$params['location']}%");
        }

        if (! empty($params['city']) && strtolower(trim($params['city'])) !== 'any city') {
            $city = trim($params['city']);
            $query->where(function ($q) use ($city) {
                $q->where('location', 'like', "%{$city}%")
                    ->orWhereHas('poster', function ($q2) use ($city) {
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
                $q->where('location', 'like', "%{$country}%")
                    ->orWhereHas('poster', function ($q2) use ($country) {
                        $q2->where('location', 'like', "%{$country}%")
                            ->orWhereHas('user', function ($q3) use ($country) {
                                $q3->where('country', 'like', "%{$country}%");
                            });
                    });
            });
        }

        $minSalary = $params['min_salary'] ?? $params['min_price'] ?? null;
        if (isset($minSalary) && $minSalary !== null && $minSalary !== '') {
            $minVal = (float) $minSalary;
            $query->where(function ($q) use ($minVal) {
                $q->where('salary_max', '>=', $minVal)
                    ->orWhere('salary_min', '>=', $minVal);
            });
        }

        $maxSalary = $params['max_salary'] ?? $params['max_price'] ?? null;
        if (isset($maxSalary) && $maxSalary !== null && $maxSalary !== '') {
            $maxVal = (float) $maxSalary;
            $query->where(function ($q) use ($maxVal) {
                $q->where('salary_min', '<=', $maxVal)
                    ->orWhere('salary_max', '<=', $maxVal);
            });
        }

        if (! empty($params['search'])) {
            $search = $params['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('company', 'like', "%{$search}%");
            });
        }

        // Filter by tag (tags are used for search/filtering only, not exposed in response)
        if (! empty($params['tag'])) {
            $query->byTag($params['tag']);
        }

        // Determine which scope to apply
        $hasStatus = ! empty($params['status']);
        $hasExpiredFilter = array_key_exists('expired', $params) && ! empty($params['expired']);
        $hasActiveFilter = array_key_exists('active', $params);

        if ($hasStatus) {
            match ($params['status']) {
                'active' => $query->where('is_active', true)->notExpired(),
                'inactive' => $query->where('is_active', false),
                'expired' => $query->expired(),
                default => null,
            };
        } elseif ($hasActiveFilter) {
            $query->where('is_active', filter_var($params['active'], FILTER_VALIDATE_BOOLEAN));
            // If filtering for active jobs, also exclude expired ones
            if (filter_var($params['active'], FILTER_VALIDATE_BOOLEAN)) {
                $query->notExpired();
            }
        } elseif ($hasExpiredFilter) {
            $query->expired();
        } else {
            // Default: only show active, non-expired listings
            $query->notExpired();
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
            } elseif (in_array($sort, ['price_asc', 'price_low_high', 'salary_asc', 'salary_low_high'])) {
                $query->orderByRaw('COALESCE(salary_min, salary_max) ASC');
                $sortField = null;
            } elseif (in_array($sort, ['price_desc', 'price_high_low', 'salary_desc', 'salary_high_low'])) {
                $query->orderByRaw('COALESCE(salary_max, salary_min) DESC');
                $sortField = null;
            } elseif (in_array($sort, ['highest_rated', 'rating_desc'])) {
                $query->orderByRaw('(applications_count * 2 + saves_count) DESC');
                $sortField = null;
            } elseif (in_array($sort, ['most_popular', 'popular'])) {
                $query->orderByRaw('(applications_count * 3 + saves_count) DESC');
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

        $query = JobListing::byPoster($profileId)
            ->latestFirst()
            ->with(['poster', 'tags', 'qualifyingQuestions']);

        if (! empty($params['search'])) {
            $search = $params['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('company', 'like', "%{$search}%");
            });
        }

        return $query->get()
            ->map(fn (JobListing $job) => $this->format($job))
            ->all();
    }

    public function store(array $data): JobListing
    {
        $profileId = (int) $this->profileId();

        // Map remote_position to is_remote if provided
        if (array_key_exists('remote_position', $data) && ! array_key_exists('is_remote', $data)) {
            $data['is_remote'] = $data['remote_position'];
        }

        $job = JobListing::create([
            'poster_id' => $profileId,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'company' => $data['company'] ?? null,
            'location' => $data['location'] ?? null,
            'salary_min' => $data['salary_min'] ?? null,
            'salary_max' => $data['salary_max'] ?? null,
            'job_type' => $data['job_type'] ?? null,
            'is_remote' => $data['is_remote'] ?? false,
            'is_active' => $data['is_active'] ?? true,
            'end_date' => $data['end_date'] ?? null,
        ]);

        // Sync tags
        if (! empty($data['tags'])) {
            $this->syncTags($job, $data['tags']);
        }

        // Create qualifying questions
        if (! empty($data['qualifying_questions'])) {
            $this->syncQualifyingQuestions($job, $data['qualifying_questions']);
        }

        return $job->load(['poster', 'tags', 'qualifyingQuestions']);
    }

    public function show(JobListing $job): JobListing
    {
        // Auto-expire if end_date has passed
        if ($job->end_date && $job->end_date->isPast() && $job->is_active) {
            $job->update(['is_active' => false]);
        }

        return $job->load(['poster', 'tags', 'qualifyingQuestions']);
    }

    public function update(JobListing $job, array $data): JobListing
    {
        $this->ensureOwned($job);

        // Map remote_position to is_remote if provided
        if (array_key_exists('remote_position', $data) && ! array_key_exists('is_remote', $data)) {
            $data['is_remote'] = $data['remote_position'];
        }

        $fillable = [
            'title', 'description', 'company', 'location',
            'salary_min', 'salary_max', 'job_type', 'is_remote',
            'is_active', 'end_date',
        ];

        foreach ($fillable as $field) {
            if (array_key_exists($field, $data)) {
                $job->$field = $data[$field];
            }
        }

        $job->save();

        // Sync tags
        if (array_key_exists('tags', $data)) {
            $this->syncTags($job, $data['tags'] ?? []);
        }

        // Sync qualifying questions
        if (array_key_exists('qualifying_questions', $data)) {
            $this->syncQualifyingQuestions($job, $data['qualifying_questions'] ?? []);
        }

        return $job->load(['poster', 'tags', 'qualifyingQuestions']);
    }

    public function destroy(JobListing $job): void
    {
        $this->ensureOwned($job);
        $job->delete();
    }

    /**
     * Sync tags for a job listing.
     * Creates new tags if they don't exist, then syncs the relationship.
     */
    protected function syncTags(JobListing $job, array $tagNames): void
    {
        $tagIds = [];
        foreach (array_unique($tagNames) as $name) {
            $tag = JobTag::firstOrCreate(['name' => trim($name)]);
            $tagIds[] = $tag->id;
        }
        $job->tags()->sync($tagIds);
    }

    /**
     * Sync qualifying questions for a job listing.
     * Replaces all existing questions with the provided ones.
     */
    protected function syncQualifyingQuestions(JobListing $job, array $questions): void
    {
        $job->qualifyingQuestions()->delete();

        $orderedQuestions = [];
        foreach ($questions as $index => $question) {
            $orderedQuestions[] = [
                'job_id' => $job->id,
                'question' => $question['question'],
                'expected_answer' => $question['expected_answer'],
                'is_required' => $question['required'] ?? false,
                'display_order' => $index,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (! empty($orderedQuestions)) {
            JobQualifyingQuestion::insert($orderedQuestions);
        }
    }

    protected function format(JobListing $job): array
    {
        return [
            'id' => $job->id,
            'title' => $job->title,
            'description' => $job->description,
            'company' => $job->company,
            'location' => $job->location,
            'salary_min' => $job->salary_min,
            'salary_max' => $job->salary_max,
            'job_type' => $job->job_type,
            'is_remote' => (bool) $job->is_remote,
            'is_active' => (bool) $job->is_active,
            'end_date' => $job->end_date?->toDateString(),
            'tags' => $job->tags->pluck('name'),
            'qualifying_questions' => $job->relationLoaded('qualifyingQuestions')
                ? $job->qualifyingQuestions->map(fn ($q) => [
                    'id' => $q->id,
                    'question' => $q->question,
                    'expected_answer' => $q->expected_answer,
                    'required' => (bool) $q->is_required,
                    'display_order' => $q->display_order,
                ])
                : [],
            'created_at' => $job->created_at?->toIso8601String(),
            'updated_at' => $job->updated_at?->toIso8601String(),
        ];
    }

    protected function ensureOwned(JobListing $job): void
    {
        if ((int) $job->poster_id !== (int) $this->profileId()) {
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
