<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\SafeNavigationUrl;
use Illuminate\Foundation\Http\FormRequest;

final class SiteSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<array-key,mixed> */
    public function rules(): array
    {
        return [
            'siteName' => 'sometimes|required|string|max:180',
            'pageImages' => 'sometimes|array:about,partner,diaspora',
            'pageImages.*' => 'nullable|string|exists:media,id',
            'description' => 'sometimes|nullable|string|max:500',
            'contactEmail' => 'sometimes|nullable|email:rfc|max:255',
            'footer' => 'sometimes|nullable|string|max:2000',
            'footerNigeria' => 'sometimes|nullable|string|max:2000',
            'footerAustralia' => 'sometimes|nullable|string|max:2000',
            'socialLinks' => 'sometimes|array:linkedin,x,instagram,youtube,facebook',
            'socialLinks.*' => ['nullable', 'url:http,https', 'max:255', new SafeNavigationUrl],
            'menus' => 'sometimes|nullable|array|min:1|max:20',
            'menus.*' => 'array:label,url',
            'menus.*.label' => 'required|string|max:80|distinct',
            'menus.*.url' => ['required', 'string', 'max:255', 'distinct', new SafeNavigationUrl],
        ];
    }
}
