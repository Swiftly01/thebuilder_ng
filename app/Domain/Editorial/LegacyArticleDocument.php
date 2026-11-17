<?php

declare(strict_types=1);

namespace App\Domain\Editorial;

use App\Models\Media;
use DOMElement;
use DOMNode;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Read-only import. Unsupported markup is reported instead of silently discarded. */
final class LegacyArticleDocument
{
    /** @return array<string,mixed> */
    public function convert(string $html): array
    {
        $dom = new \DOMDocument;
        $prior = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="utf-8" ?><html><body>'.$html.'</body></html>', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prior);
        }
        $body = $dom->getElementsByTagName('body')->item(0);
        $content = [];
        foreach ($body->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                if (trim($child->textContent) !== '') {
                    $content[] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $child->textContent]]];
                }
            } else {
                $content = array_merge($content, $this->block($child));
            }
        }
        $prepared = app(ArticleDocument::class)->prepare(['type' => 'doc', 'content' => $content]);

        return $prepared['document'];
    }

    /** @return list<array<string,mixed>> */
    private function block(DOMNode $node): array
    {
        if (! $node instanceof DOMElement) {
            return [];
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, ['div', 'tbody', 'thead', 'tfoot'], true)) {
            $result = [];
            foreach ($node->childNodes as $child) {
                if ($child->nodeType === XML_TEXT_NODE && trim($child->textContent) !== '') {
                    $result[] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $child->textContent]]];
                } else {
                    $result = array_merge($result, $this->block($child));
                }
            }

            return $result;
        }
        if (in_array($tag, ['img', 'figure'], true)) {
            $image = $tag === 'img' ? $node : $node->getElementsByTagName('img')->item(0);
            $src = $image?->getAttribute('src') ?? '';
            $base = Storage::disk('public')->url('');
            $path = str_starts_with($src, $base) ? substr($src, strlen($base)) : (str_starts_with($src, '/storage/') ? substr($src, 9) : null);
            $media = $path ? Media::where('path', rawurldecode(ltrim($path, '/')))->first() : null;
            if (! $media) {
                $this->unsupported('An existing image is outside the media library. Import it into Media before converting this article.');
            }

            return [['type' => 'mediaFigure', 'attrs' => ['mediaId' => $media->id, 'alt' => $image->getAttribute('alt') ?: $media->alt, 'caption' => $tag === 'figure' ? ($node->getElementsByTagName('figcaption')->item(0)->textContent ?? '') : '', 'credit' => $media->credit ?? '', 'layout' => 'original']]];
        }
        $type = ['p' => 'paragraph', 'h2' => 'heading', 'h3' => 'heading', 'h4' => 'heading', 'blockquote' => 'blockquote', 'ul' => 'bulletList', 'ol' => 'orderedList', 'li' => 'listItem', 'table' => 'table', 'tr' => 'tableRow', 'th' => 'tableHeader', 'td' => 'tableCell', 'pre' => 'codeBlock', 'hr' => 'horizontalRule'][$tag] ?? null;
        if (! $type) {
            $this->unsupported('Unsupported existing block: '.$tag.'. The saved HTML has not been changed.');
        }
        $out = ['type' => $type];
        if ($type === 'heading') {
            $out['attrs']['level'] = (int) substr($tag, 1);
        }
        if (in_array($type, ['tableCell', 'tableHeader'], true)) {
            $out['attrs'] = ['colspan' => (int) ($node->getAttribute('colspan') ?: 1), 'rowspan' => (int) ($node->getAttribute('rowspan') ?: 1)];
        }
        if ($type === 'orderedList') {
            $out['attrs']['start'] = (int) ($node->getAttribute('start') ?: 1);
        }
        if ($type === 'horizontalRule') {
            return [$out];
        }
        if (in_array($type, ['paragraph', 'heading'], true)) {
            $out['content'] = $this->inlineChildren($node);
        } elseif ($type === 'codeBlock') {
            $out['content'] = $node->textContent !== '' ? [['type' => 'text', 'text' => $node->textContent]] : [];
        } else {
            $out['content'] = [];
            $inline = [];
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['p', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote', 'table', 'tbody', 'thead', 'tfoot', 'tr', 'th', 'td', 'figure', 'img', 'pre', 'hr'], true)) {
                    if ($inline) {
                        $out['content'][] = ['type' => 'paragraph', 'content' => $inline];
                        $inline = [];
                    }
                    $out['content'] = array_merge($out['content'], $this->block($child));
                } elseif (trim($child->textContent) !== '') {
                    $inline = array_merge($inline, $this->inline($child));
                }
            }
            if ($inline || ! $out['content']) {
                $out['content'][] = ['type' => 'paragraph', 'content' => $inline];
            }
        }

        return [$out];
    }

    /** @param list<array<string,mixed>> $marks
     * @return list<array<string,mixed>>
     */
    private function inlineChildren(DOMNode $node, array $marks = []): array
    {
        $result = [];
        foreach ($node->childNodes as $child) {
            $result = array_merge($result, $this->inline($child, $marks));
        }

        return $result;
    }

    /** @param list<array<string,mixed>> $marks
     * @return list<array<string,mixed>>
     */
    private function inline(DOMNode $node, array $marks = []): array
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return $node->textContent !== '' ? [['type' => 'text', 'text' => $node->textContent, 'marks' => $marks]] : [];
        }
        if (! $node instanceof DOMElement) {
            return [];
        }
        $tag = strtolower($node->tagName);
        if ($tag === 'br') {
            return [['type' => 'hardBreak']];
        }
        $type = ['strong' => 'bold', 'b' => 'bold', 'em' => 'italic', 'i' => 'italic', 'u' => 'underline', 's' => 'strike', 'del' => 'strike', 'code' => 'code', 'sub' => 'subscript', 'sup' => 'superscript', 'a' => 'link'][$tag] ?? null;
        if (! $type) {
            $this->unsupported('Unsupported inline formatting: '.$tag.'. The saved HTML has not been changed.');
        }
        $marks[] = ['type' => $type] + ($tag === 'a' ? ['attrs' => ['href' => $node->getAttribute('href')]] : []);

        return $this->inlineChildren($node, $marks);
    }

    private function unsupported(string $message): never
    {
        throw ValidationException::withMessages(['body' => [$message]]);
    }
}
