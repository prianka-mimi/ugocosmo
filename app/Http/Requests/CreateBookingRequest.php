<?php

namespace App\Http\Requests;

use App\Models\Service;
use App\Models\ServicePackage;
use Illuminate\Foundation\Http\FormRequest;

class CreateBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'booking_date' => ['nullable', 'date', 'after_or_equal:today'],
            'booking_time' => ['nullable', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'package_id' => ['nullable', 'integer', 'exists:service_packages,id'],
            'duration' => ['nullable'],
            'delivery_location' => ['nullable', 'string', 'in:At my location (customer comes to me),At customer\'s address (I go to them),Both options available'],
            'customer_address' => ['nullable', 'string', 'max:1000'],
            'images' => ['nullable', 'array', 'max:5'],
            'images.*' => ['image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $serviceId = $this->input('service_id');
            if (! $serviceId) {
                return;
            }

            $service = Service::find($serviceId);
            if (! $service) {
                return;
            }

            // 0. booking_date and booking_time are required for non-online services
            if ($service->service_type !== 'online') {
                if (! $this->filled('booking_date')) {
                    $validator->errors()->add('booking_date', 'The booking date field is required.');
                }
                if (! $this->filled('booking_time')) {
                    $validator->errors()->add('booking_time', 'The booking time field is required.');
                }
            }

            // 1. Hourly service requires duration
            if ($service->price_type === 'hourly' && ! $this->filled('duration')) {
                $validator->errors()->add('duration', 'Duration is required for hourly services.');
            }

            // 2. Package must belong to the correct service
            if ($this->filled('package_id') && ! is_array($this->input('package_id'))) {
                $package = ServicePackage::find($this->input('package_id'));
                if (! $package || (int) $package->service_id !== (int) $service->id) {
                    $validator->errors()->add('package_id', 'The selected package does not belong to this service.');
                }
            }

            // 3. Location specific validations
            if ($service->service_type !== 'online') {
                $resolvedLocation = null;

                if ($service->delivery_location === 'Both options available') {
                    if (! $this->filled('delivery_location')) {
                        $validator->errors()->add('delivery_location', 'Delivery location is required for this service.');
                    } else {
                        $resolvedLocation = $this->input('delivery_location');
                    }
                } else {
                    $resolvedLocation = $service->delivery_location;
                }

                if ($resolvedLocation === 'At customer\'s address (I go to them)') {
                    if (! $this->filled('customer_address')) {
                        $validator->errors()->add('customer_address', 'Your address is required when booking this service.');
                    }
                }
            }
        });
    }
}
