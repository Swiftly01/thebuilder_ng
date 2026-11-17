<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Administration\Audit;
use App\Http\Controllers\Controller;
use App\Http\Requests\DirectoryWriteRequest;
use App\Models\Builder;
use App\Models\Startup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class DirectoryController extends Controller
{
    private function model(string $kind): string
    {
        abort_unless(in_array($kind, ['builders', 'startups'], true), 404);

        return $kind === 'builders' ? Builder::class : Startup::class;
    }

    public function index(Request $r, string $kind): Response
    {
        $r->validate(['q' => 'nullable|string|max:200', 'per_page' => 'nullable|integer|min:1|max:50']);
        $class = $this->model($kind);

        return response()->json($class::with($kind === 'startups' ? ['founders'] : [])->when($r->q, fn ($q) => $q->where('name', 'like', '%'.$r->q.'%'))->latest()->paginate((int) $r->input('per_page', 20)));
    }

    public function show(string $kind, string $id): Response
    {
        $class = $this->model($kind);

        return response()->json(['data' => $class::findOrFail($id)]);
    }

    public function store(DirectoryWriteRequest $r, string $kind, Audit $audit): Response
    {
        return $this->save($r, $kind, null, $audit);
    }

    public function update(DirectoryWriteRequest $r, string $kind, string $id, Audit $audit): Response
    {
        return $this->save($r, $kind, $id, $audit);
    }

    private function save(DirectoryWriteRequest $r, string $kind, ?string $id, Audit $audit): Response
    {
        $class = $this->model($kind);
        $item = DB::transaction(function () use ($r, $kind, $id, $class, $audit) {
            $item = $id ? $class::whereKey($id)->lockForUpdate()->firstOrFail() : new $class;
            $d = $r->validated();
            foreach (['isDiaspora' => 'is_diaspora', 'mediaId' => 'media_id', 'socialLinks' => 'social_links', 'consentAt' => 'consent_at', 'consentMethod' => 'consent_method', 'featureFrom' => 'feature_from', 'featureUntil' => 'feature_until', 'oneLiner' => 'one_liner', 'fundingStage' => 'funding_stage', 'foundedYear' => 'founded_year', 'teamSize' => 'team_size'] as $a => $b) {
                if (array_key_exists($a, $d)) {
                    $d[$b] = $d[$a];
                    unset($d[$a]);
                }
            }
            $founders = $d['founders'] ?? null;
            unset($d['founders']);
            $status = $d['status'] ?? $item->status ?? 'draft';
            unset($d['status']);
            if (! $id && ! isset($d['slug'])) {
                $d['slug'] = Str::slug($d['name']).'-'.Str::lower(Str::random(5));
            }
            if ($item->exists && $item->status === 'published' && isset($d['slug']) && $d['slug'] !== $item->slug) {
                throw ValidationException::withMessages(['slug' => ['Published profile URLs are permanent.']]);
            }
            if (isset($d['slug']) && $class::where('slug', $d['slug'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                throw ValidationException::withMessages(['slug' => ['This profile slug is already used.']]);
            }
            $item->fill($d);
            if ($kind === 'builders' && $status === 'published' && (! $item->consent_at || blank($item->consent_method))) {
                throw ValidationException::withMessages(['consentAt' => ['Record consent and its method before publishing.']]);
            }
            $item->status = $status;
            $item->save();
            if ($founders !== null) {
                $item->founders()->sync($founders);
            }
            $audit->record('profile.saved', $r->user(), $item, ['status' => $status]);

            return $item;
        });

        return response()->json(['data' => $item], $id ? 200 : 201);
    }

    public function destroy(Request $r, string $kind, string $id, Audit $audit): Response
    {
        $class = $this->model($kind);
        $item = $class::findOrFail($id);
        $item->delete();
        $audit->record('profile.deleted', $r->user(), $item);

        return response()->noContent();
    }
}
