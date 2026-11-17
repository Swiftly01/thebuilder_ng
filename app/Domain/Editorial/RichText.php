<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

final class RichText
{
    public function sanitize(string $html): string
    {
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'p,br,strong,b,em,i,u,h2,h3,h4,blockquote,ul,ol,li,a[href|title],table,thead,tbody,tr,th,td,img[src|alt|title]');
        $config->set('URI.AllowedSchemes', ['https' => true, 'http' => true, 'mailto' => true]);
        $config->set('Cache.SerializerPath', storage_path('framework/cache'));

        return (new \HTMLPurifier($config))->purify($html);
    }
}
