<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\DataCollector;

use Survos\SchemaOrgBundle\Graph\SchemaOrgGraph;
use Survos\SchemaOrgBundle\Renderer\SchemaOrgRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;

/**
 * Profiler integration: a toolbar item with the node count, and a panel listing
 * every node with the full JSON-LD.
 *
 * collect() runs on kernel.response, after controllers and listeners have
 * contributed but before SchemaOrgResetListener clears the graph for the next
 * request, so it sees exactly what the page published.
 */
final class SchemaOrgCollector extends AbstractDataCollector
{
    public function __construct(
        private readonly SchemaOrgGraph $schemaOrg,
        private readonly SchemaOrgRenderer $renderer,
    ) {
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $document = $this->schemaOrg->toArray();
        $nodes = $document['@graph'];

        $this->data = [
            'count' => \count($nodes),
            // Whether the JSON-LD actually reached the page. A page can collect nodes
            // and still publish none of them — no render_schema_org() in the layout,
            // auto_inject off — which looks identical to "it worked" without this.
            'rendered' => $this->schemaOrg->isRendered(),
            'context' => $document['@context'],
            'types' => $this->countTypes($nodes),
            'nodes' => array_map($this->summarise(...), $nodes),
            'json' => [] === $nodes ? '' : $this->renderer->json(pretty: true),
        ];
    }

    public function getCount(): int
    {
        return $this->data['count'] ?? 0;
    }

    public function isRendered(): bool
    {
        return $this->data['rendered'] ?? false;
    }

    public function getContext(): string
    {
        $context = $this->data['context'] ?? 'https://schema.org';

        return \is_string($context) ? $context : (json_encode($context) ?: '');
    }

    /** @return array<string, int> type name => node count, most frequent first */
    public function getTypes(): array
    {
        return $this->data['types'] ?? [];
    }

    /** @return list<array{type: string, id: string|null, label: string|null}> */
    public function getNodes(): array
    {
        return $this->data['nodes'] ?? [];
    }

    public function getJson(): string
    {
        return $this->data['json'] ?? '';
    }

    public static function getTemplate(): ?string
    {
        return '@SurvosSchemaOrg/data_collector/schema_org.html.twig';
    }

    /**
     * AbstractDataCollector defaults this to static::class, which becomes the panel's
     * URL segment — so without the override the panel lives at a url-encoded FQCN.
     * Matches the tag's id attribute, and elastic-bundle's convention.
     */
    public function getName(): string
    {
        return 'survos_schema_org';
    }

    /**
     * @param list<array<string, mixed>> $nodes
     *
     * @return array<string, int>
     */
    private function countTypes(array $nodes): array
    {
        $types = [];
        foreach ($nodes as $node) {
            $type = \is_string($node['@type'] ?? null) ? $node['@type'] : 'unknown';
            $types[$type] = ($types[$type] ?? 0) + 1;
        }
        arsort($types);

        return $types;
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array{type: string, id: string|null, label: string|null}
     */
    private function summarise(array $node): array
    {
        // name, then headline/title: enough to tell two nodes of the same type apart
        // at a glance without dumping the whole node into the list.
        $label = null;
        foreach (['name', 'headline', 'title'] as $property) {
            if (\is_string($node[$property] ?? null)) {
                $label = $node[$property];
                break;
            }
        }

        return [
            'type' => \is_string($node['@type'] ?? null) ? $node['@type'] : 'unknown',
            'id' => \is_string($node['@id'] ?? null) ? $node['@id'] : null,
            'label' => $label,
        ];
    }
}
