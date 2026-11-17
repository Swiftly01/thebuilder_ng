<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** A bounded document schema. HTML is generated only from validated nodes and escaped text. */
final class ArticleDocument
{
    private int $nodes = 0;

    private const BLOCKS = ['paragraph', 'heading', 'blockquote', 'bulletList', 'orderedList', 'codeBlock', 'horizontalRule', 'mediaFigure', 'table'];

    private const FONTS = ['Source Serif 4', 'Inter', 'Montserrat', 'Georgia', 'Arial', 'Times New Roman', 'monospace'];

    /** @param array<string,mixed> $document
     * @return array{document: array<string,mixed>, html: string, mediaIds: list<string>}
     */
    public function prepare(array $document, bool $lock = false): array
    {
        $this->nodes = 0;
        $this->ensure(strlen(json_encode($document, JSON_THROW_ON_ERROR)) <= 500000, 'The document is too large.');
        $this->ensure(($document['type'] ?? '') === 'doc', 'A document root is required.');
        $clean = $this->node($document, 0, ['doc']);
        $ids = $this->ids($clean);
        $query = Media::whereIn('id', $ids)->orderBy('id');
        $media = ($lock ? $query->lockForUpdate() : $query)->get()->keyBy('id');
        $this->ensure($media->count() === count($ids), 'An image is missing. Choose another image from Media.');
        $html = $this->html($clean, $media->all());
        $this->ensure(strlen($html) <= 500000, 'The rendered article is too large.');

        return ['document' => $clean, 'html' => $html, 'mediaIds' => $ids];
    }

    /** Attach delivery URLs on responses only; persisted documents contain media IDs.
     * @param  array<string,mixed>  $document
     * @return array<string,mixed>
     */
    public function hydrate(array $document): array
    {
        $media = Media::whereIn('id', $this->ids($document))->get()->keyBy('id');
        $walk = function (array $node) use (&$walk, $media): array {
            if (($node['type'] ?? '') === 'mediaFigure') {
                $item = $media->get($node['attrs']['mediaId'] ?? '');
                $node['attrs']['src'] = $item ? Storage::disk('public')->url($item->path) : '';
            }
            if (isset($node['content'])) {
                $node['content'] = array_map($walk, $node['content']);
            }

            return $node;
        };

        return $walk($document);
    }

    /** @param array<string,mixed> $node
     * @return list<string>
     */
    public function ids(array $node): array
    {
        $ids = ($node['type'] ?? '') === 'mediaFigure' && isset($node['attrs']['mediaId']) ? [$node['attrs']['mediaId']] : [];
        foreach ($node['content'] ?? [] as $child) {
            $ids = array_merge($ids, $this->ids($child));
        }

        return array_values(array_unique($ids));
    }

