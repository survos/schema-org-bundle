<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\EventListener;

use Survos\SchemaOrgBundle\Graph\SchemaOrgGraph;
use Survos\SchemaOrgBundle\Renderer\SchemaOrgRenderer;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Injects the JSON-LD before `</head>` without the app touching a template.
 *
 * Opt-in (survos_schema_org.auto_inject), and off by default: an explicit
 * `{{ render_schema_org() }}` is greppable, while injected output appears from
 * nowhere. This exists for apps whose layout we don't own or don't want to edit —
 * and, unlike hooking a layout bundle's Twig block, it works whatever the layout
 * is, and cannot be silently disabled by a template overriding a block without
 * calling parent().
 *
 * Deliberately does NOT inject the debug panel. That's a dev aid you place
 * yourself; this listener's job is the semantic payload only.
 *
 * Low priority so it runs after listeners that build or replace the response body.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onKernelResponse', priority: -1024)]
final readonly class SchemaOrgAutoInjectListener
{
    public function __construct(
        private SchemaOrgGraph $schemaOrg,
        private SchemaOrgRenderer $renderer,
    ) {
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();

        if (!$this->isInjectable($response)) {
            return;
        }

        $content = $response->getContent();
        if (false === $content) {
            return;
        }

        // First </head>, not the last: a page that mentions the string in its body
        // must not move the injection point out of the real head.
        $position = stripos($content, '</head>');
        if (false === $position) {
            return;
        }

        $scriptTag = $this->renderer->scriptTag();
        if ('' === $scriptTag) {
            return;
        }

        $response->setContent(substr($content, 0, $position) . $scriptTag . substr($content, $position));

        // The body grew, so a Content-Length set by an earlier listener is now a lie
        // and would truncate the page. Drop it and let the server recompute.
        $response->headers->remove('Content-Length');
    }

    private function isInjectable(Response $response): bool
    {
        // Already emitted by an explicit render_schema_org() in the template — the
        // whole reason SchemaOrgGraph tracks this. Injecting anyway would give the
        // page two @graph blocks describing the same thing.
        if ($this->schemaOrg->isRendered() || $this->schemaOrg->isEmpty()) {
            return false;
        }

        // Streamed and file responses have no content string to rewrite; attempting it
        // either no-ops or buffers a download into memory.
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        // Already-compressed bodies are opaque, and a download isn't a page.
        if ($response->headers->has('Content-Encoding')
            || str_contains((string) $response->headers->get('Content-Disposition'), 'attachment')) {
            return false;
        }

        if (!str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html')) {
            return false;
        }

        // Redirects and 204s have no meaningful head to inject into.
        return $response->isSuccessful();
    }
}
