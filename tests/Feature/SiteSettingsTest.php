<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->withHeader('Origin', 'http://localhost:3000');
    }

    private function admin(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->mfaMethod()->create(['secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'confirmed_at' => now()]);
        $this->actingAs($user)->withSession(['mfa_verified_at' => now()->timestamp]);
    }

    public function test_settings_persist_for_public_readers_only_after_authorized_mutation(): void
    {
        $this->putJson('/api/v1/console/settings', ['siteName' => 'Not authorized'])->assertUnauthorized();
        $reader = User::factory()->create();
        $reader->assignRole('registered_user');
        $this->actingAs($reader)->putJson('/api/v1/console/settings', ['siteName' => 'Not authorized'])->assertForbidden();
        $this->admin();
        $settings = ['siteName' => 'The Builder', 'description' => 'Editor configured description', 'footer' => 'Plain <script> text', 'socialLinks' => ['youtube' => 'https://www.youtube.com/@example'], 'menus' => [['label' => 'Stories', 'url' => '/stories?type=analysis'], ['label' => 'External resource', 'url' => 'https://example.org/resources']]];
        $this->putJson('/api/v1/console/settings', $settings)->assertOk()->assertJsonPath('data.menus.0.label', 'Stories');
        DB::table('site_settings')->insert(['key' => 'privateMarker', 'value' => json_encode('private test marker'), 'public' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson('/api/v1/settings/public')->assertOk()->assertJsonPath('data.footer', $settings['footer'])->assertJsonPath('data.socialLinks.youtube', $settings['socialLinks']['youtube'])->assertJsonPath('data.menus.1.url', 'https://example.org/resources')->assertJsonMissingPath('data.privateMarker');
        $this->assertDatabaseHas('audit_events', ['event' => 'settings.updated']);
    }

    public function test_unsafe_navigation_is_rejected_without_partially_saving_settings(): void
    {
        $this->admin();
        $this->putJson('/api/v1/console/settings', ['siteName' => 'Original publication'])->assertOk();
        foreach (['//evil.example/path', '/\\evil.example/path', 'javascript:alert(1)', 'data:text/html,unsafe', "/path\nheader", 'https://name:password@example.org', 'https:example.org', 'relative/path'] as $url) {
            $this->putJson('/api/v1/console/settings', ['siteName' => 'Unexpected overwrite', 'menus' => [['label' => 'Unsafe', 'url' => $url]]])->assertUnprocessable()->assertJsonValidationErrors('menus.0.url');
        }
        $this->getJson('/api/v1/settings/public')->assertJsonPath('data.siteName', 'Original publication');
    }

    public function test_navigation_resets_and_optional_settings_clear_with_known_social_platforms_only(): void
    {
        $this->admin();
        $this->putJson('/api/v1/console/settings', ['menus' => [['label' => 'Stories', 'url' => '/stories']], 'contactEmail' => 'editor@example.test', 'footer' => 'Earlier footer'])->assertOk();
        $this->putJson('/api/v1/console/settings', ['menus' => null, 'contactEmail' => null, 'footer' => null, 'socialLinks' => []])->assertOk();
        $this->getJson('/api/v1/settings/public')->assertJsonPath('data.menus', null)->assertJsonPath('data.footer', null)->assertJsonPath('data.contactEmail', null)->assertJsonPath('data.socialLinks', []);
        $this->putJson('/api/v1/console/settings', ['socialLinks' => ['privateKey' => 'https://example.org']])->assertUnprocessable();
        $this->putJson('/api/v1/console/settings', ['socialLinks' => ['youtube' => 'https://name:password@example.org']])->assertUnprocessable();
        $this->putJson('/api/v1/console/settings', ['menus' => []])->assertUnprocessable();
        $this->putJson('/api/v1/console/settings', ['menus' => [['label' => 'One', 'url' => '/stories'], ['label' => 'Two', 'url' => '/stories']]])->assertUnprocessable();
        $this->putJson('/api/v1/console/settings', ['apiKey' => 'must not persist'])->assertOk();
        $this->assertDatabaseMissing('site_settings', ['key' => 'apiKey']);
    }
}
