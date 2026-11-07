<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'listing_id'       => ['required', 'integer', 'exists:listings,id'],
            'quantity'         => ['required', 'integer', 'min:1'],
            'idempotency_key'  => ['required', 'string', 'max:255'],
        ];
    }
}
