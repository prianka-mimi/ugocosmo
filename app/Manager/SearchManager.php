<?php

namespace App\Manager;

use App\Http\Resources\EventResource;
use App\Http\Resources\JobResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ServiceResource;
use App\Http\Resources\UserResource;
use App\Models\Event;
use App\Models\JobListing;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Models\UserConnection;

class SearchManager
{
    public function search(string $query, string $type = 'all', string $userType = 'all', int $perPage = 20): array
    {
        $results = [];
        $searchTerm = trim($query);

        // 1. Users search
        if ($type === 'all' || $type === 'users') {
            $usersQuery = User::query()
                ->where('role', '!=', 'admin')
                ->with('profile');

            if ($userType === 'individual') {
                $usersQuery->where('account_type', 'individual');
            } elseif ($userType === 'business') {
                $usersQuery->where('account_type', 'business');
            }

            if ($searchTerm !== '') {
                $usersQuery->where(function ($q) use ($searchTerm) {
                    $q->where('name', 'like', "%{$searchTerm}%")
                        ->orWhere('username', 'like', "%{$searchTerm}%")
                        ->orWhere('email', 'like', "%{$searchTerm}%")
                        ->orWhere('occupation', 'like', "%{$searchTerm}%");
                });
            }

            $usersPaginator = $usersQuery->paginate($perPage, ['*'], 'users_page');

            // Optimisation: Pre-load connection records to prevent N+1 queries in UserResource
            $currentUser = auth('jwt')->user();
            $currentProfileId = $currentUser?->profile?->id;
            if ($currentProfileId && $usersPaginator->count() > 0) {
                $profileIds = $usersPaginator->pluck('profile.id')->filter()->all();
                if (! empty($profileIds)) {
                    $connections = UserConnection::whereIn('connection_type', ['professional', 'social', 'friend'])
                        ->where(function ($q) use ($currentProfileId, $profileIds) {
                            $q->where('requester_id', $currentProfileId)->whereIn('recipient_id', $profileIds);
                        })
                        ->orWhere(function ($q) use ($currentProfileId, $profileIds) {
                            $q->whereIn('requester_id', $profileIds)->where('recipient_id', $currentProfileId);
                        })
                        ->get();

                    $connectionsMap = [];
                    foreach ($connections as $conn) {
                        $otherId = ((int) $conn->requester_id === (int) $currentProfileId)
                            ? (int) $conn->recipient_id
                            : (int) $conn->requester_id;
                        $connectionsMap[$otherId] = $conn;
                    }

                    foreach ($usersPaginator as $user) {
                        $otherProfileId = $user->profile?->id;
                        $user->connection_record = ($otherProfileId && isset($connectionsMap[$otherProfileId]))
                            ? $connectionsMap[$otherProfileId]
                            : null;
                    }
                }
            }

            $usersPaginator->getCollection()->transform(fn ($user) => new UserResource($user));
            $results['users'] = $usersPaginator;
        }

        // 2. Products search
        if ($type === 'all' || $type === 'products') {
            $productsQuery = Product::active()
                ->with(['seller.user', 'category']);

            if ($searchTerm !== '') {
                $productsQuery->where(function ($q) use ($searchTerm) {
                    $q->where('title', 'like', "%{$searchTerm}%")
                        ->orWhere('description', 'like', "%{$searchTerm}%")
                        ->orWhere('brand', 'like', "%{$searchTerm}%");
                });
            }

            $productsPaginator = $productsQuery->paginate($perPage, ['*'], 'products_page');
            $productsPaginator->getCollection()->transform(fn ($product) => new ProductResource($product));
            $results['products'] = $productsPaginator;
        }

        // 3. Services search
        if ($type === 'all' || $type === 'services') {
            $servicesQuery = Service::active()
                ->with(['provider.user', 'category']);

            if ($searchTerm !== '') {
                $servicesQuery->where(function ($q) use ($searchTerm) {
                    $q->where('title', 'like', "%{$searchTerm}%")
                        ->orWhere('description', 'like', "%{$searchTerm}%")
                        ->orWhere('location', 'like', "%{$searchTerm}%")
                        ->orWhere('city', 'like', "%{$searchTerm}%")
                        ->orWhere('country', 'like', "%{$searchTerm}%");
                });
            }

            $servicesPaginator = $servicesQuery->paginate($perPage, ['*'], 'services_page');
            $servicesPaginator->getCollection()->transform(fn ($service) => new ServiceResource($service));
            $results['services'] = $servicesPaginator;
        }

        // 4. Jobs search
        if ($type === 'all' || $type === 'jobs') {
            $jobsQuery = JobListing::active()
                ->notExpired()
                ->with(['poster.user']);

            if ($searchTerm !== '') {
                $jobsQuery->where(function ($q) use ($searchTerm) {
                    $q->where('title', 'like', "%{$searchTerm}%")
                        ->orWhere('description', 'like', "%{$searchTerm}%")
                        ->orWhere('company', 'like', "%{$searchTerm}%")
                        ->orWhere('location', 'like', "%{$searchTerm}%");
                });
            }

            $jobsPaginator = $jobsQuery->paginate($perPage, ['*'], 'jobs_page');
            $jobsPaginator->getCollection()->transform(fn ($job) => new JobResource($job));
            $results['jobs'] = $jobsPaginator;
        }

        // 5. Events search
        if ($type === 'all' || $type === 'events') {
            $eventsQuery = Event::active()
                ->published()
                ->upcoming()
                ->with(['organizer.user']);

            if ($searchTerm !== '') {
                $eventsQuery->where(function ($q) use ($searchTerm) {
                    $q->where('title', 'like', "%{$searchTerm}%")
                        ->orWhere('description', 'like', "%{$searchTerm}%")
                        ->orWhere('location', 'like', "%{$searchTerm}%")
                        ->orWhere('city', 'like', "%{$searchTerm}%")
                        ->orWhere('country', 'like', "%{$searchTerm}%");
                });
            }

            $eventsPaginator = $eventsQuery->paginate($perPage, ['*'], 'events_page');
            $eventsPaginator->getCollection()->transform(fn ($event) => new EventResource($event));
            $results['events'] = $eventsPaginator;
        }

        return $results;
    }
}
