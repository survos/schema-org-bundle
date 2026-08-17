<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\EventListener;

use Survos\SchemaOrgBundle\Graph\SchemaOrgGraph;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Empties the graph at the start of every main request.
 *
 * Under php-fpm this is a no-op — the container is rebuilt per request anyway.
 * It matters under FrankenPHP worker mode / RoadRunner, where SchemaOrgGraph is
 * a long-lived service and one page's nodes would otherwise show up in the JSON-LD
 * of the next.
 *
 * Priority is deliberately above every routing/firewall listener so nothing can
 * contribute nodes for the new request before the old ones are cleared.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 4096)]
final readonly class SchemaOrgResetListener
{
    public function __construct(
        private SchemaOrgGraph $schemaOrg,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->schemaOrg->reset();
    }
}
