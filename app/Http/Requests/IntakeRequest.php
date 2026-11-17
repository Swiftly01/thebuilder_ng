<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IntakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<array-key, mixed> */
    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'nominate' => ['type' => 'required|in:Builder,Startup', 'name' => 'required|string|max:180', 'industry' => 'required|string|max:120', 'country' => 'required|string|max:100', 'city' => 'nullable|string|max:100', 'website' => 'nullable|url:http,https|max:255', 'reason' => 'required|string|min:40|max:1000', 'links' => 'nullable|string|max:2000', 'nomineeEmail' => 'nullable|email:rfc|max:255', 'nomineePhone' => 'nullable|string|max:40', 'submitterName' => 'required|string|max:180', 'submitterEmail' => 'required|email:rfc|max:255', 'relationship' => 'required|string|max:180', 'consent' => 'required|accepted'],'submit' => ['title' => 'required|string|max:180', 'pitch' => 'required|string|min:40|max:4000', 'whyNow' => 'nullable|string|max:2000', 'links' => 'nullable|string|max:2000'],'subscribe' => ['email' => 'required|email:rfc|max:255', 'edition' => 'sometimes|in:Nigeria,Australia'],'confirm','unsubscribe' => ['token' => 'required|string|size:64'],default => []
        };
    }
}
