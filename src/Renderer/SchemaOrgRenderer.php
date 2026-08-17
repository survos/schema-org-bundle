<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Renderer;

use Survos\SchemaOrgBundle\Graph\SchemaOrgGraph;

/**
 * Turns the collected graph into a JSON-LD script tag.
 *
 * Single owner of the encoding, because one of the flags is security-relevant and
 * must not drift between the two callers (the Twig function and the auto-inject
 * response listener) — see {@see json()}.
 */
final readonly class SchemaOrgRenderer
{
    public function __construct(
        private SchemaOrgGraph $schemaOrg,
        private bool $prettyPrint,
    ) {
    }

    /**
     * The full `<script type="application/ld+json">` tag, or '' when the page
     * collected nothing — so callers never have to check first.
     *
     * Marks the graph as rendered, which is how the auto-inject listener knows not
     * to emit a second tag for a template that already called render_schema_org().
     */
    public function scriptTag(?string $nonce = null): string
    {
        if ($this->schemaOrg->isEmpty()) {
            return '';
        }

        $this->schemaOrg->markRendered();

        $nonceAttr = null !== $nonce
            ? \sprintf(' nonce="%s"', htmlspecialchars($nonce, \ENT_QUOTES, 'UTF-8'))
            : '';

        return \sprintf(
            '<script type="application/ld+json"%s>%s</script>',
            $nonceAttr,
            $this->json($this->prettyPrint),
        );
    }

    /**
     * JSON_HEX_TAG is the load-bearing flag, not a nicety: without it a node
     * carrying "</script>" in a title or description closes the tag early and the
     * rest of the JSON lands in the DOM as markup. It escapes the angle brackets as
     * JSON unicode escapes, which parsers read back as the original characters —
     * the same protection spatie's own toScript() applies.
     */
    public function json(bool $pretty): string
    {
        $flags = \JSON_HEX_TAG | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= \JSON_PRETTY_PRINT;
        }

        return json_encode($this->schemaOrg->toArray(), $flags);
    }
}
