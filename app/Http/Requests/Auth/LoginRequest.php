<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'outlet_id' => ['nullable', 'integer', 'exists:outlets,id'],
            'pos_terminal_id' => ['nullable', 'integer', 'exists:pos_terminals,id'],
            'device_name' => ['nullable', 'string', 'max:128'],
        ];
    }
}

