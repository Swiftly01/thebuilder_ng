<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Opportunity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Opportunity */
class OpportunityResource extends JsonResource
{
    /** @return array<array-key, mixed> */
    public function toArray(Request $r): array
    {
        $days = $this->deadline ? (int) today()->diffInDays($this->deadline, false) : null;

        return ['id' => $this->id, 'slug' => $this->slug, 'title' => $this->title, 'organisation' => $this->organisation, 'type' => $this->type, 'location' => $this->location, 'region' => $this->region, 'remote' => $this->remote, 'description' => $this->description, 'eligibility' => $this->eligibility, 'applyUrl' => $this->apply_url, 'deadline' => $this->deadline?->toDateString(), 'daysLeft' => $days, 'isUrgent' => $days !== null && $days >= 0 && $days <= 7, 'edition' => $this->edition];
    }
}
