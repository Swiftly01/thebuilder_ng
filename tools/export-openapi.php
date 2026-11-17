<?php

declare(strict_types=1);
use App\Domain\Administration\ApiContract;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$document = $app->make(ApiContract::class)->document();
$json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
$path = dirname(__DIR__).'/docs/openapi.json';
if (in_array('--check', $argv, true)) {
    if (! is_file($path) || file_get_contents($path) !== $json) {
        fwrite(STDERR, "OpenAPI contract is out of date. Run php tools/export-openapi.php.\n");
        exit(1);
    }
} else {
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    file_put_contents($path, $json);
}
echo count($document['paths'])." documented API paths.\n";
