<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Video */
class VideoResource extends JsonResource
{
    /** @return array<array-key, mixed> */
    public function toArray(Request $r): array
    {
        return ['id' => $this->id, 'youtubeId' => $this->youtube_id, 'title' => $this->title ?? $this->provider_title, 'description' => $this->description ?? $this->provider_description, 'duration' => gmdate($this->duration_seconds >= 3600 ? 'H:i:s' : 'i:s', $this->duration_seconds), 'durationSeconds' => $this->duration_seconds, 'date' => $this->provider_published_at?->toISOString(), 'thumbnailUrl' => $this->thumbnail_url, 'url' => 'https://www.youtube.com/watch?v='.$this->youtube_id, 'embedUrl' => $this->embeddable ? 'https://www.youtube-nocookie.com/embed/'.$this->youtube_id : null, 'edition' => $this->edition, 'format' => $this->format, 'featured' => $this->featured];
    }
}
