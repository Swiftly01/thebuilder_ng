<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class IdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }

    /** @return array<array-key, mixed> */
    public function rules(): array
    {
        $password = ['required', 'string', Password::min(8)->mixedCase()->numbers()];

        return match ($this->route()->getActionMethod()) {
            'register' => ['name' => 'required|string|min:2|max:180', 'email' => 'required|email:rfc|max:255|unique:users,email', 'password' => $password, 'country' => 'nullable|string|max:100', 'city' => 'nullable|string|max:100', 'profession' => 'nullable|string|max:150'],
            'login' => ['email' => 'required|email:rfc|max:255', 'password' => 'required|string|max:1000'],
            'forgotPassword','resend' => ['email' => 'required|email:rfc|max:255'],
            'resetPassword' => ['email' => 'required|email:rfc', 'token' => 'required|string', 'password' => [...$password, 'confirmed']],
            'updateProfile' => ['name' => 'sometimes|required|string|max:180', 'country' => 'nullable|string|max:100', 'city' => 'nullable|string|max:100', 'profession' => 'nullable|string|max:150', 'organisation' => 'nullable|string|max:180', 'industry' => 'nullable|string|max:120', 'website' => 'nullable|url:http,https|max:255', 'biography' => 'nullable|string|max:2000', 'social_links' => 'nullable|array', 'social_links.*' => 'nullable|url:http,https|max:255', 'privacy' => 'nullable|array', 'privacy.*' => 'in:public,members,private'],
            'onboarding','interests' => ['interests' => 'required|array|min:3|max:15', 'interests.*' => 'required|string|distinct|exists:categories,slug', 'country' => 'nullable|string|max:100', 'city' => 'nullable|string|max:100', 'profession' => 'nullable|string|max:150'],
            'changePassword' => ['currentPassword' => 'required|string', 'password' => [...$password, 'confirmed']],
            'deleteAccount' => ['password' => 'required|string'],default => []
        };
    }
}
