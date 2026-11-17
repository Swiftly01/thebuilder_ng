<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class DirectoryResource extends JsonResource
{
    /** @return array<array-key, mixed> */
    public function toArray(Request $r): array
    {
        $x = $this->resource;
        $image = $x->media;
        $base = ['id' => $x->id, 'slug' => $x->slug, 'name' => $x->name, 'industry' => $x->industry ?? '', 'country' => $x->country ?? '', 'region' => $x->region, 'flag' => match (strtolower($x->country ?? '')) {
            'nigeria' => '🇳🇬','australia' => '🇦🇺','ghana' => '🇬🇭','kenya' => '🇰🇪',default => ''
        }, 'website' => $x->website, 'imageUrl' => $image ? Storage::disk('public')->url($image->path) : null];
        $base['imageAlt'] = $image?->alt;
        $base['imageCredit'] = $image?->credit;
        if ($x instanceof Builder) {
            return $base + ['classification' => $x->classification, 'headline' => $x->headline ?? '', 'location' => implode(', ', array_filter([$x->city, $x->country])), 'bio' => $x->biography, 'profession' => $x->profession, 'organisation' => $x->organisation, 'achievements' => $x->achievements ?? [], 'socialLinks' => $x->social_links ?? [], 'feature' => $x->feature, 'articles' => ArticleResource::collection($x->articles()->published()->with(['author', 'category', 'media', 'tags'])->get())];
        }

        return $base + ['oneLiner' => $x->one_liner ?? '', 'description' => $x->description, 'hq' => implode(', ', array_filter([$x->city, $x->country])), 'stage' => $x->stage ?? 'Idea', 'fundingStage' => $x->funding_stage, 'foundedYear' => $x->founded_year, 'teamSize' => $x->team_size, 'founders' => self::collection($x->founders()->where('status', 'published')->whereNotNull('consent_at')->get()), 'articles' => ArticleResource::collection($x->articles()->published()->with(['author', 'category', 'media', 'tags'])->get())];
    }
}
