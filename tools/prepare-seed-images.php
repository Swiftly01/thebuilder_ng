<?php

declare(strict_types=1);

// Deterministic format conversion of already-generated assets; no image API calls.
if ($argc !== 2 || ! is_dir($argv[1]) || ! extension_loaded('gd')) {
    fwrite(STDERR, "Usage: php -d extension=gd tools/prepare-seed-images.php <generated-image-directory>\n");
    exit(1);
}
$root = dirname(__DIR__).'/database/seed-assets';
$manifest = json_decode(file_get_contents($root.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
foreach ($manifest['assets'] as $asset) {
    $target = $root.'/'.$asset['file'];
    if (is_file($target)) {
        continue;
    }
    $source = realpath($argv[1]).DIRECTORY_SEPARATOR.$asset['source'];
    $image = imagecreatefrompng($source);
    if (! $image) {
        throw new RuntimeException('Could not decode '.$asset['source']);
    }
    $scale = min(1, 1440 / max(imagesx($image), imagesy($image)));
    $output = imagescale($image, (int) round(imagesx($image) * $scale), (int) round(imagesy($image) * $scale));
    if (! $output || ! imagewebp($output, $target, 84)) {
        throw new RuntimeException('Could not write '.$asset['file']);
    }
    echo $asset['file'].' '.filesize($target)." bytes\n";
}
