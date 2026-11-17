<?php

declare(strict_types=1);

namespace App\Domain\Discovery;

use App\Models\Article;
use App\Models\Builder;
use App\Models\Opportunity;
use App\Models\Startup;
use Illuminate\Database\Eloquent\Builder as Query;

final class Queries
{
    /** @param array<array-key, mixed> $f */
    /** @param array<string,mixed> $f
     * @return Query<Article> */
    public function articles(array $f): Query
    {
        $q = Article::with(['author', 'category', 'media', 'tags'])->published();
        if (! empty($f['category'])) {
            $q->whereHas('category', fn ($q) => $q->where('slug', $f['category']));
        }
        if (! empty($f['tag'])) {
            $q->whereHas('tags', fn ($q) => $q->where('slug', $f['tag']));
        }
        foreach (['type', 'industry', 'country', 'region'] as $k) {
            if (! empty($f[$k])) {
                $q->where($k, $f[$k]);
            }
        }
        if (! empty($f['author'])) {
            $q->where('author_id', $f['author']);
        }
        if (! empty($f['edition']) && $f['edition'] !== 'both') {
            $q->whereIn('edition', [$f['edition'], 'both']);
        }
        if (! empty($f['from'])) {
            $q->whereDate('published_at', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->whereDate('published_at', '<=', $f['to']);
        }

        return $this->search($q, $f['q'] ?? '', ['title', 'summary']);
    }

    /** @param array<array-key, mixed> $f */
    /** @param array<string,mixed> $f
     * @return Query<Builder|Startup> */
    public function directory(string $kind, array $f): Query
    {
        /** @var Query<Builder|Startup> $q */
        $q = ($kind === 'builders' ? Builder::query() : Startup::query())->with('media')->where('status', 'published');
        if ($kind === 'builders') {
            $q->whereNotNull('consent_at');
            if (isset($f['diaspora'])) {
                $q->where('is_diaspora', (bool) $f['diaspora']);
            }
            if (! empty($f['feature'])) {
                $q->where('feature', $f['feature'])
                    ->where(fn ($q) => $q->whereNull('feature_from')->orWhere('feature_from', '<=', now()))
                    ->where(fn ($q) => $q->whereNull('feature_until')->orWhere('feature_until', '>=', now()));
            }
        }
        foreach (['country', 'region', 'industry', ...($kind === 'builders' ? ['classification'] : ['stage'])] as $k) {
            if (! empty($f[$k])) {
                $q->where($k, $f[$k]);
            }
        }

        return $this->search($q, $f['q'] ?? '', ['name', $kind === 'builders' ? 'headline' : 'one_liner']);
    }

    /** @param array<array-key, mixed> $f */
    /** @param array<string,mixed> $f
     * @return Query<Opportunity> */
    public function opportunities(array $f): Query
    {
        $q = Opportunity::where('status', 'published')->where(fn ($q) => $q->whereNull('deadline')->orWhereDate('deadline', '>=', today()));
        if (isset($f['remote'])) {
            $q->where('remote', (bool) $f['remote']);
        }
        if (! empty($f['deadlineDays'])) {
            $q->whereNotNull('deadline')->whereDate('deadline', '<=', today()->addDays((int) $f['deadlineDays']));
        }
        if (! empty($f['type'])) {
            $q->where('type', strtolower($f['type']));
        }
        foreach (['region', 'location'] as $key) {
            if (! empty($f[$key])) {
                $q->where($key, $f[$key]);
            }
        }
        if (! empty($f['edition']) && $f['edition'] !== 'both') {
            $q->whereIn('edition', [$f['edition'], 'both']);
        }

        return $this->search($q, $f['q'] ?? '', ['title', 'organisation']);
    }

    /** @param array<array-key, mixed> $columns */
    /** @template T of \Illuminate\Database\Eloquent\Model
     * @param Query<T> $query
     * @param list<string> $columns
     * @return Query<T> */
    private function search(Query $query, string $term, array $columns): Query
    {
        $term = trim($term);
        if ($term === '') {
            return $query;
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return $query->where(function ($q) use ($columns, $like): void {
            foreach ($columns as $column) {
                $q->orWhere($column, 'like', $like);
            }
        });
    }
}
