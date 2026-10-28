<?php

namespace App\Enums;

/**
 * Lifecycle of a local shipment record (the physical fulfillment of an
 * order through ShipEngine).
 *
 * draft                  – local record created, no label yet
 * created                – local record persisted, ready for rates/label
 * purchasing             – a label purchase is in flight (concurrency claim)
 * label_created          – a shipping label has been purchased
 * shipped                – carrier accepted / picked up the package
 * in_transit             – carrier moving the package between facilities
 * out_for_delivery       – on the delivery vehicle for final delivery
 * delivered              – carrier tracking reported delivery
 * exception              – delivery problem reported by the carrier
 * voided                 – the purchased label was voided
 * failed                 – the last external operation failed
 */
enum ShipmentStatus: string
{
    case Draft = 'draft';
    case Created = 'created';
    case Purchasing = 'purchasing';
    case LabelCreated = 'label_created';
    case Shipped = 'shipped';
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Exception = 'exception';
    case Voided = 'voided';
    case Failed = 'failed';

    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /**
     * Carriage-progress rank used to prevent a stale event from moving a
     * shipment backwards (e.g. in_transit after out_for_delivery).
     */
    public function progress(): int
    {
        return match ($this) {
            self::Draft, self::Created, self::Purchasing, self::LabelCreated => 0,
            self::Shipped => 1,
            self::InTransit => 2,
            self::OutForDelivery => 3,
            self::Delivered => 4,
            self::Exception, self::Voided, self::Failed => -1,
        };
    }
}
