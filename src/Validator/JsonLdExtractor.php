<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Validator;

/**
 * Pulls the contents of every `<script type="application/ld+json">` out of an HTML
 * document.
 *
 * Regex rather than a DOM parser on purpose: this has to work on whatever the
 * server actually sent, including markup a strict parser would reject, and the
 * pattern it needs to match is narrow and unambiguous.
 */
final readonly class JsonLdExtractor
{
    /** @return list<string> raw JSON, in document order */
    public function extract(string $html): array
    {
        if (!preg_match_all(
            '#<script[^>]*type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
            $html,
            $matches,
        )) {
            return [];
        }

        $blocks = [];
        foreach ($matches[1] as $block) {
            $block = trim($block);
            if ('' !== $block) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }
}
