<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Article;
use App\Models\Builder;
use App\Models\Category;
use App\Models\Media;
use App\Models\Startup;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Explicit, repeatable sample content. Never called by the production seeder. */
class DemoContentSeeder extends Seeder
{
    public function run(): void
    {
        $testing = app()->environment('testing');
        if (! $testing && (! app()->environment('local') || config('database.connections.mysql.database') !== 'the_builder_replatform_preview_20261014')) {
            throw new \RuntimeException('Demo content is restricted to the isolated local preview or test database.');
        }
        $this->call([DatabaseSeeder::class, MediaSeeder::class]);
        DB::transaction(function (): void {
            $manifest = json_decode(file_get_contents(base_path('database/seed-assets/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
            $media = [];
            foreach ($manifest['assets'] as $asset) {
                $media[$asset['key']] = Media::where('path', 'seed/generated/'.$asset['file'])->firstOrFail()->id;
            }
            $author = User::firstOrCreate(['email' => 'demo-editor@example.test'], ['name' => 'The Builder Demo Editor', 'password' => Str::random(64)]);
            if (! DB::table('site_settings')->where('key', 'pageImages')->exists()) {
                DB::table('site_settings')->insert(['key' => 'pageImages', 'value' => json_encode(['about' => $media['collaboration'], 'partner' => $media['product-studio'], 'diaspora' => $media['community']]), 'public' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
            if ($author->wasRecentlyCreated) {
                $author->forceFill(['status' => 'suspended'])->save();
            }
            $subjects = [
                ['Business', 'How collaborative studios help ideas take shape', 'collaboration'],
                ['Startups', 'Inside a product studio built around customer needs', 'product-studio'],
                ['Community', 'A workshop connecting founders across borders', 'community'],
                ['Entrepreneurs', 'Building a company with a practical first prototype', 'product-studio'],
                ['Academia', 'Turning university research into useful tools', 'collaboration'],
                ['Interviews', 'A conversation about designing for everyday problems', 'community'],
                ['Research', 'Exploring the next generation of clean energy', 'energy'],
                ['Policy', 'Creating room for sustainable innovation', 'energy'],
                ['Diaspora', 'The networks that connect builders around the world', 'community'],
            ];
            $articles = [];
            foreach (['Nigeria', 'Australia'] as $edition) {
                foreach ($subjects as $index => [$category, $title, $image]) {
                    if (! in_array($category, config('editorial.categories.'.$edition), true)) {
                        continue;
                    }
                    $item = Article::firstOrCreate(['slug' => 'demo-'.Str::slug($edition.'-'.$category)], [
                        'title' => $title.' — Demo', 'summary' => 'Demonstration content with an AI-generated illustration. Replace this sample through the admin console.',
                        'body' => '<p>This is fictional demonstration content for The Builder '.$edition.' edition.</p><h2>Ideas into action</h2><p>Use the editorial console to replace the text, select an uploaded image, and publish an approved story.</p>',
                        'type' => 'article', 'author_id' => $author->id, 'category_id' => Category::where('slug', Str::slug($category))->firstOrFail()->id,
                        'media_id' => $media[$image], 'edition' => $edition, 'country' => $edition,
                    ]);
                    if ($item->wasRecentlyCreated) {
                        $item->forceFill(['status' => 'published', 'published_at' => now()->subMinutes($index + 1)])->save();
                    }
                    $articles[$edition][] = $item;
                }
            }
            $profiles = [
                ['ada-demo', 'Ada Okoro (Demo)', 'Nigeria', 'Lagos', 'Africa', 'founder', 'Startup Founder'],
                ['tunde-demo', 'Tunde Bello (Demo)', 'Australia', 'Melbourne', 'Oceania', 'engineer', 'Professional'],
                ['nora-demo', 'Nora Mensah (Demo)', 'United Kingdom', 'London', 'Europe', 'creative', 'Creative'],
                ['kofi-demo', 'Kofi Adeyemi (Demo)', 'Canada', 'Toronto', 'North America', 'researcher', 'Academic'],
                ['builders-us-demo', 'Builders Studio US (Demo)', 'United States', 'New York', 'North America', 'community', 'Community Leader'],
            ];
            $builders = [];
            foreach ($profiles as [$slug, $name, $country, $city, $region, $image, $classification]) {
                $builder = Builder::firstOrCreate(['slug' => 'demo-'.$slug], [
                    'name' => $name, 'classification' => $classification, 'headline' => 'Fictional demo profile — replace with an approved builder',
                    'biography' => 'This fictional profile demonstrates the database-driven directory. Its image is AI-generated and does not depict a real featured person.',
                    'country' => $country, 'city' => $city, 'region' => $region, 'industry' => 'Technology', 'media_id' => $media[$image],
                    'consent_method' => 'Fictional demo record; no real person represented', 'is_diaspora' => $country !== 'Nigeria', 'feature' => $country === 'Australia' ? 'month' : null,
                ]);
                if ($builder->wasRecentlyCreated) {
                    $builder->forceFill(['status' => 'published', 'consent_at' => now()])->save();
                }
                $builders[] = $builder;
            }
            foreach ([['Studio Prototype (Demo)', 'Nigeria', 'Lagos', 'product-studio'], ['Solar Workshop (Demo)', 'Australia', 'Melbourne', 'energy'], ['Community Lab (Demo)', 'United Kingdom', 'London', 'community']] as $index => [$name, $country, $city, $image]) {
                $startup = Startup::firstOrCreate(['slug' => 'demo-'.Str::slug($name)], [
                    'name' => $name, 'one_liner' => 'A fictional venture demonstrating the startup directory.',
                    'description' => 'Demo company with an AI-generated illustration. Replace with an approved startup profile through the admin console.',
                    'industry' => $index === 1 ? 'Energy' : 'Technology', 'stage' => 'Seed', 'country' => $country, 'city' => $city, 'founded_year' => 2024, 'team_size' => 6,
                    'media_id' => $media[$image],
                ]);
                if ($startup->wasRecentlyCreated) {
                    $startup->forceFill(['status' => 'published'])->save();
                    $startup->founders()->sync([$builders[$index]->id]);
                }
            }
            // Repair only active records filled by the former one-photo seed,
            // plus genuinely missing images. Preserve all admin-selected media.
            $legacy = Media::where('path', 'seed/editorial-default.jpg')->value('id');
            Article::where(function ($query) use ($legacy): void {
                $query->whereNull('media_id');
                if ($legacy) {
                    $query->orWhere('media_id', $legacy);
                }
            })->get()->each(function (Article $article) use ($media): void {
                $article->update(['media_id' => $media['collaboration']]);
            });
        });
    }
}
