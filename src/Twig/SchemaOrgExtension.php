<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Twig;

use Survos\SchemaOrgBundle\Renderer\SchemaOrgRenderer;
use Twig\Attribute\AsTwigFunction;

/**
 * Renders the collected graph into the page.
 *
 * Inspecting what was collected is the profiler's job — see
 * {@see \Survos\SchemaOrgBundle\DataCollector\SchemaOrgCollector}, which puts a
 * node count in the toolbar and the whole graph in a panel.
 */
final readonly class SchemaOrgExtension
{
    public function __construct(
        private SchemaOrgRenderer $renderer,
    ) {
    }

    /**
     * The whole graph as one `<script type="application/ld+json">` tag, or '' when
     * the page collected nothing — so it is safe to call unconditionally from
     * base.html.twig.
     *
     * Pass $nonce when the app's CSP requires one for inline scripts.
     */
    #[AsTwigFunction('render_schema_org', isSafe: ['html'])]
    public function render(?string $nonce = null): string
    {
        return $this->renderer->scriptTag($nonce);
    }
}
