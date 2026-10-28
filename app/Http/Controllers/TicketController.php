<?php

namespace App\Http\Controllers;

use App\Http\Requests\TicketIndexRequest;
use App\Http\Resources\EventTicketResource;
use App\Manager\TicketManager;
use App\Models\Event;
use App\Models\EventTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TicketController extends Controller
{
    protected TicketManager $ticketManager;

    public function __construct(TicketManager $ticketManager)
    {
        $this->ticketManager = $ticketManager;
    }

    public function purchase(Event $event): JsonResponse
    {
        try {
            $data = request()->validate([
                'tickets' => ['nullable', 'array'],
                'tickets.*.customer_name' => ['nullable', 'string', 'max:255'],
                'tickets.*.customer_email' => ['nullable', 'email', 'max:255'],
                'tickets.*.customer_dob' => ['nullable', 'date_format:Y-m-d'],
                'tickets.*.customer_address' => ['nullable', 'string', 'max:500'],
                'tickets.*.section_id' => ['nullable', 'integer'],
                'tickets.*.seat_id' => ['nullable', 'integer'],

                'customer_name' => ['nullable', 'string', 'max:255'],
                'customer_email' => ['nullable', 'email', 'max:255'],
                'customer_dob' => ['nullable', 'date_format:Y-m-d'],
                'customer_address' => ['nullable', 'string', 'max:500'],
                'section_id' => ['nullable', 'integer'],
                'seat_id' => ['nullable', 'integer'],
            ]);

            DB::beginTransaction();

            if (! empty($data['tickets'])) {
                $ticketsData = array_map(function ($ticket) use ($data) {
                    return [
                        'customer_name' => $ticket['customer_name'] ?? $data['customer_name'] ?? null,
                        'customer_email' => $ticket['customer_email'] ?? $data['customer_email'] ?? null,
                        'customer_dob' => $ticket['customer_dob'] ?? $data['customer_dob'] ?? null,
                        'customer_address' => $ticket['customer_address'] ?? $data['customer_address'] ?? null,
                        'section_id' => $ticket['section_id'] ?? null,
                        'seat_id' => $ticket['seat_id'] ?? null,
                    ];
                }, $data['tickets']);

                $tickets = $this->ticketManager->purchaseMultiple($event, $ticketsData);
                $this->data = EventTicketResource::collection($tickets);
            } else {
                $ticket = $this->ticketManager->purchase(
                    $event,
                    $data['customer_name'] ?? null,
                    $data['customer_email'] ?? null,
                    $data['customer_dob'] ?? null,
                    $data['customer_address'] ?? null,
                    $data['section_id'] ?? null,
                    $data['seat_id'] ?? null
                );
                $this->data = EventTicketResource::make($ticket);
            }

            DB::commit();

            $this->status_message = 'Ticket added to cart';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Ticket purchase error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function myTickets(TicketIndexRequest $request): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 20);
            $this->data = EventTicketResource::collection($this->ticketManager->myTickets($perPage))->response()->getData();
            $this->status_message = 'Tickets retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('My tickets retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function show(EventTicket $ticket): JsonResponse
    {
        try {
            $this->data = EventTicketResource::make($this->ticketManager->show($ticket));
            $this->status_message = 'Ticket retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Ticket retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function use(Event $event, EventTicket $ticket): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = EventTicketResource::make(
                $this->ticketManager->useTicket($event, $ticket)
            );
            DB::commit();

            $this->status_message = 'Ticket checked in successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Ticket check-in error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function eventTickets(Event $event): JsonResponse
    {
        try {
            $this->data = EventTicketResource::collection($this->ticketManager->eventTickets($event));
            $this->status_message = 'Event tickets retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Event tickets retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function organizerTickets(Request $request): JsonResponse
    {
        try {
            $user = auth()->guard('jwt')->user();
            $profile = $user?->profile;
            if (! $profile) {
                throw new \Exception('Profile not found. Complete your profile first.', 400);
            }

            $data = $request->validate([
                'page' => ['nullable', 'integer', 'min:1'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
                'search' => ['nullable', 'string', 'max:255'],
                'event_id' => ['nullable', 'integer', 'exists:events,id'],
                'status' => ['nullable', 'string', 'max:50'],
            ]);

            $perPage = (int) ($data['per_page'] ?? 20);
            $profileId = (int) $profile->id;

            // 1. Stats Query (Valid, Used, Refunded/Cancelled counts)
            $statsQuery = EventTicket::whereHas('event', function ($q) use ($profileId) {
                $q->where('organizer_id', $profileId);
            });

            if (! empty($data['event_id'])) {
                $statsQuery->where('event_id', $data['event_id']);
            }

            $stats = [
                'valid' => (clone $statsQuery)->whereIn('status', ['confirmed', 'active'])->count(),
                'used' => (clone $statsQuery)->where('status', 'used')->count(),
                'refunded' => (clone $statsQuery)->whereIn('status', ['refunded', 'cancelled'])->count(),
            ];

            // 2. Filtered Tickets Query
            $ticketsQuery = EventTicket::whereHas('event', function ($q) use ($profileId) {
                $q->where('organizer_id', $profileId);
            })
                ->with(['event', 'customer', 'section', 'seat'])
                ->latestFirst();

            if (! empty($data['event_id'])) {
                $ticketsQuery->where('event_id', $data['event_id']);
            }

            if (! empty($data['status']) && $data['status'] !== 'all') {
                if ($data['status'] === 'valid') {
                    $ticketsQuery->whereIn('status', ['confirmed', 'active']);
                } elseif ($data['status'] === 'refunded') {
                    $ticketsQuery->whereIn('status', ['refunded', 'cancelled']);
                } else {
                    $ticketsQuery->where('status', $data['status']);
                }
            }

            if (! empty($data['search'])) {
                $search = $data['search'];
                $ticketsQuery->where(function ($q) use ($search) {
                    $q->where('ticket_number', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%");
                });
            }

            $paginated = $ticketsQuery->paginate($perPage);

            $resourceCollection = EventTicketResource::collection($paginated);
            $paginationData = $resourceCollection->response()->getData(true);

            $this->data = [
                'stats' => $stats,
                'tickets' => $paginationData['data'] ?? [],
                'links' => $paginationData['links'] ?? null,
                'meta' => $paginationData['meta'] ?? null,
            ];

            $this->status_message = 'Organizer tickets retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Organizer tickets retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
