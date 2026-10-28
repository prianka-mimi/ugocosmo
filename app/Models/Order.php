<?php

namespace App\Models;

use App\Services\Fulfillment\FulfillmentProviderResolver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'buyer_id',
        'seller_id',
        'status',
        'subtotal',
        'buyer_fee',
        'seller_fee',
        'platform_fee_total',
        'tax_amount',
        'total',
        'shipping_address',
        'shipping_method',
        'selected_rate_id',
        'selected_carrier_id',
        'selected_carrier_code',
        'selected_service_code',
        'selected_service_name',
        'shipping_amount',
        'shipping_currency',
        'selected_rate_snapshot',
        'tracking_number',
        'stripe_payment_intent_id',
        // ── AliExpress dropshipping (supplier orders) ─────────────
        'aliexpress_order_numbers',
        'aliexpress_placed_at',
        'aliexpress_logistics',
        // ── AliExpress manual purchase record ─────────────────────
        'purchase_reference',
        'supplier_name',
        'supplier_cost',
        'purchase_date',
        'purchase_notes',
        'purchased_at',
        'stock_received_at',
        'shipped_at',
        'delivered_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'buyer_fee' => 'decimal:2',
        'seller_fee' => 'decimal:2',
        'platform_fee_total' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'shipping_address' => 'array',
        'shipping_amount' => 'decimal:2',
        'selected_rate_snapshot' => 'array',
        'aliexpress_order_numbers' => 'array',
        'aliexpress_placed_at' => 'datetime',
        'aliexpress_logistics' => 'array',
        'supplier_cost' => 'decimal:2',
        'purchase_date' => 'date',
        'purchased_at' => 'datetime',
        'stock_received_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'buyer_id');
    }

    /**
     * Alias for buyer profile relationship
     */
    public function user(): BelongsTo
    {
        return $this->buyer();
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'seller_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function transaction(): HasOne
    {
        return $this->hasOne(Transaction::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /**
     * Shipping cost selected (and backend-verified) at checkout.
     */
    public function hasSelectedShippingRate(): bool
    {
        return $this->selected_rate_id !== null && (float) $this->shipping_amount > 0;
    }

    /**
     * Whether the order can no longer be modified by the buyer (paid or
     * beyond) — used to guard rate selection.
     */
    public function isTerminalForPayment(): bool
    {
        return in_array($this->status, [
            'processing',
            'awaiting_purchase',
            'purchased',
            'stock_received',
            'ready_to_ship',
            'shipped',
            'delivered',
            'completed',
            'cancelled',
        ], true);
    }

    public function timelineEvents(): HasMany
    {
        return $this->hasMany(OrderTimelineEvent::class, 'order_id')->orderBy('created_at');
    }

    public function scopeByBuyer($query, int $profileId)
    {
        return $this->scopeForBuyer($query, $profileId);
    }

    public function scopeForBuyer($query, int $profileId, ?int $userId = null)
    {
        return $query->where(function ($q) use ($profileId, $userId) {
            if ($profileId > 0) {
                $q->where('orders.buyer_id', $profileId);
            }
            if ($userId !== null && $userId > 0) {
                $q->orWhere('orders.buyer_id', $userId);
            }
        });
    }

    public function scopeBySeller($query, int $profileId)
    {
        return $this->scopeForSeller($query, $profileId);
    }

    public function scopeForSeller($query, int $profileId, ?int $userId = null)
    {
        return $query->where(function ($q) use ($profileId, $userId) {
            if ($profileId > 0) {
                $q->where('orders.seller_id', $profileId);
            }
            if ($userId !== null && $userId > 0) {
                $q->orWhere('orders.seller_id', $userId);
            }
            // Include orders where seller's products (custom/local, aliexpress, etsy, csv, etc.) were ordered
            $q->orWhereHas('items.product', function ($pq) use ($profileId, $userId) {
                $pq->where(function ($sub) use ($profileId, $userId) {
                    if ($profileId > 0) {
                        $sub->where('seller_id', $profileId);
                    }
                    if ($userId !== null && $userId > 0) {
                        $sub->orWhere('seller_id', $userId);
                    }
                });
            })
            // Include orders where seller's services were ordered
                ->orWhereHas('items.service', function ($sq) use ($profileId, $userId) {
                    $sq->where(function ($sub) use ($profileId, $userId) {
                        if ($profileId > 0) {
                            $sub->where('provider_id', $profileId);
                        }
                        if ($userId !== null && $userId > 0) {
                            $sub->orWhere('provider_id', $userId);
                        }
                    });
                })
            // Include orders where seller's events were ordered
                ->orWhereHas('items.event', function ($eq) use ($profileId, $userId) {
                    $eq->where(function ($sub) use ($profileId, $userId) {
                        if ($profileId > 0) {
                            $sub->where('organizer_id', $profileId);
                        }
                        if ($userId !== null && $userId > 0) {
                            $sub->orWhere('organizer_id', $userId);
                        }
                    });
                });
        });
    }

    public function scopeFilter($query, array $filters = [])
    {
        if (! empty($filters['status']) && strtolower(trim((string) $filters['status'])) !== 'all') {
            $rawStatus = trim((string) $filters['status']);
            if (str_contains($rawStatus, ',')) {
                $statuses = array_filter(array_map('trim', explode(',', $rawStatus)));
                $query->whereIn('orders.status', $statuses);
            } else {
                $query->where('orders.status', $rawStatus);
            }
        }

        if (! empty($filters['source']) && strtolower(trim((string) $filters['source'])) !== 'all') {
            $source = strtolower(trim((string) $filters['source']));
            $query->whereHas('items.product', function ($pq) use ($source) {
                $pq->where('source', $source);
            });
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('orders.tracking_number', 'like', "%{$search}%")
                    ->orWhere('orders.stripe_payment_intent_id', 'like', "%{$search}%")
                    ->orWhereHas('buyer', function ($bq) use ($search) {
                        $bq->where('full_name', 'like', "%{$search}%")
                            ->orWhere('username', 'like', "%{$search}%");
                    })
                    ->orWhereHas('items', function ($iq) use ($search) {
                        $iq->where('title', 'like', "%{$search}%");
                    });
            });
        }

        if (! empty($filters['from_date'])) {
            $query->whereDate('orders.created_at', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->whereDate('orders.created_at', '<=', $filters['to_date']);
        }

        return $query;
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    // ─── Fulfillment helpers (state inspection only) ───────────────

    public function hasPurchaseRecord(): bool
    {
        return $this->purchased_at !== null;
    }

    public function isAliExpressFulfilled(): bool
    {
        if ($this->relationLoaded('items')) {
            return $this->items->contains(function ($item) {
                $product = $item->product;

                return $product && (strtolower((string) $product->source) === 'aliexpress' || ! empty($product->aliexpress_product_id));
            });
        }

        return $this->items()->whereHas('product', function ($q) {
            $q->where('source', 'aliexpress')
                ->orWhereNotNull('aliexpress_product_id');
        })->exists();
    }

    public function hasPhysicalItems(): bool
    {
        if ($this->relationLoaded('items')) {
            return $this->items->contains(function ($item) {
                return ! empty($item->product_id) || $item->product !== null;
            });
        }

        return $this->items()->whereNotNull('product_id')->exists();
    }

    /**
     * Determine whether this order requires ShipEngine shipping rate selection
     * before payment can proceed.
     *
     * Production-ready & future-proof:
     * - Returns false if all products are digital or from external/dropshipped channels
     *   (AliExpress, Shopify, Etsy, etc. as defined in config/shipping.php).
     * - Returns true if physical products (self-created, CSV-imported, etc.) are present
     *   that use tracked shipping.
     */
    public function requiresShippingRate(): bool
    {
        return app(FulfillmentProviderResolver::class)
            ->orderRequiresPrePaymentShippingRate($this);
    }
}
