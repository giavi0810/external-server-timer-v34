<?php

namespace App\Http\Requests;

use App\Models\TicketEvent;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class BatchFreshdeskWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1', 'max:100'],
            'events.*' => ['required', 'array'],
            'events.*.recovery_id' => ['nullable', 'string', 'max:255'],
            'events.*.ticket_id' => ['required', 'integer', 'min:1'],
            'events.*.event_type' => ['required', 'string', Rule::in(TicketEvent::SUPPORTED_EVENT_TYPES)],
            'events.*.event_timestamp' => ['required', 'date'],
            'events.*.ticket_data' => ['nullable', 'array'],
            'events.*.changes' => ['nullable', 'array'],
            'events.*.conversation_data' => ['nullable', 'array'],
            'events.*.raw_payload' => ['nullable', 'array'],
            'events.*.raw_payload.ticket' => ['nullable', 'array'],
            'events.*.ticket' => ['nullable', 'array'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Dữ liệu batch không hợp lệ.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
