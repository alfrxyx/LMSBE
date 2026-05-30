<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['sometimes', 'required', 'email', 'unique:users,email,' . $this->user()->id],
            'phone' => ['sometimes', 'required', 'string', 'max:20'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:150'],
            'avatar' => ['sometimes', 'nullable', 'image', 'max:2048'], // Max 2MB
            'current_password' => ['required_with:password', 'string'],
            'password' => ['sometimes', 'nullable', 'confirmed', Password::defaults()],
        ];
    }
}
