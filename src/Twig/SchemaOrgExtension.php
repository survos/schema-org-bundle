<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Twig;

use Survos\SchemaOrgBundle\Graph\SchemaOrgGraph;
use Twig\Attribute\AsTwigFunction;

/**
 * Renders the collected graph. Both functions are safe to call unconditionally
 * from base.html.twig: they return '' when nothing was collected.
 */
final readonly class SchemaOrgExtension
{
    public function __construct(
        private SchemaOrgGraph $schemaOrg,
        private bool $prettyPrint,
        private bool $debugPanel,
    ) {
    }

    /**
     * The whole graph as one `<script type="application/ld+json">` tag.
     *
     * Pass $nonce when the app's CSP requires one for inline scripts.
     */
    #[AsTwigFunction('render_schema_org', isSafe: ['html'])]
    public function render(?string $nonce = null): string
    {
        if ($this->schemaOrg->isEmpty()) {
            return '';
        }

        $nonceAttr = null !== $nonce
            ? \sprintf(' nonce="%s"', htmlspecialchars($nonce, \ENT_QUOTES, 'UTF-8'))
            : '';

        return \sprintf(
            '<script type="application/ld+json"%s>%s</script>',
            $nonceAttr,
            $this->encode($this->prettyPrint),
        );
    }

    /**
     * A collapsed panel, pinned bottom-right, showing what the page actually
     * collected — so you can check the graph without reading view-source.
     * Returns '' unless survos_schema_org.debug_panel is on.
     */
    #[AsTwigFunction('schema_org_debug', isSafe: ['html'])]
    public function debugPanel(): string
    {
        if (!$this->debugPanel || $this->schemaOrg->isEmpty()) {
            return '';
        }

        $count = $this->schemaOrg->count();
        $label = \sprintf('Schema.org graph (%d node%s)', $count, 1 === $count ? '' : 's');
        $json = htmlspecialchars($this->encode(pretty: true), \ENT_QUOTES, 'UTF-8');

        return <<<HTML
            <details style="position:fixed;bottom:0;right:0;z-index:2147483647;max-width:480px;max-height:60vh;overflow:auto;background:#1e1e1e;color:#d4d4d4;font:12px/1.4 ui-monospace,monospace;border-top-left-radius:6px;box-shadow:0 0 12px rgba(0,0,0,.4);">
                <summary style="cursor:pointer;padding:6px 10px;background:#333;user-select:none;">{$label}</summary>
                <pre style="margin:0;padding:10px;white-space:pre-wrap;word-break:break-word;">{$json}</pre>
            </details>
            HTML;
    }

    /**
     * JSON_HEX_TAG is the load-bearing flag, not a nicety: without it a node
     * carrying "</script>" in a title or description closes the tag early and the
     * rest of the JSON lands in the DOM as markup. It escapes the angle brackets
     * as JSON unicode escapes, which parsers read back as the original
     * characters — the same protection spatie's own toScript() applies.
     */
    private function encode(bool $pretty): string
    {
        $flags = \JSON_HEX_TAG | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= \JSON_PRETTY_PRINT;
        }

        return json_encode($this->schemaOrg->toArray(), $flags);
    }
}
