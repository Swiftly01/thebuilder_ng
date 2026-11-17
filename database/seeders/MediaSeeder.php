<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Media;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class MediaSeeder extends Seeder
{
    public function run(): void
    {
        $root = base_path('database/seed-assets');
        $manifest = json_decode(file_get_contents($root.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        // Validate the whole set before modifying files or database records.
        foreach ($manifest['assets'] as $asset) {
            if (! preg_match('/^[a-z0-9-]+\.webp$/D', $asset['file'])) {
                throw new \RuntimeException('Unsafe seed asset filename.');
            }
            $source = $root.'/'.$asset['file'];
            $dimensions = is_file($source) ? getimagesize($source) : false;
            if (! $dimensions || $dimensions['mime'] !== 'image/webp' || $dimensions[0] * $dimensions[1] > 8000000) {
                throw new \RuntimeException('Missing or invalid seed image: '.$asset['file']);
            }
            $path = 'seed/generated/'.$asset['file'];
            if (Storage::disk('public')->exists($path) && ! hash_equals(hash_file('sha256', $source), hash('sha256', Storage::disk('public')->get($path)))) {
                throw new \RuntimeException('Existing seed image differs; use a new versioned filename: '.$path);
            }
        }
        foreach ($manifest['assets'] as $asset) {
            $bytes = file_get_contents($root.'/'.$asset['file']);
            $dimensions = getimagesize($root.'/'.$asset['file']);
            $path = 'seed/generated/'.$asset['file'];
            if (! Storage::disk('public')->exists($path) && ! Storage::disk('public')->put($path, $bytes)) {
                throw new \RuntimeException('Could not store seed image: '.$path);
            }
            // Reruns preserve alt/credit edits made later in the admin console.
            $media = Media::firstOrCreate(['path' => $path], ['mime' => 'image/webp', 'size' => strlen($bytes), 'alt' => $asset['alt'], 'credit' => $manifest['credit']]);
            $media->update(['width' => $dimensions[0], 'height' => $dimensions[1]]);
        }
    }
}
