<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Graph;

use Spatie\SchemaOrg\BaseType;
use Spatie\SchemaOrg\Graph;
use Spatie\SchemaOrg\Type;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Request-scoped collector for Schema.org nodes.
 *
 * Any controller, listener, or service can contribute nodes (Organization,
 * Person, Movie, ArchiveComponent, ...) built with spatie/schema-org; the whole
 * lot is rendered once, as a single JSON-LD `@graph`, by `render_schema_org()`.
 *
 * This is a thin wrapper over {@see \Spatie\SchemaOrg\Graph}, which already does
 * the `@graph` envelope, per-node `@context` stripping, and keying by
 * (type, identifier). What it adds is the two things a Symfony service needs and
 * the upstream class does not have:
 *
 *  - **last write wins.** Spatie's `add()` throws TypeAlreadyInGraph on a repeat
 *    (type, identifier), which is wrong for a graph fed from several independent
 *    places — a site-wide Organization contributed by both a listener and a
 *    controller is normal, not an error. We use `set()` instead.
 *  - **an identifier derived from the node.** Spatie defaults every node to the
 *    identifier "default", so two distinct Persons silently overwrite each other.
 *    We key off the node's own `@id`/`identifier` when it has one, and give
 *    un-identified nodes a per-instance anonymous key so they accumulate.
 *
 * Reach through to {@see graph()} for the upstream API (hide/show, contexts).
 */
final class SchemaOrgGraph implements ResetInterface
{
    private Graph $graph;

    /** Monotonic key source for nodes with no @id; see identifierFor(). */
    private int $anonymousCount = 0;

    /**
     * Whether this request has already emitted its script tag.
     *
     * Request state about the graph, so it lives here and is cleared by reset()
     * alongside the nodes. It exists so auto-inject and an explicit
     * render_schema_org() in a template cannot both fire and produce two tags.
     */
    private bool $rendered = false;

    public function __construct()
    {
        $this->graph = new Graph();
    }

    /**
     * Adds (or replaces) a node.
     *
     * The identifier defaults to the node's own `@id`/`identifier` property, so
     * contributing the same `@id` twice updates one node rather than duplicating
     * it. Nodes without one are never deduplicated. Pass $identifier explicitly
     * to dedupe nodes that have no `@id` — e.g. `'current-page'`.
     */
    public function add(Type $node, ?string $identifier = null): static
    {
        $this->graph->set($node, $identifier ?? $this->identifierFor($node));

        return $this;
    }

    public function has(string $type, string $identifier = Graph::IDENTIFIER_DEFAULT): bool
    {
        return $this->graph->has($type, $identifier);
    }

    /**
     * Null rather than upstream's TypeNotInGraph exception, so callers can branch.
     *
     * @template T of Type
     *
     * @param class-string<T> $type
     *
     * @return T|null
     */
    public function get(string $type, string $identifier = Graph::IDENTIFIER_DEFAULT): ?Type
    {
        /** @var T|null */
        return $this->has($type, $identifier) ? $this->graph->get($type, $identifier) : null;
    }

    /**
     * The node of $type with $identifier, creating and adding an empty one if
     * absent — the "several places each fill in part of the same node" case.
     *
     * @template T of Type
     *
     * @param class-string<T> $type
     *
     * @return T
     */
    public function getOrCreate(string $type, string $identifier = Graph::IDENTIFIER_DEFAULT): Type
    {
        /** @var T */
        return $this->graph->getOrCreate($type, $identifier);
    }

    public function isEmpty(): bool
    {
        return 0 === $this->count();
    }

    public function count(): int
    {
        return array_sum(array_map(\count(...), $this->graph->getNodes()));
    }

    /**
     * The full JSON-LD document: one `@context`, one `@graph` of nodes.
     *
     * @return array{'@context': string|array<mixed>, '@graph': list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        /** @var array{'@context': string|array<mixed>, '@graph': list<array<string, mixed>>} */
        return $this->graph->toArray();
    }

    public function markRendered(): void
    {
        $this->rendered = true;
    }

    public function isRendered(): bool
    {
        return $this->rendered;
    }

    /** Escape hatch to the underlying spatie graph (hide/show, custom @context). */
    public function graph(): Graph
    {
        return $this->graph;
    }

    /**
     * Drops every collected node.
     *
     * Reached two ways, deliberately:
     *
     *  - ResetInterface + the kernel.reset tag, which Symfony invokes between
     *    requests under a long-running runtime (FrankenPHP worker mode,
     *    RoadRunner) AND between messages in a `messenger:consume` worker. The
     *    latter is why the interface matters: a consumer never fires
     *    kernel.request, so a listener alone would never fire for the whole life
     *    of the worker process.
     *  - {@see \Survos\SchemaOrgBundle\EventListener\SchemaOrgResetListener}, on
     *    kernel.request, so a request starts clean even if the previous one died
     *    before anything reset it.
     *
     * Both are idempotent, so doing both costs nothing.
     */
    public function reset(): void
    {
        $this->graph = new Graph();
        $this->anonymousCount = 0;
        $this->rendered = false;
    }

    /**
     * A node's own `@id`, falling back to a unique anonymous key.
     *
     * `identifier` is checked as well as `@id` because spatie's fluent builders
     * write the value there and only move it to `@id` at toArray() time
     * (BaseType::serializeIdentifier), so at add() time an identified node
     * usually has `identifier` set and `@id` not.
     */
    private function identifierFor(Type $node): string
    {
        if ($node instanceof BaseType) {
            foreach (['@id', 'identifier'] as $property) {
                $value = $node->getProperty($property);
                if (\is_string($value) && '' !== $value) {
                    return $value;
                }
            }
        }

        return '#anonymous-' . $this->anonymousCount++;
    }
}
