<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use App\Models\Media;
use Illuminate\Support\Facades\Storage;

final class SiteMedia
{
    /** @param array<string,mixed> $settings
     * @return array<string,mixed> */
    public static function resolve(array $settings): array
    {
        $ids = $settings['pageImages'] ?? [];
        $items = Media::whereIn('id', array_filter(array_values($ids)))->get()->keyBy('id');
        $settings['pageMedia'] = [];
        foreach (['about', 'partner', 'diaspora'] as $slot) {
            $media = $items->get($ids[$slot] ?? '');
            $settings['pageMedia'][$slot] = $media ? ['id' => $media->id, 'url' => Storage::disk('public')->url($media->path), 'alt' => $media->alt, 'credit' => $media->credit] : null;
        }

        return $settings;
    }
}
