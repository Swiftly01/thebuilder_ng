<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Administration\Audit;
use App\Domain\Editorial\Workflow;
use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use App\Models\Nomination;
use App\Models\StorySubmission;
use App\Models\User;
use App\Notifications\TransactionalNotice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class ReviewController extends Controller
{
    public function draftNomination(Request $request, Nomination $nomination, Workflow $workflow): JsonResource
    {
        $article = DB::transaction(function () use ($request, $nomination, $workflow): Article {
            $record = Nomination::whereKey($nomination->id)->lockForUpdate()->firstOrFail();
            if ($record->article_id) {
                return Article::findOrFail($record->article_id);
            }
            abort_if($record->status === 'declined', 409, 'A declined nomination cannot create a draft.');
            $article = $workflow->save(null, ['title' => $record->name, 'summary' => $record->reason], $request->user(), null);
            $record->article_id = $article->id;
            $record->save();

            return $article;
        });

        return new ArticleResource($article);
    }

    private const MOVES = ['received' => ['review', 'declined'], 'review' => ['shortlisted', 'declined'], 'shortlisted' => ['contacted', 'declined'], 'contacted' => ['interview', 'development', 'declined'], 'interview' => ['development', 'declined'], 'development' => ['published', 'declined'], 'published' => [], 'declined' => []];

    public function nominations(Request $r): Response
    {
        $r->validate(['q' => 'nullable|string|max:200', 'status' => 'nullable|string|max:32', 'per_page' => 'nullable|integer|min:1|max:50']);

        return response()->json(Nomination::when($r->q, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$r->q.'%')->orWhere('reference', 'like', '%'.$r->q.'%')))->when($r->status, fn ($q) => $q->where('status', $r->status))->latest()->paginate((int) $r->input('per_page', 20)));
    }

    public function moveNomination(Request $r, Nomination $nomination, Audit $audit): Response
    {
        $d = $r->validate(['status' => 'required|in:review,shortlisted,contacted,interview,development,published,declined', 'note' => 'nullable|string|max:4000', 'articleId' => 'nullable|exists:articles,id']);
        $item = DB::transaction(function () use ($r, $nomination, $d, $audit) {
            $item = Nomination::whereKey($nomination->id)->lockForUpdate()->firstOrFail();
            if (! in_array($d['status'], self::MOVES[$item->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => ['This nomination transition is not allowed.']]);
            }
            if ($d['status'] === 'declined' && blank($d['note'] ?? null)) {
                throw ValidationException::withMessages(['note' => ['A review note is required.']]);
            }
            $articleId = $d['articleId'] ?? $item->article_id;
            if ($d['status'] === 'published') {
                abort_unless($articleId && Article::published()->whereKey($articleId)->exists(), 422, 'Link a published article first.');
            }
            $item->forceFill(['status' => $d['status'], 'review_note' => $d['note'] ?? null, 'article_id' => $articleId])->save();
            $audit->record('nomination.transition', $r->user(), $item, ['status' => $d['status']]);
            if (in_array($d['status'], ['shortlisted', 'declined'], true)) {
                Notification::route('mail', $item->submitter_email)->notify(new TransactionalNotice('Nomination update', $item->reference.' has been '.$d['status'].'.'));
            }

            return $item;
        });

        return response()->json(['data' => $item]);
    }

    public function submissions(Request $r): Response
    {
        return response()->json(StorySubmission::latest()->paginate(20));
    }

    public function moveSubmission(Request $r, StorySubmission $submission, Audit $audit): Response
    {
        $d = $r->validate(['status' => 'required|in:review,accepted,declined', 'note' => 'nullable|string|max:4000']);
        $item = DB::transaction(function () use ($r, $submission, $d, $audit) {
            $item = StorySubmission::whereKey($submission->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($item->status, ['received', 'review'], true), 409, 'This submission has already been resolved.');
            if ($d['status'] === 'declined' && blank($d['note'] ?? null)) {
                throw ValidationException::withMessages(['note' => ['A review note is required.']]);
            }
            if ($d['status'] === 'accepted') {
                $a = Article::create(['title' => $item->title, 'slug' => Str::slug($item->title).'-'.Str::lower(Str::random(6)), 'summary' => $item->pitch, 'author_id' => $r->user()->id]);
                $item->article_id = $a->id;
            }
            $item->status = $d['status'];
            $item->review_note = $d['note'] ?? null;
            $item->save();
            $audit->record('submission.transition', $r->user(), $item, ['status' => $d['status']]);
            User::find($item->user_id)?->notify(new TransactionalNotice('Story submission update', $item->reference.' is now '.$d['status'].'.'));

            return $item;
        });

        return response()->json(['data' => $item]);
    }
}
