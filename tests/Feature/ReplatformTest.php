<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\Totp;
use App\Models\Article;
use App\Models\ArticleRevision;
use App\Models\NewsletterSubscription;
use App\Models\User;
use App\Notifications\QueuedVerifyEmail as VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ReplatformTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost:3000');
        $this->seed();
        Notification::fake();
    }

    private function editor(string $role = 'admin'): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $u->mfaMethod()->create(['secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'confirmed_at' => now()]);
        $this->actingAs($u, 'web')->withSession(['mfa_verified_at' => now()->timestamp]);

        return $u;
    }

    private function story(User $u, string $status = 'published', string $title = 'Verified public story'): Article
    {
        return Article::forceCreate(['title' => $title, 'slug' => strtolower(str_replace(' ', '-', $title)), 'summary' => 'A meaningful editorial summary.', 'body' => '<p>The actual approved story body.</p>', 'author_id' => $u->id, 'status' => $status, 'published_at' => $status === 'published' ? now()->subMinute() : null]);
    }

    public function test_only_published_articles_are_public_searchable_and_in_sitemap(): void
    {
        $u = User::factory()->create();
        $a = $this->story($u);
        $draft = $this->story($u, 'draft', 'Secret draft');
        $this->getJson('/api/v1/articles')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/articles/'.$draft->slug)->assertNotFound();
        $this->getJson('/api/v1/search?q=Secret')->assertOk()->assertJsonCount(0, 'data.stories');
        $this->get('/api/v1/sitemap.xml')->assertOk()->assertSee($a->slug)->assertDontSee($draft->slug);
    }

    public function test_registration_never_accepts_client_role_and_sessions_are_cookie_based(): void
    {
        $this->withHeader('Origin', 'http://localhost:3000')->postJson('/api/v1/auth/register', ['name' => 'Test Reader', 'email' => 'READER@example.com', 'password' => 'StrongPassword42', 'roles' => ['admin']])->assertCreated()->assertJsonPath('data.emailVerified', false);
        $u = User::where('email', 'reader@example.com')->firstOrFail();
        $this->assertTrue($u->hasRole('registered_user'));
        $this->assertFalse($u->hasRole('admin'));
        Notification::assertSentTo($u, VerifyEmail::class);
        $this->getJson('/api/v1/me/profile')->assertForbidden();
    }

    public function test_login_errors_are_generic_and_fifth_failure_locks_next_attempt(): void
    {
        User::factory()->create(['email' => 'reader@example.com']);
        $this->withHeader('Origin', 'http://localhost:3000');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'reader@example.com', 'password' => 'wrong'])->assertUnprocessable()->assertJsonPath('errors.email.0', 'The provided credentials are incorrect.');
        }$this->postJson('/api/v1/auth/login', ['email' => 'reader@example.com', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_email_link_is_signed_and_single_use(): void
    {
        $u = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $u->id, 'hash' => sha1($u->email)]);
        $this->get($url)->assertRedirect();
        $this->assertTrue($u->refresh()->hasVerifiedEmail());
        $this->get($url)->assertStatus(409);
        $this->get('/api/v1/auth/email/verify/'.$u->id.'/bad')->assertForbidden();
    }

    public function test_unauthorized_and_reader_console_access_fail(): void
    {
        $this->getJson('/api/v1/console/content')->assertUnauthorized();
        $u = User::factory()->create();
        $u->assignRole('registered_user');
        $this->actingAs($u)->getJson('/api/v1/console/content')->assertForbidden();
    }

    public function test_admin_requires_mfa_enrollment_and_challenge(): void
    {
        $u = User::factory()->create();
        $u->assignRole('admin');
        $this->actingAs($u)->getJson('/api/v1/console/dashboard')->assertForbidden()->assertJsonPath('detail', 'mfa_setup_required');
        $u->mfaMethod()->create(['secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'confirmed_at' => now()]);
        $u->unsetRelation('mfaMethod');
        $this->getJson('/api/v1/console/dashboard')->assertForbidden()->assertJsonPath('detail', 'mfa_required');
    }

    public function test_mfa_secret_is_encrypted_and_recovery_code_is_single_use(): void
    {
        $u = User::factory()->create();
        $u->assignRole('admin');
        $this->actingAs($u)->withSession([]);
        $setup = $this->postJson('/api/v1/auth/mfa/setup')->assertOk()->json('data');
        $this->assertNotSame($setup['secret'], DB::table('user_mfa_methods')->where('user_id', $u->id)->value('secret'));
        $code = app(Totp::class)->code($setup['secret']);
        $codes = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $code])->assertOk()->json('data.recoveryCodes');
        $this->assertCount(8, $codes);
        $this->postJson('/api/v1/auth/mfa/challenge', ['code' => $code])->assertUnprocessable();
        $this->postJson('/api/v1/auth/mfa/challenge', ['recoveryCode' => $codes[0]])->assertNoContent();
        $this->postJson('/api/v1/auth/mfa/challenge', ['recoveryCode' => $codes[0]])->assertUnprocessable();
    }

    public function test_editorial_save_sanitizes_html_and_checks_versions(): void
    {
        $u = $this->editor();
        $data = $this->postJson('/api/v1/console/content', ['title' => 'Safe draft', 'summary' => 'An approved summary.', 'body' => '<p>Hello<script>alert(1)</script><a href="javascript:alert(1)">Bad URL</a></p>'])->assertCreated()->json('data');
        $this->assertStringNotContainsString('<script', $data['body']);
        $this->assertStringNotContainsString('javascript:', $data['body']);
        $this->patchJson('/api/v1/console/content/'.$data['id'], ['title' => 'Updated'])->assertStatus(428);
        $this->withHeader('If-Match', '1')->patchJson('/api/v1/console/content/'.$data['id'], ['title' => 'Updated'])->assertOk()->assertJsonPath('data.version', 2);
        $this->withHeader('If-Match', '1')->patchJson('/api/v1/console/content/'.$data['id'], ['title' => 'Stale'])->assertStatus(412);
        $this->assertSame(2, ArticleRevision::where('article_id', $data['id'])->count());
    }

    public function test_approved_workflow_required_and_publishing_is_public_after_success(): void
    {
        $u = $this->editor();
        $a = $this->story($u, 'draft');
        $endpoint = '/api/v1/console/content/'.$a->id.'/transition';
        $this->withHeader('If-Match', '1')->postJson($endpoint, ['status' => 'published'])->assertUnprocessable();
        $this->postJson($endpoint, ['status' => 'review'])->assertOk()->assertJsonPath('data.version', 2);
        $this->withHeader('If-Match', '2')->postJson($endpoint, ['status' => 'changes'])->assertUnprocessable();
        $this->postJson($endpoint, ['status' => 'approved'])->assertOk();
        $this->withHeader('If-Match', '3')->postJson($endpoint, ['status' => 'published'])->assertOk();
        $this->getJson('/api/v1/articles/'.$a->slug)->assertOk()->assertJsonPath('data.id', $a->id);
    }

    public function test_content_editor_cannot_publish_or_edit_another_author(): void
    {
        $u = $this->editor('content_editor');
        $other = User::factory()->create();
        $a = $this->story($other, 'approved');
        $this->withHeader('If-Match', '1')->patchJson('/api/v1/console/content/'.$a->id, ['title' => 'Hijack'])->assertForbidden();
        $this->postJson('/api/v1/console/content/'.$a->id.'/transition', ['status' => 'published'])->assertForbidden();
    }

    public function test_profile_requires_consent_before_publication(): void
    {
        $this->editor();
        $this->postJson('/api/v1/console/builders', ['name' => 'A Builder', 'classification' => 'Professional', 'status' => 'published'])->assertUnprocessable();
        $b = $this->postJson('/api/v1/console/builders', ['name' => 'A Builder', 'classification' => 'Professional', 'status' => 'published', 'consentAt' => now()->toISOString(), 'consentMethod' => 'Written email permission'])->assertCreated()->json('data');
        $this->getJson('/api/v1/builders/'.$b['slug'])->assertOk()->assertJsonMissingPath('data.consent_at');
    }

    public function test_bookmarks_are_owned_and_not_duplicated(): void
    {
        $a = $this->story(User::factory()->create());
        $u = User::factory()->create();
        $this->actingAs($u)->putJson('/api/v1/me/bookmarks/'.$a->id)->assertNoContent();
        $this->putJson('/api/v1/me/bookmarks/'.$a->id)->assertNoContent();
        $this->getJson('/api/v1/me/bookmarks')->assertOk()->assertJsonCount(1, 'data');
        $other = User::factory()->create();
        $this->actingAs($other)->getJson('/api/v1/me/bookmarks')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson('/api/v1/me/bookmarks/'.$a->id)->assertNoContent();
        $this->assertDatabaseCount('bookmarks', 1);
    }

    public function test_nomination_idempotency_replays_exact_reference_and_rejects_mismatch(): void
    {
        $body = ['type' => 'Builder', 'name' => 'A real nominee', 'industry' => 'Technology', 'country' => 'Nigeria', 'reason' => str_repeat('Impactful contribution. ', 3), 'submitterName' => 'A reader', 'submitterEmail' => 'reader@example.com', 'relationship' => 'Colleague', 'consent' => true];
        $this->withHeader('Idempotency-Key', 'nomination-12345');
        $ref = $this->postJson('/api/v1/nominations', $body)->assertCreated()->json('data.reference');
        $this->postJson('/api/v1/nominations', $body)->assertCreated()->assertJsonPath('data.reference', $ref);
        $this->assertDatabaseCount('nominations', 1);
        $this->postJson('/api/v1/nominations', [...$body, 'name' => 'Different nominee'])->assertStatus(409);
        $this->assertMatchesRegularExpression('/^TB-NOM-\d{4}-000001$/', $ref);
    }

    public function test_newsletter_confirmation_is_hashed_expiring_and_single_use(): void
    {
        $this->withHeader('Idempotency-Key', 'newsletter-12345')->postJson('/api/v1/newsletter/subscriptions', ['email' => 'reader@example.com'])->assertStatus(202);
        $item = NewsletterSubscription::firstOrFail();
        $token = str_repeat('a', 64);
        $item->update(['token_hash' => hash('sha256', $token)]);
        $this->postJson('/api/v1/newsletter/confirm', ['token' => $token])->assertOk();
        $this->postJson('/api/v1/newsletter/confirm', ['token' => $token])->assertNotFound();
        $this->assertSame('confirmed', $item->refresh()->status);
    }

    public function test_admin_cannot_suspend_or_remove_self(): void
    {
        $u = $this->editor();
        $this->patchJson('/api/v1/console/users/'.$u->id, ['status' => 'suspended'])->assertUnprocessable();
        $this->patchJson('/api/v1/console/users/'.$u->id, ['roles' => ['registered_user']])->assertUnprocessable();
    }

    public function test_validation_problem_and_unknown_routes_have_safe_errors(): void
    {
        $this->getJson('/api/v1/articles?per_page=500')->assertUnprocessable()->assertHeader('Content-Type', 'application/problem+json')->assertJsonStructure(['status', 'detail', 'errors', 'requestId']);
        $this->getJson('/api/v1/unknown')->assertNotFound()->assertHeader('X-Request-ID');
    }

    public function test_migrations_have_no_ticket_sponsor_or_volunteer_tables(): void
    {
        foreach (['ticket_types', 'registrations', 'sponsors', 'volunteers', 'speakers'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
    }
}
