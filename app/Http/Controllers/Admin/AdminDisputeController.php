<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Dispute;
use App\Models\Order;
use App\Services\OrderTimelineService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdminDisputeController extends Controller
{
    public function index()
    {
        $query = Dispute::with(['order', 'raisedBy', 'resolvedBy']);

        if ($status = request('status')) {
            $query->where('status', $status);
        }
        if ($search = request('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('order', fn ($o) => $o->where('id', 'like', "%{$search}%"))
                    ->orWhereHas('raisedBy', fn ($r) => $r->where('full_name', 'like', "%{$search}%"))
                    ->orWhere('reason', 'like', "%{$search}%");
            });
        }

        $disputes = $query->latestFirst()->paginate(20);
        $statuses = ['pending', 'investigating', 'resolved', 'dismissed'];

        return view('modules.admin.disputes.index', compact('disputes', 'statuses'));
    }

    public function show(Dispute $dispute)
    {
        $dispute->load(['order.buyer', 'order.seller', 'order.items', 'escrowPayment', 'raisedBy', 'resolvedBy']);

        return view('modules.admin.disputes.show', compact('dispute'));
    }

    public function updateStatus(Dispute $dispute)
    {
        $status = request()->input('status');
        $allowed = ['pending', 'investigating', 'resolved', 'dismissed'];

        if (! in_array($status, $allowed)) {
            return back()->with('error', 'Invalid dispute status.');
        }

        try {
            DB::beginTransaction();

            $oldStatus = $dispute->status;
            $updateData = ['status' => $status];

            if (in_array($status, ['resolved', 'dismissed'])) {
                $updateData['resolved_by'] = auth()->user()?->profile?->id;
                $updateData['resolved_at'] = now();
                $updateData['resolution_notes'] = request()->input('resolution_notes', '');
                $updateData['resolution'] = request()->input('resolution', $status);
            }

            $dispute->update($updateData);

            // If resolved with refund, update the order
            if ($status === 'resolved' && request()->input('resolution') === 'refund_buyer') {
                $orderOldStatus = $dispute->order->status;
                $dispute->order->update(['status' => 'refunded']);
                app(OrderTimelineService::class)->record(
                    $dispute->order,
                    'refunded',
                    auth()->user()?->profile?->id,
                    OrderTimelineService::ACTOR_ADMIN,
                    'Order refunded after dispute resolution',
                    ['from' => $orderOldStatus, 'dispute_id' => $dispute->id]
                );
            }

            // If resolved with release to seller
            if ($status === 'resolved' && request()->input('resolution') === 'release_seller') {
                $orderOldStatus = $dispute->order->status;
                $dispute->order->update(['status' => 'completed']);
                app(OrderTimelineService::class)->record(
                    $dispute->order,
                    'completed',
                    auth()->user()?->profile?->id,
                    OrderTimelineService::ACTOR_ADMIN,
                    'Order completed after dispute resolution',
                    ['from' => $orderOldStatus, 'dispute_id' => $dispute->id]
                );
            }

            AdminAuditLog::create([
                'admin_id' => auth()->user()?->profile?->id,
                'action' => 'dispute_resolved',
                'entity_type' => 'dispute',
                'entity_id' => $dispute->id,
                'old_value' => ['status' => $oldStatus],
                'new_value' => $dispute->toArray(),
                'ip_address' => request()->ip(),
            ]);

            DB::commit();

            return back()->with('success', 'Dispute #'.$dispute->id.' updated to: '.ucfirst($status));
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Dispute update failed: '.$e->getMessage());

            return back()->with('error', $e->getMessage());
        }
    }
}
