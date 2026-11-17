<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Editorial\ArticleDocument;
use App\Models\Article;
use App\Models\ArticleRevision;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class RichArticleCmsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('public');
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->mfaMethod()->create(['secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'confirmed_at' => now()]);
        $this->actingAs($user)->withHeader('Origin', 'http://localhost:3000')->withSession(['mfa_verified_at' => now()->timestamp]);

        return $user;
    }

    private function image(): Media
    {
        $media = Media::create(['path' => 'images/cms-test.webp', 'mime' => 'image/webp', 'size' => 12, 'width' => 1200, 'height' => 800, 'alt' => 'Builders at work', 'credit' => 'The Builder']);
        Storage::disk('public')->put($media->path, 'test image');

        return $media;
    }

    private function paragraph(string $text): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private function document(Media $media): array
    {
        return ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => ['textAlign' => 'right'], 'content' => [['type' => 'text', 'text' => 'Before the image', 'marks' => [
                ['type' => 'bold'], ['type' => 'textStyle', 'attrs' => ['fontFamily' => 'Montserrat', 'fontSize' => '24px', 'color' => '#1e3f8f', 'lineHeight' => '1.7']], ['type' => 'highlight', 'attrs' => ['color' => '#fff2a8']],
            ]]]],
            ['type' => 'mediaFigure', 'attrs' => ['mediaId' => $media->id, 'src' => 'javascript:ignored', 'alt' => 'Builders at work', 'caption' => 'Inside <script>alert(1)</script> caption', 'credit' => 'Photo: Test', 'layout' => 'landscape']],
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'After the image']]],
            ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [$this->paragraph('A table cell')]]]]]],
        ]];
    }

    public function test_formatting_images_preview_and_publication_round_trip_through_the_database(): void
    {
        $this->admin();
        $media = $this->image();
        $document = $this->document($media);
        $response = $this->postJson('/api/v1/console/content', ['title' => 'Rich CMS article', 'summary' => 'An editorial summary', 'bodyDocument' => $document])->assertCreated();
        $article = Article::findOrFail($response->json('data.id'));
        $this->assertStringContainsString('font-family:Montserrat;font-size:24px;color:#1e3f8f;line-height:1.7', $article->body);
        $this->assertStringContainsString('text-align:right', $article->body);
        $this->assertStringContainsString('background-color:#fff2a8', $article->body);
        $this->assertStringContainsString('width="1200" height="800"', $article->body);
        $this->assertStringContainsString('<table>', $article->body);
        $this->assertStringContainsString('&lt;script&gt;', $article->body);
        $this->assertStringNotContainsString('<script>', $article->body);
        $this->assertStringNotContainsString('javascript:', $article->body);
        $this->assertLessThan(strpos($article->body, 'data-media-id'), strpos($article->body, 'Before the image'));
        $this->assertLessThan(strpos($article->body, 'After the image'), strpos($article->body, 'data-media-id'));
        $this->assertArrayNotHasKey('src', $article->body_document['content'][1]['attrs']);
        $response->assertJsonPath('data.bodyDocument.content.1.attrs.src', Storage::disk('public')->url($media->path));
        $this->assertSame(2, DB::table('article_media_uses')->where('media_id', $media->id)->count());
        $this->postJson('/api/v1/console/content-preview', ['bodyDocument' => $document])->assertOk()->assertJsonPath('data.body', $article->body)->assertHeader('Cache-Control', 'no-store, private');
        foreach (['review', 'approved', 'published'] as $status) {
            $article->refresh();
            $this->withHeader('If-Match', (string) $article->version)->postJson('/api/v1/console/content/'.$article->id.'/transition', ['status' => $status])->assertOk();
        }
        $this->getJson('/api/v1/articles/'.$article->slug)->assertOk()->assertJsonPath('data.body', $article->fresh()->body)->assertJsonMissingPath('data.bodyDocument');
        $this->deleteJson('/api/v1/console/media/'.$media->id)->assertConflict();
        Storage::disk('public')->assertExists($media->path);
    }

    public function test_image_revision_survives_replacement_and_restore_and_stale_saves_are_rejected(): void
    {
        $this->admin();
        $media = $this->image();
        $id = $this->postJson('/api/v1/console/content', ['title' => 'History', 'bodyDocument' => $this->document($media)])->assertCreated()->json('data.id');
        $revision = ArticleRevision::where('article_id', $id)->firstOrFail();
        $this->withHeader('If-Match', '1')->patchJson('/api/v1/console/content/'.$id, ['bodyDocument' => ['type' => 'doc', 'content' => [$this->paragraph('Replacement')]]])->assertOk();
        $this->assertSame(0, DB::table('article_media_uses')->where('article_id', $id)->whereNull('revision_id')->count());
        $this->deleteJson('/api/v1/console/media/'.$media->id)->assertConflict();
        $this->withHeader('If-Match', '1')->patchJson('/api/v1/console/content/'.$id, ['title' => 'Stale title'])->assertStatus(412);
        $this->withHeader('If-Match', '2')->postJson('/api/v1/console/content/'.$id.'/versions/'.$revision->id.'/restore')->assertOk()->assertJsonPath('data.bodyDocument.content.1.attrs.mediaId', $media->id);
        $this->assertStringContainsString('font-family:Montserrat', Article::findOrFail($id)->body);
    }

    public function test_legacy_import_is_read_only_and_legacy_revision_restoration_clears_the_new_document(): void
    {
        $this->admin();
        $legacy = '<p>Original <strong>words</strong> and <a href="https://example.com">link</a>.</p><table><tbody><tr><td>A cell</td></tr></tbody></table>';
        $id = $this->postJson('/api/v1/console/content', ['title' => 'Legacy', 'body' => $legacy])->assertCreated()->json('data.id');
        $revision = ArticleRevision::where('article_id', $id)->firstOrFail();
        $original = Article::findOrFail($id)->body;
        $this->postJson('/api/v1/console/content-import', ['body' => $original])->assertOk()->assertJsonPath('data.document.content.0.content.1.marks.0.type', 'bold')->assertJsonPath('data.document.content.1.type', 'table');
        $this->assertNull(Article::findOrFail($id)->body_document);
        $this->withHeader('If-Match', '1')->patchJson('/api/v1/console/content/'.$id, ['bodyDocument' => ['type' => 'doc', 'content' => [$this->paragraph('New document')]]])->assertOk();
        $this->withHeader('If-Match', '2')->patchJson('/api/v1/console/content/'.$id, ['body' => '<p>Cannot replace the document</p>'])->assertUnprocessable();
        $this->withHeader('If-Match', '2')->postJson('/api/v1/console/content/'.$id.'/versions/'.$revision->id.'/restore')->assertOk()->assertJsonPath('data.bodyDocument', null)->assertJsonPath('data.body', $original);
        $this->postJson('/api/v1/console/content-import', ['body' => '<p><img src="https://external.test/image.jpg" alt="External"></p>'])->assertUnprocessable();
        $this->assertSame($original, Article::findOrFail($id)->body);
    }

    public function test_invalid_or_executable_documents_are_rejected_without_creating_content(): void
    {
        $this->admin();
        $cases = [
            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Invalid list start']]]]]]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Duplicate marks', 'marks' => [['type' => 'bold'], ['type' => 'bold']]]]],
            ['type' => 'iframe', 'attrs' => ['src' => 'https://evil.test']],
            ['type' => 'paragraph', 'attrs' => ['textAlign' => 'left;position:fixed']],
            ['type' => 'heading', 'attrs' => ['level' => 1]],
            ['type' => 'mediaFigure', 'attrs' => ['mediaId' => 'missing', 'alt' => 'Missing']],
        ];
        foreach ([['link', ['href' => 'javascript:alert(1)']], ['link', ['href' => '//evil.test']], ['link', ['href' => '/\\evil.test']], ['textStyle', ['fontFamily' => 'url(https://evil.test)']], ['textStyle', ['fontSize' => '9999px']], ['textStyle', ['color' => 'red;background:url(x)']], ['highlight', ['color' => 'transparent']]] as [$type, $attrs]) {
            $cases[] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Unsafe', 'marks' => [['type' => $type, 'attrs' => $attrs]]]]];
        }
        foreach ($cases as $block) {
            $this->postJson('/api/v1/console/content', ['title' => 'Invalid document', 'bodyDocument' => ['type' => 'doc', 'content' => [$block]]])->assertUnprocessable();
        }
        $deep = $this->paragraph('Deep');
        for ($i = 0; $i < 26; $i++) {
            $deep = ['type' => 'blockquote', 'content' => [$deep]];
        }
        $this->postJson('/api/v1/console/content-preview', ['bodyDocument' => ['type' => 'doc', 'content' => [$deep]]])->assertUnprocessable();
        $this->postJson('/api/v1/console/content', ['title' => 'Two sources', 'body' => 'HTML', 'bodyDocument' => ['type' => 'doc', 'content' => []]])->assertUnprocessable();
        $this->assertDatabaseCount('articles', 0);
    }

    public function test_reader_cannot_use_editor_preview_or_import(): void
    {
        $user = User::factory()->create();
        $user->assignRole('registered_user');
        $this->actingAs($user)->withHeader('Origin', 'http://localhost:3000');
        $this->postJson('/api/v1/console/content-preview', ['bodyDocument' => ['type' => 'doc', 'content' => []]])->assertForbidden();
        $this->postJson('/api/v1/console/content-import', ['body' => '<p>Content</p>'])->assertForbidden();
    }

    public function test_all_supported_text_formats_and_duplicate_image_positions_are_preserved(): void
    {
        $this->admin();
        $media = $this->image();
        $document = $this->document($media);
        $document['content'][] = $document['content'][1];
        $document['content'][] = ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [$this->paragraph('List item')]]]];
        foreach (['italic', 'underline', 'strike', 'code', 'subscript', 'superscript'] as $mark) {
            $document['content'][] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $mark, 'marks' => [['type' => $mark]]]]];
        }
        $prepared = app(ArticleDocument::class)->prepare($document);
        $this->assertSame([$media->id], $prepared['mediaIds']);
        $this->assertSame(2, substr_count($prepared['html'], '<figure'));
        foreach (['<em>', '<u>', '<s>', '<code>', '<sub>', '<sup>', '<ul>'] as $tag) {
            $this->assertStringContainsString($tag, $prepared['html']);
        }
    }

    public function test_media_in_a_legacy_html_revision_remains_protected_after_replacement(): void
    {
        $this->admin();
        $media = $this->image();
        $body = '<p>Legacy image</p><img src="'.Storage::disk('public')->url($media->path).'" alt="A legacy image">';
        $id = $this->postJson('/api/v1/console/content', ['title' => 'Legacy image history', 'body' => $body])->assertCreated()->json('data.id');
        $this->withHeader('If-Match', '1')->patchJson('/api/v1/console/content/'.$id, ['body' => '<p>Replaced content</p>'])->assertOk();
        $this->assertSame(0, DB::table('article_media_uses')->where('media_id', $media->id)->count());
        $this->deleteJson('/api/v1/console/media/'.$media->id)->assertConflict();
        Storage::disk('public')->assertExists($media->path);
    }

    public function test_storage_origin_can_change_without_rewriting_the_document(): void
    {
        $this->admin();
        $media = $this->image();
        $id = $this->postJson('/api/v1/console/content', ['title' => 'Portable images', 'bodyDocument' => $this->document($media)])->assertCreated()->json('data.id');
        $original = Article::findOrFail($id)->body;
        config(['filesystems.disks.public.url' => 'https://media.example.test/storage']);
        Storage::forgetDisk('public');
        $response = $this->getJson('/api/v1/console/content/'.$id)->assertOk()->assertJsonPath('data.bodyDocument.content.1.attrs.src', 'https://media.example.test/storage/'.$media->path);
        $this->assertStringContainsString('https://media.example.test/storage/'.$media->path, $response->json('data.body'));
        $this->assertSame($original, Article::findOrFail($id)->body);
    }
}
