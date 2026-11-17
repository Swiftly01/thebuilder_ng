<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DirectoryWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<array-key, mixed> */
    public function rules(): array
    {
        $kind = $this->route('kind');
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $rules = ['name' => $required.'|string|max:180', 'slug' => 'sometimes|string|alpha_dash|max:180', 'industry' => 'nullable|string|max:120', 'country' => 'nullable|string|max:100', 'city' => 'nullable|string|max:100', 'region' => 'nullable|string|max:100', 'website' => 'nullable|url:http,https|max:255', 'mediaId' => 'nullable|string|exists:media,id', 'status' => 'sometimes|in:draft,published,archived'];

        return $rules + ($kind === 'builders' ? ['isDiaspora' => 'sometimes|boolean', 'classification' => $required.'|in:Startup Founder,Entrepreneur,Professional,Investor,Community Leader,Innovator,Academic,Creative,Diaspora Professional', 'headline' => 'nullable|string|max:240', 'biography' => 'nullable|string|max:10000', 'profession' => 'nullable|string|max:150', 'organisation' => 'nullable|string|max:180', 'achievements' => 'nullable|array|max:20', 'achievements.*' => 'string|max:1000', 'socialLinks' => 'nullable|array', 'socialLinks.*' => 'url:http,https|max:255', 'consentAt' => 'nullable|date', 'consentMethod' => 'nullable|string|max:200', 'feature' => 'nullable|in:featured,month,year', 'featureFrom' => 'nullable|date', 'featureUntil' => 'nullable|date|after_or_equal:featureFrom'] : ['oneLiner' => 'nullable|string|max:255', 'description' => 'nullable|string|max:10000', 'stage' => 'sometimes|in:Idea,Pre-seed,Seed,Series A+,Growth', 'fundingStage' => 'nullable|string|max:120', 'foundedYear' => 'nullable|integer|min:1800|max:2100', 'teamSize' => 'nullable|integer|min:1|max:1000000', 'founders' => 'sometimes|array|max:50', 'founders.*' => 'string|distinct|exists:builders,id']);
    }
}
