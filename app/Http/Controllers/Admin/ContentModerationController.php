<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Booking;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\JobApplication;
use App\Models\JobListing;
use App\Models\Post;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Throwable;

class ContentModerationController extends Controller
{
    // ─── Posts ────────────────────────────────────────────────────

    public function posts()
    {
        $filters = request()->only(['is_active', 'search']);
        $query = Post::with(['author', 'reports.reporter']);

        if (isset($filters['is_active'])) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (! empty($filters['search'])) {
            $query->where('content', 'like', "%{$filters['search']}%");
        }

        $posts = $query->orderBy('created_at', 'desc')->paginate(20);

        return view('modules.admin.moderation.posts', compact('posts'));
    }

    public function showPost(Post $post)
    {
        $post->load(['author.user', 'reports.reporter.user']);

        return view('modules.admin.moderation.posts_show', compact('post'));
    }

    public function showProduct(Product $product)
    {
        $product->load(['seller.user', 'category']);

        return view('modules.admin.moderation.products_show', compact('product'));
    }

    public function showService(Service $service)
    {
        $service->load(['provider.user', 'category', 'packages', 'bookings.customer.user']);

        return view('modules.admin.moderation.services_show', compact('service'));
    }

    public function showEvent(Event $event)
    {
        $event->load(['organizer.user', 'sections', 'tickets.customer.user']);

        return view('modules.admin.moderation.events_show', compact('event'));
    }

    public function showJob(JobListing $job)
    {
        $job->load(['poster.user', 'applications.applicant.user']);

        return view('modules.admin.moderation.jobs_show', compact('job'));
    }

    public function showBooking(Booking $booking)
    {
        $booking->load(['service.provider.user', 'customer.user', 'provider.user']);

        return view('modules.admin.moderation.bookings_show', compact('booking'));
    }

    public function showJobApplication(JobApplication $application)
    {
        $application->load(['job.poster.user', 'applicant.user']);

        return view('modules.admin.moderation.job_applications_show', compact('application'));
    }

    public function showTicket(EventTicket $ticket)
    {
        $ticket->load(['event.organizer.user', 'customer.user', 'section', 'seat']);

        return view('modules.admin.moderation.tickets_show', compact('ticket'));
    }

    public function deactivatePost(Post $post)
    {
        try {
            DB::beginTransaction();
            $post->update(['is_active' => false, 'is_reported' => true]);
            $this->logAudit('post_deactivated', 'post', $post->id, ['was_active' => true]);
            DB::commit();

            return back()->with('success', 'Post deactivated successfully.');
        } catch (Throwable $e) {
            DB::rollBack();

            return back()->with('error', $e->getMessage());
        }
    }

    public function activatePost(Post $post)
    {
        try {
            DB::beginTransaction();
            $post->update(['is_active' => true, 'is_reported' => false]);
            $this->logAudit('post_activated', 'post', $post->id, []);
            DB::commit();

            return back()->with('success', 'Post activated successfully.');
        } catch (Throwable $e) {
            DB::rollBack();

            return back()->with('error', $e->getMessage());
        }
    }

    // ─── Products ─────────────────────────────────────────────────

