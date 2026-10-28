<?php

namespace App\Http\Requests;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentIntentRequest extends FormRequest
{
    protected ?Order $orderInstance = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        $orderId = $this->input('order_id');
        if (! $orderId) {
            return true; // Let validation rules handle presence
        }

        $profile = $user->profile;
        if (! $profile) {
            return false;
        }

        $order = Order::find($orderId);
        if (! $order) {
            return true; // Let validation rules handle existence
        }

        // Verify the order belongs to the authenticated user's profile
        if ((int) $order->buyer_id !== (int) $profile->id) {
            return false;
        }

        $this->orderInstance = $order;

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'order_id' => [
                'required',
                'integer',
                Rule::exists('orders', 'id'),
                function ($attribute, $value, $fail) {
                    $order = $this->orderInstance ?? Order::find($value);
                    if (! $order) {
                        return;
                    }

                    // Verify the order status is not already paid or processed
                    if (in_array(strtolower($order->status), ['processing', 'shipped', 'delivered', 'completed', 'refunded'])) {
                        $fail('The order is already paid or processed.');

                        return;
                    }

                    // Verify the order is not cancelled
                    if (strtolower($order->status) === 'cancelled') {
                        $fail('The order has been cancelled and cannot be paid.');

                        return;
                    }

                    // Prevent duplicate payment if a successful payment record exists
                    $existingSuccessfulPayment = Payment::where('order_id', $order->id)
                        ->where('payment_status', 'succeeded')
                        ->first();
                    if ($existingSuccessfulPayment) {
                        $fail('This order has already been successfully paid.');

                        return;
                    }
                },
            ],
        ];
    }

    /**
     * Get the resolved order instance.
     */
    public function getOrder(): ?Order
    {
        if (! $this->orderInstance && $this->input('order_id')) {
            $this->orderInstance = Order::find($this->input('order_id'));
        }

        return $this->orderInstance;
    }
}
