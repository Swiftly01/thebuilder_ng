<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Administration\Audit;
use App\Domain\Administration\SiteMedia;
use App\Http\Controllers\Controller;
use App\Http\Requests\SiteSettingsRequest;
use App\Http\Resources\UserResource;
use App\Models\Article;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\NewsletterSubscription;
use App\Models\Nomination;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

final class AdministrationController extends Controller
{
    public function dashboard(): Response
    {
        return response()->json(['data' => ['users' => User::count(), 'newUsers' => User::where('created_at', '>=', now()->subDays(30))->count(), 'published' => Article::published()->count(), 'review' => Article::where('status', 'review')->count(), 'nominations' => Nomination::whereIn('status', ['received', 'review'])->count(), 'subscribers' => NewsletterSubscription::where('status', 'confirmed')->count(), 'activity' => AuditEvent::latest('created_at')->limit(10)->get()]]);
    }

    public function users(Request $r): Response
    {
        $r->validate(['q' => 'nullable|string|max:200', 'per_page' => 'nullable|integer|min:1|max:50']);

        return response()->json(User::with('roles')->when($r->q, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$r->q.'%')->orWhere('email', 'like', '%'.$r->q.'%')))->latest()->paginate((int) $r->input('per_page', 20)));
    }

    public function updateUser(Request $r, User $user, Audit $audit): Response
    {
        $d = $r->validate(['name' => 'sometimes|required|string|max:180', 'status' => 'sometimes|in:active,suspended,deactivated', 'roles' => 'sometimes|array|min:1|max:10', 'roles.*' => 'string|distinct|exists:roles,name']);
        DB::transaction(function () use ($r, $user, $d, $audit): void {
            $admins = User::role('admin')->where('status', 'active')->lockForUpdate()->get();
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $losesAdmin = (isset($d['roles']) && ! in_array('admin', $d['roles'], true)) || (($d['status'] ?? 'active') !== 'active');
            if ($user->hasRole('admin') && $losesAdmin && ($user->id === $r->user()->id || $admins->count() <= 1)) {
                throw ValidationException::withMessages(['roles' => ['Keep an active administrator and do not remove your own administrator access.']]);
            }
            if (isset($d['roles'])) {
                $user->syncRoles($d['roles']);
                unset($d['roles']);
            }
            if (isset($d['status']) && $d['status'] !== 'active') {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            $user->forceFill($d)->save();
            $audit->record('user.updated', $r->user(), $user, ['status' => $user->status, 'roles' => $user->getRoleNames()->all()]);
        });

        return response()->json(['data' => new UserResource($user->refresh())]);
    }

    public function roles(): Response
    {
        return response()->json(['data' => Role::with('permissions')->get()]);
    }

    public function audit(Request $r): Response
    {
        $r->validate(['per_page' => 'nullable|integer|min:1|max:50']);

        return response()->json(AuditEvent::latest('created_at')->paginate((int) $r->input('per_page', 25)));
    }

    public function homepage(): Response
    {
        return response()->json(['data' => DB::table('homepage_sections')->where('key', '!=', 'picks')->orderBy('position')->get()->map(fn ($s) => ['key' => $s->key, 'position' => $s->position, 'visible' => (bool) $s->visible, 'pinnedIds' => json_decode($s->pinned_ids ?? '[]', true)])]);
    }

    public function saveHomepage(Request $r, Audit $audit): Response
    {
        $d = $r->validate(['sections' => 'required|array|min:1|max:10', 'sections.*' => 'required|array:key,visible,pinnedIds', 'sections.*.key' => 'required|distinct|in:lead,latest,trending,startups,diaspora,videos,opportunities,nominate,most-read,newsletter', 'sections.*.visible' => 'required|boolean', 'sections.*.pinnedIds' => 'sometimes|array|max:20', 'sections.*.pinnedIds.*' => 'string|size:26']);
        DB::transaction(function () use ($r, $d, $audit): void {
            foreach ($d['sections'] as $i => $s) {
                DB::table('homepage_sections')->updateOrInsert(['key' => $s['key']], ['position' => $i, 'visible' => $s['visible'], 'pinned_ids' => json_encode($s['pinnedIds'] ?? []), 'created_at' => now(), 'updated_at' => now()]);
            }
            $audit->record('homepage.updated', $r->user());
        });

        return $this->homepage();
    }

    public function taxonomy(): Response
    {
        return response()->json(['data' => ['categories' => Category::orderBy('name')->get(), 'tags' => Tag::orderBy('name')->get()]]);
    }

    public function saveTaxonomy(Request $r, string $kind, Audit $audit): Response
    {
        abort_unless(in_array($kind, ['categories', 'tags'], true), 404);
        $d = $r->validate(['id' => 'nullable|string', 'name' => 'required|string|max:120', 'slug' => 'required|string|alpha_dash|max:120', 'parent_id' => 'nullable|string|exists:categories,id', 'description' => 'nullable|string|max:2000']);
        $class = $kind === 'categories' ? Category::class : Tag::class;
        $id = $d['id'] ?? null;
        unset($d['id']);
        $item = $id ? $class::findOrFail($id) : new $class;
        if ($class::where('slug', $d['slug'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
            throw ValidationException::withMessages(['slug' => ['This slug already exists.']]);
        }
        if ($id && ($d['parent_id'] ?? null) === $id) {
            throw ValidationException::withMessages(['parent_id' => ['A category cannot be its own parent.']]);
        }
        if ($kind === 'categories' && ! empty($d['parent_id'])) {
            $parent = Category::find($d['parent_id']);
            $seen = [];
            while ($parent) {
                if ($parent->id === $id || in_array($parent->id, $seen, true)) {
                    throw ValidationException::withMessages(['parent_id' => ['Category hierarchy cannot contain a cycle.']]);
                }
                $seen[] = $parent->id;
                $parent = $parent->parent_id ? Category::find($parent->parent_id) : null;
            }
        }
        $item->fill($d)->save();
        $audit->record('taxonomy.saved', $r->user(), $item);

        return response()->json(['data' => $item]);
    }

    public function settings(): Response
    {
        return response()->json(['data' => SiteMedia::resolve(DB::table('site_settings')->get()->mapWithKeys(fn ($s) => [$s->key => json_decode($s->value, true)])->all())]);
    }

    public function saveSettings(SiteSettingsRequest $r, Audit $audit): Response
    {
        $d = $r->validated();
        DB::transaction(function () use ($r, $d, $audit): void {
            foreach ($d as $key => $value) {
                DB::table('site_settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'public' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
            $audit->record('settings.updated', $r->user());
        });

        return $this->settings();
    }
}
