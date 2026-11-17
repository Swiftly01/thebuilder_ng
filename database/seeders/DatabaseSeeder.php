<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = ['content.read', 'content.create', 'content.edit', 'content.review', 'content.publish', 'profiles.manage', 'homepage.manage', 'nominations.manage', 'submissions.manage', 'media.manage', 'taxonomy.manage', 'users.manage', 'settings.manage', 'ads.manage', 'audit.read', 'analytics.read', 'youtube.manage'];
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }Role::findOrCreate('registered_user', 'web');
        Role::findOrCreate('admin', 'web')->syncPermissions($permissions);
        Role::findOrCreate('editor_in_chief', 'web')->syncPermissions(array_diff($permissions, ['users.manage', 'settings.manage', 'ads.manage', 'taxonomy.manage']));
        Role::findOrCreate('content_editor', 'web')->syncPermissions(['content.read', 'content.create', 'content.edit', 'media.manage']);
        foreach (['Entrepreneurship', 'Technology', 'Fintech', 'Healthtech', 'Education', 'Agriculture', 'Investment', 'Careers', 'Diaspora', 'Innovation', 'Creative Economy', 'Business', 'Interviews', 'Founder Stories', 'Analysis', 'Opinion', 'AgriTech', 'EdTech', 'Energy'] as $name) {
            Category::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);
        }
        foreach (array_unique(array_merge(...array_values(config('editorial.categories')))) as $name) {
            Category::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);
        }
        foreach (['lead', 'latest', 'trending', 'startups', 'diaspora', 'videos', 'opportunities', 'nominate', 'most-read', 'newsletter'] as $i => $key) {
            DB::table('homepage_sections')->insertOrIgnore(['key' => $key, 'position' => $i, 'visible' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
