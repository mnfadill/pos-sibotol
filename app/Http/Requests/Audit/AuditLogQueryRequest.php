<?php

declare(strict_types=1);

namespace App\Http\Requests\Audit;

use App\Enums\AuditAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AuditLogQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('audit.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $validActions = array_map(static fn (AuditAction $a): string => $a->value, AuditAction::cases());

        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'actor_id' => ['nullable', 'integer'],
            'action_event' => ['nullable', 'string', Rule::in($validActions)],
            'action_events' => ['nullable', 'array'],
            'action_events.*' => ['string', Rule::in($validActions)],
            'entity_type' => ['nullable', 'string', 'max:64'],
            'entity_id' => ['nullable', 'string', 'max:64'],
            'outlet_id' => ['nullable', 'integer'],
            'pos_terminal_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:128'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}