    /** @param array<string,mixed> $node
     * @param  list<string>  $allowed
     * @return array<string,mixed>
     */
    private function node(array $node, int $depth, array $allowed): array
    {
        $this->ensure(++$this->nodes <= 5000 && $depth <= 24, 'The document has too many blocks or nesting levels.');
        $type = $node['type'] ?? '';
        $this->ensure(is_string($type) && in_array($type, $allowed, true), 'Unsupported content block.');
        $attrs = $node['attrs'] ?? [];
        $this->ensure(is_array($attrs), 'Invalid block attributes.');
        $out = ['type' => $type];
        if ($type === 'text') {
            $out['text'] = $this->text($node['text'] ?? null, 100000);
            $this->ensure($out['text'] !== '', 'Empty text nodes are not supported.');
            $marks = $node['marks'] ?? [];
            $this->ensure(is_array($marks) && array_is_list($marks) && count($marks) <= 12, 'Invalid text formatting.');
            if ($marks) {
                $types = array_map(fn ($mark) => is_array($mark) ? ($mark['type'] ?? null) : null, $marks);
                $this->ensure(count(array_unique($types, SORT_REGULAR)) === count($types), 'Duplicate text formatting is not supported.');
                $out['marks'] = array_map(fn ($mark) => $this->mark($mark), $marks);
            }

            return $out;
        }
        if (in_array($type, ['paragraph', 'heading'], true)) {
            $align = $attrs['textAlign'] ?? 'left';
            $this->ensure(in_array($align, ['left', 'center', 'right', 'justify', null], true), 'Invalid text alignment.');
            $out['attrs']['textAlign'] = $align ?: 'left';
            if ($type === 'heading') {
                $level = $attrs['level'] ?? 2;
                $this->ensure(in_array($level, [2, 3, 4], true), 'Choose heading level 2, 3 or 4.');
                $out['attrs']['level'] = $level;
            }
        }
        if ($type === 'orderedList') {
            $start = $attrs['start'] ?? 1;
            $this->ensure(is_int($start) && $start >= 1 && $start <= 10000, 'Invalid list start.');
            $out['attrs']['start'] = $start;
        }
        if (in_array($type, ['tableCell', 'tableHeader'], true)) {
            foreach (['colspan', 'rowspan'] as $key) {
                $value = $attrs[$key] ?? 1;
                $this->ensure(is_int($value) && $value >= 1 && $value <= 20, 'Invalid table span.');
                $out['attrs'][$key] = $value;
            }
        }
        if ($type === 'mediaFigure') {
            $out['attrs'] = [
                'mediaId' => $this->text($attrs['mediaId'] ?? null, 26),
                'alt' => $this->text($attrs['alt'] ?? null, 255),
                'caption' => $this->text($attrs['caption'] ?? '', 1000),
                'credit' => $this->text($attrs['credit'] ?? '', 255),
                'layout' => $attrs['layout'] ?? 'landscape',
            ];
            $this->ensure(trim($out['attrs']['alt']) !== '' && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $out['attrs']['mediaId']) === 1, 'Select an image and add descriptive alt text.');
            $this->ensure(in_array($out['attrs']['layout'], ['landscape', 'original'], true), 'Invalid image layout.');
        }
        $children = $node['content'] ?? [];
        $this->ensure(is_array($children) && array_is_list($children), 'Invalid block content.');
        $childTypes = match ($type) {
            'doc', 'blockquote', 'listItem' => self::BLOCKS,
            'paragraph', 'heading' => ['text', 'hardBreak'],
            'codeBlock' => ['text'],
            'bulletList', 'orderedList' => ['listItem'],
            'table' => ['tableRow'],
            'tableRow' => ['tableCell', 'tableHeader'],
            'tableCell', 'tableHeader' => array_values(array_diff(self::BLOCKS, ['table'])),
            default => [],
        };
        if (! $childTypes) {
            $this->ensure($children === [], 'This block cannot contain other blocks.');
        } else {
            $out['content'] = [];
            foreach ($children as $child) {
                $this->ensure(is_array($child), 'Invalid content node.');
                $out['content'][] = $this->node($child, $depth + 1, $childTypes);
            }
            if (in_array($type, ['table', 'tableRow', 'tableCell', 'tableHeader', 'listItem', 'bulletList', 'orderedList'], true)) {
                $this->ensure($children !== [], 'Tables and lists must contain content.');
            }
            if ($type === 'listItem') {
                $this->ensure(($out['content'][0]['type'] ?? '') === 'paragraph', 'A list item must begin with a paragraph.');
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function mark(mixed $mark): array
    {
        $this->ensure(is_array($mark), 'Invalid text formatting.');
        $type = $mark['type'] ?? '';
        $this->ensure(in_array($type, ['bold', 'italic', 'underline', 'strike', 'code', 'subscript', 'superscript', 'link', 'textStyle', 'highlight'], true), 'Unsupported text formatting.');
        $out = ['type' => $type];
        $attrs = $mark['attrs'] ?? [];
        $this->ensure(is_array($attrs), 'Invalid formatting attributes.');
        if ($type === 'link') {
            $href = $this->text($attrs['href'] ?? '', 2000);
            $this->ensure(preg_match('~^(https?://|mailto:|tel:|/(?!/)|\#)~i', $href) === 1 && ! preg_match('/[\x00-\x20\\\\]/', $href), 'Use a valid http, https, email, phone or internal link.');
            $out['attrs'] = ['href' => $href];
        }
        if ($type === 'textStyle') {
            foreach (['fontFamily', 'fontSize', 'color', 'backgroundColor', 'lineHeight'] as $key) {
                if (! isset($attrs[$key]) || $attrs[$key] === '') {
                    continue;
                }
                $value = $this->text($attrs[$key], 100);
                if ($key === 'fontFamily') {
                    $value = trim($value, "\"'");
                    $this->ensure(in_array($value, self::FONTS, true), 'Choose a supported font.');
                } elseif ($key === 'fontSize') {
                    $this->ensure(in_array($value, ['12px', '14px', '16px', '18px', '19px', '20px', '22px', '24px', '28px', '32px', '36px', '40px', '48px'], true), 'Choose a supported font size.');
                } elseif ($key === 'lineHeight') {
                    $this->ensure(in_array($value, ['1.2', '1.5', '1.7', '2', '2.4'], true), 'Choose a supported line height.');
                } else {
                    $value = $this->color($value);
                }
                $out['attrs'][$key] = $value;
            }
        }
        if ($type === 'highlight') {
            $out['attrs']['color'] = $this->color($attrs['color'] ?? '#fff2a8');
        }

        return $out;
    }

    private function color(mixed $value): string
    {
        $this->ensure(is_string($value), 'Invalid colour.');
        if (preg_match('/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/', $value, $m)) {
            $this->ensure(max((int) $m[1], (int) $m[2], (int) $m[3]) <= 255, 'Invalid colour.');
            $value = sprintf('#%02x%02x%02x', $m[1], $m[2], $m[3]);
        }
        $this->ensure(preg_match('/^#[0-9a-f]{6}$/i', $value) === 1, 'Choose a six-digit hex colour.');

        return strtolower($value);
    }

    private function text(mixed $value, int $max): string
    {
        $this->ensure(is_string($value) && mb_strlen($value) <= $max, 'Text is missing or exceeds its limit.');

        return $value;
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['bodyDocument' => [$message]]);
        }
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string,mixed> $node
     * @param  array<string,Media>  $media
     */
    private function html(array $node, array $media): string
    {
        $type = $node['type'];
        $attrs = $node['attrs'] ?? [];
        if ($type === 'text') {
            $html = $this->escape($node['text']);
            foreach ($node['marks'] ?? [] as $mark) {
                $a = $mark['attrs'] ?? [];
                $tag = ['bold' => 'strong', 'italic' => 'em', 'underline' => 'u', 'strike' => 's', 'code' => 'code', 'subscript' => 'sub', 'superscript' => 'sup'][$mark['type']] ?? 'span';
                if ($mark['type'] === 'link') {
                    $html = '<a href="'.$this->escape($a['href']).'" rel="noopener noreferrer">'.$html.'</a>';
                } elseif (in_array($mark['type'], ['textStyle', 'highlight'], true)) {
                    $styles = [];
                    foreach ($a as $key => $value) {
                        $property = ['fontFamily' => 'font-family', 'fontSize' => 'font-size', 'color' => 'color', 'backgroundColor' => 'background-color', 'lineHeight' => 'line-height'][$key];
                        if ($mark['type'] === 'highlight') {
                            $property = 'background-color';
                        }
                        $styles[] = $property.':'.$value;
                    }
                    $html = '<span style="'.$this->escape(implode(';', $styles)).'">'.$html.'</span>';
                } else {
                    $html = '<'.$tag.'>'.$html.'</'.$tag.'>';
                }
            }

            return $html;
        }
        if ($type === 'mediaFigure') {
            $item = $media[$attrs['mediaId']];
            $size = $item->width && $item->height ? ' width="'.$item->width.'" height="'.$item->height.'"' : '';
            $caption = $attrs['caption'] !== '' ? '<span>'.$this->escape($attrs['caption']).'</span>' : '';
            $caption .= $attrs['credit'] !== '' ? '<span class="article-image-credit">'.$this->escape($attrs['credit']).'</span>' : '';

            return '<figure data-media-id="'.$item->id.'" data-layout="'.$attrs['layout'].'"><img src="'.$this->escape(Storage::disk('public')->url($item->path)).'" alt="'.$this->escape($attrs['alt']).'"'.$size.' loading="lazy" decoding="async">'.($caption !== '' ? '<figcaption>'.$caption.'</figcaption>' : '').'</figure>';
        }
        $inner = implode('', array_map(fn ($child) => $this->html($child, $media), $node['content'] ?? []));
        if ($type === 'doc') {
            return $inner;
        }
        if ($type === 'hardBreak') {
            return '<br>';
        }
        if ($type === 'horizontalRule') {
            return '<hr>';
        }
        if ($type === 'codeBlock') {
            return '<pre><code>'.$inner.'</code></pre>';
        }
        $tag = ['paragraph' => 'p', 'heading' => 'h'.($attrs['level'] ?? 2), 'blockquote' => 'blockquote', 'bulletList' => 'ul', 'orderedList' => 'ol', 'listItem' => 'li', 'table' => 'table', 'tableRow' => 'tr', 'tableCell' => 'td', 'tableHeader' => 'th'][$type];
        $extra = isset($attrs['textAlign']) ? ' style="text-align:'.$attrs['textAlign'].'"' : '';
        $extra .= $type === 'orderedList' ? ' start="'.$attrs['start'].'"' : '';
        if (in_array($type, ['tableCell', 'tableHeader'], true)) {
            $extra .= ' colspan="'.$attrs['colspan'].'" rowspan="'.$attrs['rowspan'].'"';
        }
        $html = '<'.$tag.$extra.'>'.$inner.'</'.$tag.'>';

        return $type === 'table' ? '<div class="article-table" role="region" aria-label="Article table" tabindex="0">'.$html.'</div>' : $html;
    }
}
