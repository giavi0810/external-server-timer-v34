<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class FreshdeskWebhookRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_type' => 'required|string',
            'event_timestamp' => 'required|date',
            'ticket_id' => 'required|integer',
            'ticket_data' => 'nullable|array',
            'changes' => 'nullable|array',
            'conversation_data' => 'nullable|array',
            'raw_payload' => 'nullable|array',
            'raw_payload.ticket' => 'nullable|array',
            'ticket' => 'nullable|array',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        \Illuminate\Support\Facades\Log::warning("Webhook validation failed", [
            'errors' => $validator->errors(),
        ]);

        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422));
    }
}