    public function products()
    {
        $query = Product::with(['seller', 'category']);
        if (request()->has('is_active') && request('is_active') !== null && request('is_active') !== '') {
            $query->where('is_active', filter_var(request('is_active'), FILTER_VALIDATE_BOOLEAN));
        }
        if (request()->has('search') && ! empty(request('search'))) {
            $search = request('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }
        $products = $query->orderBy('created_at', 'desc')->paginate(20);

        return view('modules.admin.moderation.products', compact('products'));
    }

    public function deactivateProduct(Product $product)
    {
        try {
            DB::beginTransaction();
            $product->update(['is_active' => false]);
            $this->logAudit('product_deactivated', 'product', $product->id, []);
            DB::commit();

            return back()->with('success', 'Product deactivated successfully.');
        } catch (Throwable $e) {
            DB::rollBack();

            return back()->with('error', $e->getMessage());
        }
    }

    public function activateProduct(Product $product)
    {
        try {
            DB::beginTransaction();
            $product->update(['is_active' => true]);
            $this->logAudit('product_activated', 'product', $product->id, []);
            DB::commit();

            return back()->with('success', 'Product activated successfully.');
        } catch (Throwable $e) {
            DB::rollBack();

            return back()->with('error', $e->getMessage());
        }
    }

    // ─── Services ─────────────────────────────────────────────────

    public function services()
    {
        $query = Service::with('provider');
        if (request()->has('is_active') && request('is_active') !== null && request('is_active') !== '') {
            $query->where('is_active', filter_var(request('is_active'), FILTER_VALIDATE_BOOLEAN));
        }
        if (request()->has('search') && ! empty(request('search'))) {
            $query->where(function ($q) {
                $q->where('title', 'like', '%'.request('search').'%')
                    ->orWhere('service_type', 'like', '%'.request('search').'%')
                    ->orWhere('description', 'like', '%'.request('search').'%');
            });
        }
        $services = $query->orderBy('created_at', 'desc')->paginate(20);

        return view('modules.admin.moderation.services', compact('services'));
    }

    public function deactivateService(Service $service)
    {
        try {
            DB::beginTransaction();
            $service->update(['is_active' => false]);
            $this->logAudit('service_deactivated', 'service', $service->id, []);
            DB::commit();

            return back()->with('success', 'Service deactivated successfully.');
        } catch (Throwable $e) {
            DB::rollBack();

            return back()->with('error', $e->getMessage());
        }
    }

    public function activateService(Service $service)
    {
        try {
            DB::beginTransaction();
            $service->update(['is_active' => true]);
            $this->logAudit('service_activated', 'service', $service->id, []);
            DB::commit();

            return back()->with('success', 'Service activated successfully.');
        } catch (Throwable $e) {
            DB::rollBack();

            return back()->with('error', $e->getMessage());
        }
    }

    // ─── Events ───────────────────────────────────────────────────

    public function events()
    {
        $query = Event::with('organizer');
        if (request()->has('is_active') && request('is_active') !== null && request('is_active') !== '') {
            $query->where('is_active', filter_var(request('is_active'), FILTER_VALIDATE_BOOLEAN));
        }
        if (request()->has('search') && ! empty(request('search'))) {
            $query->where(function ($q) {
                $q->where('title', 'like', '%'.request('search').'%')
                    ->orWhere('venue', 'like', '%'.request('search').'%')
                    ->orWhere('description', 'like', '%'.request('search').'%');
            });
        }
        $events = $query->orderBy('created_at', 'desc')->paginate(20);

        return view('modules.admin.moderation.events', compact('events'));
    }

    public function deactivateEvent(Event $event)
    {
        try {
            DB::beginTransaction();
            $event->update(['is_active' => false]);
            $this->logAudit('event_deactivated', 'event', $event->id, []);
            DB::commit();

            return back()->with('success', 'Event deactivated successfully.');
        } catch (Throwable $e) {
            DB::rollBack();

            return back()->with('error', $e->getMessage());
        }
    }

    public function activateEvent(Event $event)
    {
        try {
            DB::beginTransaction();
            $event->update(['is_active' => true]);
            $this->logAudit('event_activated', 'event', $event->id, []);
            DB::commit();

            return back()->with('success', 'Event activated successfully.');
        } catch (Throwable $e) {
            DB::rollBack();

            return back()->with('error', $e->getMessage());
        }
    }

    // ─── Jobs ─────────────────────────────────────────────────────

    public function jobs()
    {
        $query = JobListing::with('poster');
        if (request()->has('is_active') && request('is_active') !== null && request('is_active') !== '') {
            $query->where('is_active', filter_var(request('is_active'), FILTER_VALIDATE_BOOLEAN));
        }
        if (request()->has('search') && ! empty(request('search'))) {
            $query->where(function ($q) {
                $q->where('title', 'like', '%'.request('search').'%')
                    ->orWhere('company', 'like', '%'.request('search').'%')
                    ->orWhere('description', 'like', '%'.request('search').'%');
            });
        }
        $jobs = $query->orderBy('created_at', 'desc')->paginate(20);

        return view('modules.admin.moderation.jobs', compact('jobs'));
    }

    public function deactivateJob(JobListing $job)
    {
        try {
            DB::beginTransaction();
            $job->update(['is_active' => false]);
            $this->logAudit('job_deactivated', 'job', $job->id, []);
            DB::commit();

            return back()->with('success', 'Job deactivated successfully.');
        } catch (Throwable $e) {
            DB::rollBack();

            return back()->with('error', $e->getMessage());
        }
    }

    public function activateJob(JobListing $job)
    {
        try {
            DB::beginTransaction();
            $job->update(['is_active' => true]);
            $this->logAudit('job_activated', 'job', $job->id, []);
            DB::commit();

            return back()->with('success', 'Job activated successfully.');
        } catch (Throwable $e) {
            DB::rollBack();

            return back()->with('error', $e->getMessage());
        }
    }

    // ─── Bookings ─────────────────────────────────────────────────

    public function bookings()
    {
        $query = Booking::with(['service', 'customer', 'provider']);
        $bookings = $query->orderBy('created_at', 'desc')->paginate(20);

        return view('modules.admin.moderation.bookings', compact('bookings'));
    }

    public function deactivateBooking(Booking $booking)
    {
        try {
            DB::beginTransaction();
            $booking->update(['status' => 'cancelled']);
            $this->logAudit('booking_cancelled', 'booking', $booking->id, []);
            DB::commit();

            return back()->with('success', 'Booking cancelled successfully.');
        } catch (Throwable $e) {
            DB::rollBack();

            return back()->with('error', $e->getMessage());
        }
    }

    public function activateBooking(Booking $booking)
    {
        try {
            DB::beginTransaction();
            $booking->update(['status' => 'confirmed']);
            $this->logAudit('booking_confirmed', 'booking', $booking->id, []);
            DB::commit();

            return back()->with('success', 'Booking confirmed successfully.');
        } catch (Throwable $e) {
            DB::rollBack();

            return back()->with('error', $e->getMessage());
        }
    }

    // ─── Job Applications ─────────────────────────────────────────

    public function jobApplications()
    {
        $query = JobApplication::with(['job', 'applicant']);
        $applications = $query->orderBy('created_at', 'desc')->paginate(20);

        return view('modules.admin.moderation.job_applications', compact('applications'));
    }

    public function deactivateJobApplication(JobApplication $application)
    {
        try {
            DB::beginTransaction();
            $application->update(['status' => 'rejected']);
            $this->logAudit('job_application_rejected', 'job_application', $application->id, []);
            DB::commit();

            return back()->with('success', 'Job application rejected.');
        } catch (Throwable $e) {
            DB::rollBack();

            return back()->with('error', $e->getMessage());
        }
    }

    public function activateJobApplication(JobApplication $application)
    {
        try {
            DB::beginTransaction();
            $application->update(['status' => 'accepted']);
            $this->logAudit('job_application_accepted', 'job_application', $application->id, []);
            DB::commit();

            return back()->with('success', 'Job application accepted.');
        } catch (Throwable $e) {
            DB::rollBack();

            return back()->with('error', $e->getMessage());
        }
    }

    // ─── Event Tickets ────────────────────────────────────────────

    public function tickets()
    {
        $query = EventTicket::with(['event', 'customer']);
        $tickets = $query->orderBy('created_at', 'desc')->paginate(20);

        return view('modules.admin.moderation.tickets', compact('tickets'));
    }

    public function deactivateTicket(EventTicket $ticket)
    {
        try {
            DB::beginTransaction();
            $ticket->update(['status' => 'cancelled']);
            $this->logAudit('ticket_cancelled', 'event_ticket', $ticket->id, []);
            DB::commit();

            return back()->with('success', 'Ticket cancelled successfully.');
        } catch (Throwable $e) {
            DB::rollBack();

            return back()->with('error', $e->getMessage());
        }
    }

    public function activateTicket(EventTicket $ticket)
    {
        try {
            DB::beginTransaction();
            $ticket->update(['status' => 'confirmed']);
            $this->logAudit('ticket_confirmed', 'event_ticket', $ticket->id, []);
            DB::commit();

            return back()->with('success', 'Ticket confirmed successfully.');
        } catch (Throwable $e) {
            DB::rollBack();

            return back()->with('error', $e->getMessage());
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────

    protected function logAudit(string $action, string $entityType, ?int $entityId, array $metadata): void
    {
        try {
            AdminAuditLog::create([
                'admin_id' => auth()->user()?->profile?->id,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'new_value' => $metadata,
                'ip_address' => request()->ip(),
            ]);
        } catch (\Exception $e) {
            logger()->error('Failed to log audit: '.$e->getMessage());
        }
    }
}
