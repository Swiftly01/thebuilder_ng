<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use App\Domain\Editorial\RichText;
use App\Models\Article;
use App\Models\ArticleRevision;
use App\Models\Builder;
use App\Models\Category;
use App\Models\NewsletterSubscription;
use App\Models\Startup;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Import a reviewed, normalized snapshot, never the legacy schema or executable SQL. */
final class SnapshotImport
{
    public function __construct(private readonly RichText $richText) {}

    /** @param array<string,mixed> $snapshot
     * @return array<string,int> */
    public function run(array $snapshot, bool $apply = false): array
    {
        abort_if(app()->environment('production'), 403, 'Import a reviewed snapshot into an isolated staging database first.');
        Validator::make($snapshot, ['version' => 'required|in:1', 'source' => 'required|string|alpha_dash|max:40', 'records' => 'required|array|max:10000', 'records.*' => 'required|array:entity,sourceId,data', 'records.*.sourceId' => 'required|string|max:80', 'records.*.entity' => 'required|in:users,categories,tags,builders,startups,articles,newsletter,bookmarks', 'records.*.data' => 'required|array'])->validate();
        $counts = ['created' => 0, 'replayed' => 0];
        DB::beginTransaction();
        try {
            foreach ($snapshot['records'] as $record) {
                $source = $snapshot['source'].':'.$record['entity'];
                $checksum = hash('sha256', json_encode($record, JSON_THROW_ON_ERROR));
                $mapped = DB::table('legacy_import_map')->where('source', $source)->where('source_id', $record['sourceId'])->lockForUpdate()->first();
                if ($mapped) {
                    if (! hash_equals($mapped->checksum, $checksum)) {
                        throw ValidationException::withMessages(['records' => ['A mapped source record changed. Reconcile it explicitly before reimporting.']]);
                    }
                    $counts['replayed']++;

                    continue;
                }
                $id = $this->record($snapshot['source'], $record['entity'], $record['data']);
                DB::table('legacy_import_map')->insert(['source' => $source, 'source_id' => $record['sourceId'], 'target_id' => $id, 'checksum' => $checksum, 'created_at' => now(), 'updated_at' => now()]);
                $counts['created']++;
            }
            if ($apply) {
                DB::commit();
            } else {
                DB::rollBack();
            }

            return $counts;
        } catch (\Throwable $error) {
            DB::rollBack();
            throw $error;
        }
    }

    private function mapped(string $source, string $entity, ?string $id): ?string
    {
        if ($id === null) {
            return null;
        }
        $mapped = DB::table('legacy_import_map')->where('source', $source.':'.$entity)->where('source_id', $id)->value('target_id');
        if (! $mapped) {
            throw ValidationException::withMessages(['records' => ['A referenced source record is absent; order records by dependency.']]);
        }

        return (string) $mapped;
    }

