<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Administration\Audit;
use App\Http\Controllers\Controller;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class MediaController extends Controller
{
    public function index(Request $r): Response
    {
        $r->validate(['q' => 'nullable|string|max:120', 'per_page' => 'nullable|integer|min:1|max:50']);

        return response()->json(Media::when($r->q, fn ($q) => $q->where('alt', 'like', '%'.$r->q.'%'))->latest()->paginate((int) $r->input('per_page', 24))->through(fn ($media) => $media->toArray() + ['url' => Storage::disk('public')->url($media->path)]));
    }

    public function store(Request $r, Audit $audit): Response
    {
        $d = $r->validate(['file' => 'required|image|mimes:jpg,jpeg,png,webp|max:10240', 'alt' => 'required|string|max:255', 'credit' => 'nullable|string|max:255']);
        abort_unless(extension_loaded('gd'), 503, 'Image processing is unavailable.');
        $bytes = file_get_contents($d['file']->getRealPath());
        $size = getimagesizefromstring($bytes);
        if (! $size || $size[0] * $size[1] > 8000000) {
            throw ValidationException::withMessages(['file' => ['Choose an image smaller than 8 megapixels.']]);
        }
        $image = imagecreatefromstring($bytes);
        if (! $image) {
            throw ValidationException::withMessages(['file' => ['This image could not be decoded.']]);
        }
        $path = 'images/'.Str::ulid().'.webp';
        ob_start();
        imagewebp($image, null, 85);
        $webp = ob_get_clean();
        imagedestroy($image);
        if (! $webp) {
            abort(422, 'This image could not be processed.');
        }
        try {
            $written = Storage::disk('public')->put($path, $webp);
        } catch (\Throwable $error) {
            report($error);
            abort(503, 'Media storage is temporarily unavailable.');
        }
        abort_unless($written, 503, 'Media storage is temporarily unavailable.');
        try {
            $item = DB::transaction(function () use ($r, $d, $path, $webp, $size, $audit) {
                $item = Media::create(['path' => $path, 'mime' => 'image/webp', 'size' => strlen($webp), 'width' => $size[0], 'height' => $size[1], 'alt' => $d['alt'], 'credit' => $d['credit'] ?? null, 'uploaded_by' => $r->user()->id]);
                $audit->record('media.uploaded', $r->user(), $item);

                return $item;
            });
        } catch (\Throwable $e) {
            try {
                if (! Storage::disk('public')->delete($path)) {
                    report(new \RuntimeException('Media rollback cleanup failed for '.$path));
                }
            } catch (\Throwable $cleanupError) {
                report($cleanupError);
            }
            throw $e;
        }

        return response()->json(['data' => $item->toArray() + ['url' => Storage::disk('public')->url($path)]], 201);
    }

    public function show(Media $media): Response
    {
        return response()->json(['data' => $media->toArray() + ['url' => Storage::disk('public')->url($media->path)]]);
    }

    public function update(Request $r, Media $media, Audit $audit): Response
    {
        $d = $r->validate(['alt' => 'required|string|max:255', 'credit' => 'nullable|string|max:255']);
        $media->update($d);
        $audit->record('media.updated', $r->user(), $media);

        return response()->json(['data' => $media]);
    }

    public function destroy(Request $r, Media $media, Audit $audit): Response
    {
        DB::transaction(function () use ($media, $audit, $r): void {
            $media = Media::whereKey($media->id)->lockForUpdate()->firstOrFail();
            abort_if(DB::table('article_media_uses')->where('media_id', $media->id)->exists(), 409, 'This image is used by article content or a retained revision.');
            $pageImages = json_decode(DB::table('site_settings')->where('key', 'pageImages')->value('value') ?? '[]', true);
            abort_if(in_array($media->id, array_values($pageImages), true), 409, 'This image is still used by a page.');
            foreach (['articles', 'builders', 'startups', 'campaigns'] as $table) {
                abort_if(DB::table($table)->where('media_id', $media->id)->exists(), 409, 'This image is still in use.');
            }
            // Compatibility protection for HTML and snapshots created before usage indexing.
            abort_if(DB::table('articles')->where('body', 'like', '%'.$media->path.'%')->exists()
                || DB::table('article_revisions')->where('snapshot->media_id', $media->id)->exists()
                || DB::table('article_revisions')->where('snapshot->body', 'like', '%'.$media->path.'%')->exists(), 409, 'This image is used by existing article content or history.');
            $audit->record('media.deleted', $r->user(), $media);
            try {
                $deleted = Storage::disk('public')->delete($media->path);
            } catch (\Throwable $error) {
                report($error);
                abort(503, 'Media storage is temporarily unavailable.');
            }
            abort_unless($deleted, 503, 'The image could not be removed. Please retry.');
            $media->delete();
        });

        return response()->noContent();
    }
}
