<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title'             => ['required', 'string', 'max:255'],
            'unit_price_minor'  => ['required', 'integer', 'min:1'],
            'currency'          => ['required', 'string', 'size:3'],
            'quantity'          => ['required', 'integer', 'min:1'],
        ];
    }
}
