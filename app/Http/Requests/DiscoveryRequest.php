<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DiscoveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<array-key, mixed> */
    public function rules(): array
    {
        return ['format' => 'nullable|in:interview,podcast,documentary', 'feature' => 'nullable|in:featured,month,year', 'deadlineDays' => 'nullable|integer|min:1|max:365', 'remote' => 'nullable|boolean', 'diaspora' => 'nullable|boolean', 'sort' => 'nullable|in:latest,most_read', 'q' => 'nullable|string|max:200', 'page' => 'nullable|integer|min:1|max:10000', 'per_page' => 'nullable|integer|min:1|max:50', 'category' => 'nullable|string|max:100', 'tag' => 'nullable|string|max:100', 'type' => 'nullable|string|max:40', 'edition' => 'nullable|in:Nigeria,Australia,both', 'industry' => 'nullable|string|max:120', 'country' => 'nullable|string|max:100', 'region' => 'nullable|string|max:100', 'classification' => 'nullable|string|max:64', 'stage' => 'nullable|string|max:40', 'author' => 'nullable|string|max:26', 'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from'];
    }
}
