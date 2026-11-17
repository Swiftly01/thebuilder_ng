<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class OpportunityWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:180', 'slug' => 'sometimes|string|alpha_dash|max:180',
            'organisation' => 'required|string|max:180', 'type' => 'required|in:job,grant,fellowship,accelerator',
            'location' => 'required|string|max:180', 'region' => 'nullable|string|max:100',
            'remote' => 'sometimes|boolean', 'description' => 'required|string|max:10000',
            'eligibility' => 'nullable|string|max:10000', 'applyUrl' => 'required|url:http,https|max:255',
            'deadline' => 'nullable|date', 'edition' => 'sometimes|in:Nigeria,Australia,both',
            'status' => 'sometimes|in:draft,published,archived',
        ];
    }
}