    /** @param array<string,mixed> $data */
    private function record(string $source, string $entity, array $data): string
    {
        if ($entity === 'users') {
            $data = Validator::make($data, ['name' => 'required|string|max:180', 'email' => 'required|email:rfc|max:255|unique:users,email', 'passwordHash' => 'nullable|string|max:255', 'verifiedAt' => 'nullable|date', 'profile' => 'nullable|array:country,city,profession,biography,interests', 'profile.country' => 'nullable|string|max:100', 'profile.city' => 'nullable|string|max:100', 'profile.profession' => 'nullable|string|max:150', 'profile.biography' => 'nullable|string|max:2000', 'profile.interests' => 'nullable|array|max:15', 'profile.interests.*' => 'string|exists:categories,slug'])->validate();
            $hash = $data['passwordHash'] ?? null;
            if ($hash !== null && ! in_array(password_get_info($hash)['algoName'], ['bcrypt'], true)) {
                throw ValidationException::withMessages(['passwordHash' => ['Only bcrypt hashes matching the configured password driver can be imported.']]);
            }
            $user = new User;
            $user->name = $data['name'];
            $user->email = strtolower(trim($data['email']));
            if ($hash) {
                $user->setRawAttributes($user->getAttributes() + ['password' => $hash]);
            } else {
                $user->password = Str::random(64);
            }
            $user->email_verified_at = $data['verifiedAt'] ?? null;
            $user->save();
            $user->assignRole('registered_user');
            $user->profile()->create($data['profile'] ?? []);

            return $user->id;
        }
        if (in_array($entity, ['categories', 'tags'], true)) {
            $table = $entity;
            $data = Validator::make($data, ['name' => 'required|string|max:120', 'slug' => 'required|alpha_dash|max:120', 'description' => 'nullable|string|max:2000', 'parentSourceId' => 'nullable|string|max:80'])->validate();
            $parent = $this->mapped($source, 'categories', $data['parentSourceId'] ?? null);
            $model = $entity === 'categories' ? Category::firstOrNew(['slug' => $data['slug']]) : Tag::firstOrNew(['slug' => $data['slug']]);
            if ($model->exists) {
                if ($model->name !== $data['name']) {
                    throw ValidationException::withMessages(['slug' => ['A canonical taxonomy record conflicts with the imported name.']]);
                }

                return $model->id;
            }
            $model->fill(['name' => $data['name'], 'slug' => $data['slug']]);
            if ($model instanceof Category) {
                $model->fill(['description' => $data['description'] ?? null, 'parent_id' => $parent]);
            }
            $model->save();

            return $model->id;
        }
        if (in_array($entity, ['builders', 'startups'], true)) {
            $rules = ['name' => 'required|string|max:180', 'slug' => 'required|alpha_dash|max:180|unique:'.$entity.',slug', 'status' => 'required|in:draft,published,archived', 'country' => 'nullable|string|max:100', 'city' => 'nullable|string|max:100', 'region' => 'nullable|string|max:100', 'industry' => 'nullable|string|max:120', 'website' => 'nullable|url:http,https|max:255'];
            $rules += $entity === 'builders' ? ['classification' => 'required|in:Startup Founder,Entrepreneur,Professional,Investor,Community Leader,Innovator,Academic,Creative,Diaspora Professional', 'headline' => 'nullable|string|max:240', 'biography' => 'nullable|string|max:10000', 'consent_at' => 'nullable|date', 'consent_method' => 'nullable|string|max:200'] : ['one_liner' => 'nullable|string|max:255', 'description' => 'nullable|string|max:10000', 'stage' => 'nullable|in:Idea,Pre-seed,Seed,Series A+,Growth', 'founded_year' => 'nullable|integer|min:1800|max:2100', 'founderSourceIds' => 'nullable|array|max:50', 'founderSourceIds.*' => 'string|max:80'];
            $data = Validator::make($data, $rules)->validate();
            if ($entity === 'builders' && $data['status'] === 'published' && (empty($data['consent_at']) || empty($data['consent_method']))) {
                throw ValidationException::withMessages(['consent_at' => ['Published imported Builders require verified consent evidence.']]);
            }
            $founders = $data['founderSourceIds'] ?? [];
            unset($data['founderSourceIds']);
            $item = $entity === 'builders' ? new Builder : new Startup;
            $status = $data['status'];
            unset($data['status']);
            $item->fill($data);
            $item->status = $status;
            $item->save();
            if ($item instanceof Startup) {
                $item->founders()->sync(array_map(fn ($id) => $this->mapped($source, 'builders', $id), $founders));
            }

            return $item->id;
        }
        if ($entity === 'articles') {
            $data = Validator::make($data, ['title' => 'required|string|max:180', 'slug' => 'required|alpha_dash|max:180|unique:articles,slug', 'summary' => 'required|string|max:1000', 'body' => 'required|string|max:100000', 'authorSourceId' => 'required|string|max:80', 'categorySourceId' => 'nullable|string|max:80', 'status' => 'required|in:draft,published,archived', 'publishedAt' => 'nullable|date|before_or_equal:now', 'edition' => 'required|in:Nigeria,Australia,both', 'tagSourceIds' => 'nullable|array|max:20', 'tagSourceIds.*' => 'string|max:80', 'builderSourceIds' => 'nullable|array|max:20', 'builderSourceIds.*' => 'string|max:80', 'startupSourceIds' => 'nullable|array|max:20', 'startupSourceIds.*' => 'string|max:80'])->validate();
            if ($data['status'] === 'published' && empty($data['publishedAt'])) {
                throw ValidationException::withMessages(['publishedAt' => ['A published source article requires its original publication date.']]);
            }
            $item = new Article;
            $item->fill(['title' => $data['title'], 'slug' => $data['slug'], 'summary' => $data['summary'], 'body' => $this->richText->sanitize($data['body']), 'author_id' => $this->mapped($source, 'users', $data['authorSourceId']), 'category_id' => $this->mapped($source, 'categories', $data['categorySourceId'] ?? null), 'edition' => $data['edition']]);
            $item->status = $data['status'];
            $item->published_at = $data['publishedAt'] ?? null;
            $item->save();
            foreach (['tags' => 'tagSourceIds', 'builders' => 'builderSourceIds', 'startups' => 'startupSourceIds'] as $relation => $key) {
                $item->$relation()->sync(array_map(fn ($id) => $this->mapped($source, $relation, $id), $data[$key] ?? []));
            }
            ArticleRevision::create(['article_id' => $item->id, 'version' => 1, 'snapshot' => $item->only(['title', 'slug', 'summary', 'body', 'status', 'edition', 'category_id']), 'note' => 'Reviewed source snapshot import']);

            return $item->id;
        }
        if ($entity === 'newsletter') {
            $data = Validator::make($data, ['email' => 'required|email:rfc|unique:newsletter_subscriptions,email', 'edition' => 'required|in:Nigeria,Australia,both', 'consentAt' => 'required|date', 'confirmedAt' => 'nullable|date'])->validate();
            // Existing subscription consent does not activate sending until double opt-in is verified.
            $item = NewsletterSubscription::forceCreate(['email' => strtolower($data['email']), 'edition' => $data['edition'], 'consent_at' => $data['consentAt'], 'confirmed_at' => $data['confirmedAt'] ?? null, 'status' => isset($data['confirmedAt']) ? 'confirmed' : 'pending']);

            return $item->id;
        }
        $data = Validator::make($data, ['userSourceId' => 'required|string|max:80', 'articleSourceId' => 'required|string|max:80'])->validate();
        $userId = $this->mapped($source, 'users', $data['userSourceId']);
        $articleId = $this->mapped($source, 'articles', $data['articleSourceId']);
        DB::table('bookmarks')->insertOrIgnore(['user_id' => $userId, 'article_id' => $articleId, 'created_at' => now(), 'updated_at' => now()]);

        return (string) $articleId;
    }
}
