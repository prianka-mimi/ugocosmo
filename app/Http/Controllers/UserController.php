<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Http\Resources\UserResource;
use App\Manager\UserManager;
use App\Models\User;
use App\Models\UserConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class UserController extends Controller
{
    protected UserManager $userManager;

    public function __construct(UserManager $userManager)
    {
        $this->userManager = $userManager;
    }

    public function apiIndex(): JsonResponse
    {
        try {
            $status = request('status');
            $role = request('role');

            $query = User::query()->where('role', '!=', 'admin')->orderBy('id', 'desc');

            if ($status) {
                $query->where('status', $status);
            }

            if ($role) {
                $query->where('role', $role);
            }

            $users = $query->get();

            // Eager load profile relation to avoid N+1 queries
            $users->load('profile');

            $currentUser = auth('jwt')->user();
            $currentProfileId = $currentUser?->profile?->id;

            if ($currentProfileId) {
                $profileIds = $users->pluck('profile.id')->filter()->all();

                if (! empty($profileIds)) {
                    $connections = UserConnection::whereIn('connection_type', ['professional', 'social', 'friend'])
                        ->where(function ($q) use ($currentProfileId, $profileIds) {
                            $q->where('requester_id', $currentProfileId)
                                ->whereIn('recipient_id', $profileIds);
                        })
                        ->orWhere(function ($q) use ($currentProfileId, $profileIds) {
                            $q->whereIn('requester_id', $profileIds)
                                ->where('recipient_id', $currentProfileId);
                        })
                        ->get();

                    $connectionsMap = [];
                    foreach ($connections as $conn) {
                        $otherId = ((int) $conn->requester_id === (int) $currentProfileId)
                            ? (int) $conn->recipient_id
                            : (int) $conn->requester_id;
                        $connectionsMap[$otherId] = $conn;
                    }

                    foreach ($users as $user) {
                        $otherProfileId = $user->profile?->id;
                        $user->connection_record = ($otherProfileId && isset($connectionsMap[$otherProfileId]))
                            ? $connectionsMap[$otherProfileId]
                            : null;
                    }
                }
            }

            $this->data = UserResource::collection($users);
            $this->status_message = 'Users retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Users retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function index()
    {
        $users = $this->userManager->paginate(
            request('status'),
            request('role'),
            20
        );
        $statusFilter = request('status');
        $roleFilter = request('role');

        return view('modules.users.index', compact('users', 'statusFilter', 'roleFilter'));
    }

    public function create()
    {
        return view('modules.users.form', ['user' => null]);
    }

    public function store(UserStoreRequest $request)
    {
        try {
            DB::beginTransaction();
            $user = $this->userManager->create($request->validated());
            DB::commit();

            return redirect()->route('users.index')->with('success', 'User created successfully.');
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('User creation failed: '.$e->getMessage());

            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show(User $user)
    {
        $user->load([
            'profile.posts',
            'profile.products',
            'profile.services',
            'profile.jobs',
            'profile.events',
            'profile.bookings.service',
            'profile.jobApplications.job',
            'profile.orders.items.product',
            'profile.tickets.event',
        ]);

        return view('modules.users.show', compact('user'));
    }

    public function edit(User $user)
    {
        return view('modules.users.form', compact('user'));
    }

    public function update(UserUpdateRequest $request, User $user)
    {
        try {
            DB::beginTransaction();
            $user = $this->userManager->update($user, $request->validated());
            DB::commit();

            return redirect()->route('users.index')->with('success', 'User updated successfully.');
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('User update failed: '.$e->getMessage());

            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function destroy(User $user)
    {
        try {
            DB::beginTransaction();
            $this->userManager->delete($user);
            DB::commit();

            return redirect()->route('users.index')->with('success', 'User deleted successfully.');
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('User deletion failed: '.$e->getMessage());

            return back()->with('error', $e->getMessage());
        }
    }

    public function approve(User $user)
    {
        try {
            DB::beginTransaction();
            $success = $this->userManager->approve($user);
            DB::commit();

            if (! $success) {
                return redirect()->route('admin.users')->with('error', 'Cannot approve this user.');
            }

            return redirect()->route('admin.users')->with('success', 'User approved successfully.');
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('User approval failed: '.$e->getMessage());

            return redirect()->route('admin.users')->with('error', $e->getMessage());
        }
    }

    public function reject(User $user)
    {
        try {
            DB::beginTransaction();
            $success = $this->userManager->reject($user);
            DB::commit();

            if (! $success) {
                return redirect()->route('admin.users')->with('error', 'Cannot reject this user.');
            }

            return redirect()->route('admin.users')->with('success', 'User rejected successfully.');
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('User rejection failed: '.$e->getMessage());

            return redirect()->route('admin.users')->with('error', $e->getMessage());
        }
    }

    public function updateStatus(User $user)
    {
        try {
            DB::beginTransaction();
            $success = $this->userManager->updateStatus($user, request('status'));
            DB::commit();

            if (! $success) {
                return back()->with('error', 'Only supervisor users can have status changed.');
            }

            return back()->with('success', 'User status updated.');
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('User status update failed: '.$e->getMessage());

            return back()->with('error', $e->getMessage());
        }
    }

    public function search(Request $request): JsonResponse
    {
        $searchTerm = trim((string) $request->input('query', $request->input('search', '')));
        $perPage = (int) $request->integer('per_page', 10);
        if ($perPage < 1) {
            $perPage = 10;
        }

        $query = User::query()
            ->where('role', '!=', 'admin');

        if ($searchTerm !== '') {
            $like = '%'.$searchTerm.'%';
            $query->where('name', 'LIKE', $like);
        }

        $paginator = $query->paginate($perPage);

        $data = collect($paginator->items())->map(function (User $user) {
            $parts = explode(' ', trim((string) $user->name), 2);
            $firstName = $parts[0] ?? '';
            $lastName = $parts[1] ?? '';

            return [
                'id' => (int) $user->id,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'name' => $user->name ?? '',
                'avatar' => $user->avatar,
            ];
        })->all();

        $this->data = $data;
        $this->status_message = 'Users fetched successfully';
        $this->status_code = 200;
        $this->pagination = [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'first_page_url' => $paginator->url(1),
            'last_page_url' => $paginator->url($paginator->lastPage()),
            'next_page_url' => $paginator->nextPageUrl(),
            'prev_page_url' => $paginator->previousPageUrl(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'path' => $paginator->path(),
        ];

        return $this->JsonResponseFormat();
    }
}
