<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<array-key, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        $document = ['bodyDocument' => 'sometimes|array:type,content', 'bodyDocument.type' => 'required_with:bodyDocument|in:doc', 'bodyDocument.content' => 'present_with:bodyDocument|array'];

        return $document + ['title' => $required.'|string|max:180', 'slug' => 'sometimes|string|alpha_dash|max:180', 'type' => 'sometimes|in:article,founder_story,interview,opinion,analysis,business,diaspora,sponsored,press_release', 'summary' => 'nullable|string|max:1000', 'body' => 'nullable|string|max:100000', 'authorId' => ['sometimes', Rule::exists('users', 'id')], 'categoryId' => ['nullable', Rule::exists('categories', 'id')], 'mediaId' => ['nullable', Rule::exists('media', 'id')], 'edition' => 'sometimes|in:Nigeria,Australia,both', 'industry' => 'nullable|string|max:120', 'country' => 'nullable|string|max:100', 'region' => 'nullable|string|max:100', 'isSponsored' => 'sometimes|boolean', 'sponsorName' => 'nullable|string|max:180', 'tags' => 'sometimes|array|max:20', 'tags.*' => 'string|distinct|exists:tags,id', 'builders' => 'sometimes|array|max:20', 'builders.*' => 'string|distinct|exists:builders,id', 'startups' => 'sometimes|array|max:20', 'startups.*' => 'string|distinct|exists:startups,id', 'seo' => 'nullable|array:title,description,canonical', 'seo.title' => 'nullable|string|max:180', 'seo.description' => 'nullable|string|max:300', 'seo.canonical' => 'nullable|url:http,https|max:255'];
    }
}
