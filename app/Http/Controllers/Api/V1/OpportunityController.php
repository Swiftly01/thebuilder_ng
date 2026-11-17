<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Administration\Audit;
use App\Http\Controllers\Controller;
use App\Http\Requests\OpportunityWriteRequest;
use App\Models\Opportunity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class OpportunityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => 'nullable|string|max:200', 'status' => 'nullable|in:draft,published,archived', 'per_page' => 'nullable|integer|min:1|max:50']);

        return response()->json(Opportunity::when($data['q'] ?? null, fn ($q, $term) => $q->where('title', 'like', '%'.$term.'%'))->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))->latest()->paginate((int) $request->input('per_page', 20)));
    }

    public function save(OpportunityWriteRequest $request, Audit $audit): JsonResponse
    {
        $data = $request->validated();
        $id = $request->route('opportunity');
        $item = DB::transaction(function () use ($request, $audit, $data, $id): Opportunity {
            $item = $id ? Opportunity::whereKey($id)->lockForUpdate()->firstOrFail() : new Opportunity;
            if (isset($data['slug']) && $item->exists && $item->status === 'published' && $item->slug !== $data['slug']) {
                throw ValidationException::withMessages(['slug' => ['Published opportunity URLs are permanent.']]);
            }
            $slug = $data['slug'] ?? $item->slug ?? Str::slug($data['title']).'-'.Str::lower(Str::random(6));
            if (Opportunity::where('slug', $slug)->when($item->exists, fn ($q) => $q->where('id', '!=', $item->id))->exists()) {
                throw ValidationException::withMessages(['slug' => ['This slug is already used.']]);
            }
            $data['apply_url'] = $data['applyUrl'];
            unset($data['applyUrl']);
            $item->fill($data + ['slug' => $slug])->save();
            $audit->record('opportunity.saved', $request->user(), $item, ['status' => $item->status]);

            return $item;
        });

        return response()->json(['data' => $item], $id ? 200 : 201);
    }

    public function destroy(Request $request, Opportunity $opportunity, Audit $audit): Response
    {
        $opportunity->delete();
        $audit->record('opportunity.deleted', $request->user(), $opportunity);

        return response()->noContent();
    }
}
