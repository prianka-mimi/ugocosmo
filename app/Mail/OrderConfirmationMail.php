<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Order $order;

    public string $recipientName;

    public function __construct(Order $order, string $recipientName)
    {
        $this->order = $order;
        $this->recipientName = $recipientName;
    }

    public function envelope(): Envelope
    {
        $orderRef = $this->order->tracking_number ?: ('ORD-' . $this->order->id);

        return new Envelope(subject: "🛒 Order Confirmed #{$orderRef} — UgoCosmo");
    }

    public function content(): Content
    {
        $this->order->loadMissing(['items.product', 'seller', 'transaction']);

        return new Content(
            view: 'emails.order.confirmation',
            with: [
                'customerName' => $this->recipientName,
                'order' => $this->order,
                'items' => $this->order->items,
                'total' => (float) $this->order->total,
                'subtotal' => (float) $this->order->subtotal,
                'shippingAmount' => (float) ($this->order->shipping_amount ?? 0.0),
                'orderRef' => $this->order->tracking_number ?: ('ORD-' . $this->order->id),
                'shippingAddress' => $this->order->shipping_address,
                'shopUrl' => config('app.url') . '/shop',
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}

