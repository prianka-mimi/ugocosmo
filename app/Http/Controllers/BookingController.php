<?php

namespace App\Http\Controllers;

use App\Http\Requests\BookingIndexRequest;
use App\Http\Requests\CreateBookingRequest;
use App\Http\Requests\DeliverBookingRequest;
use App\Http\Requests\RejectDeliveryRequest;
use App\Http\Requests\UpdateBookingStatusRequest;
use App\Http\Resources\BookingResource;
use App\Manager\BookingManager;
use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class BookingController extends Controller
{
    protected BookingManager $bookingManager;

    public function __construct(BookingManager $bookingManager)
    {
        $this->bookingManager = $bookingManager;
    }

    public function create(CreateBookingRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = BookingResource::make(
                $this->bookingManager->create($request->validated())
            );
            DB::commit();

            $this->status_message = 'Booking created successfully';
            $this->status_code = 201;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Booking creation error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function myBookings(BookingIndexRequest $request): JsonResponse
    {
        try {
            $role = $request->input('role', 'customer');
            $perPage = (int) $request->input('per_page', 20);

            if (! in_array($role, ['customer', 'provider'])) {
                $role = 'customer';
            }

            $this->data = BookingResource::collection(
                $this->bookingManager->myBookings($role, $perPage)
            )->response()->getData();

            $this->status_message = 'Bookings retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Bookings retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function show(Booking $booking): JsonResponse
    {
        try {
            $this->data = BookingResource::make($this->bookingManager->show($booking));
            $this->status_message = 'Booking retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Booking retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function cancel(UpdateBookingStatusRequest $request, Booking $booking): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = BookingResource::make(
                $this->bookingManager->cancel($booking, $request->input('notes'))
            );
            DB::commit();

            $this->status_message = 'Booking cancelled successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Booking cancellation error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function confirm(Booking $booking): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = BookingResource::make($this->bookingManager->confirm($booking));
            DB::commit();

            $this->status_message = 'Booking confirmed successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Booking confirm error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function reject(Booking $booking): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = BookingResource::make($this->bookingManager->reject($booking));
            DB::commit();

            $this->status_message = 'Booking rejected successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Booking reject error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function deliver(DeliverBookingRequest $request, Booking $booking): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = BookingResource::make($this->bookingManager->deliver($booking, $request->validated()));
            DB::commit();

            $this->status_message = 'Booking delivered successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Booking deliver error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function rejectDelivery(RejectDeliveryRequest $request, Booking $booking): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = BookingResource::make($this->bookingManager->rejectDelivery($booking, $request->validated()));
            DB::commit();

            $this->status_message = 'Booking delivery rejected successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Booking delivery reject error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function complete(Booking $booking): JsonResponse
    {
        try {
            DB::beginTransaction();
            $this->data = BookingResource::make($this->bookingManager->complete($booking));
            DB::commit();

            $this->status_message = 'Booking completed successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            DB::rollback();
            Log::error('Booking complete error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }
}
