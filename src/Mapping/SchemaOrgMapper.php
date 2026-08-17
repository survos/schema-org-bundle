<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Mapping;

use Spatie\SchemaOrg\BaseType;
use Survos\SchemaOrgBundle\Graph\SchemaOrgGraph;

/**
 * Builds spatie nodes from #[SchemaOrg]-annotated objects.
 *
 * Scope, deliberately: this maps the boring 80% — scalars, dates, lists, and
 * name-only relations — which is most of the bulk of a hand-written mapping. It
 * does NOT try to express cross-links, canonical-URL-derived @ids, or conditional
 * rules in attributes; that way lies a DSL. Map what's declarative, then keep
 * composing by hand:
 *
 *     $movie = $mapper->add($movie, $canonicalUrl . '#movie', $siteUrl);
 *     $movie->mainEntityOfPage($webPage->referenced());
 *
 * The node is returned, so the hand-written half is ordinary fluent spatie code.
 */
final readonly class SchemaOrgMapper
{
    public function __construct(
        private SchemaOrgMetadataFactory $metadata,
        private SchemaOrgGraph $schemaOrg,
    ) {
    }

    /**
     * Maps $entity and adds it to the request graph.
     *
     * @param string|null $id   the node's @id; without one it cannot be deduplicated
     *                          or referenced, which is usually not what you want for
     *                          the page's main entity
     * @param string      $base replaces {base} in a #[SchemaProperty] idPattern
     */
    public function add(object $entity, ?string $id = null, string $base = ''): BaseType
    {
        $node = $this->toNode($entity, $id, $base);
        $this->schemaOrg->add($node);

        return $node;
    }

    /** Maps $entity without touching the graph. */
    public function toNode(object $entity, ?string $id = null, string $base = ''): BaseType
    {
        return $this->build($entity, $id, $base, []);
    }

    /**
     * @param array<int, BaseType> $visited keyed by spl_object_id
     */
    private function build(object $entity, ?string $id, string $base, array $visited): BaseType
    {
        $class = $entity::class;
        $mapping = $this->metadata->getMapping($class);

        if (null === $mapping) {
            throw new \LogicException(\sprintf(
                '%s has no #[SchemaOrg] attribute, so there is nothing to map it to. '
                . 'Add #[SchemaOrg(\'SomeType\')] to the class, or build the node by hand.',
                $class,
            ));
        }

        $node = new $mapping->nodeClass();
        if (null !== $id && '' !== $id) {
            $node->setProperty('identifier', $id);
        }

        // Registered BEFORE the properties are walked, so a relation that points back
        // at this object finds the in-progress node instead of recursing for ever.
        $visited[spl_object_id($entity)] = $node;

        foreach ($mapping->properties as $property) {
            $value = ($property->accessor)($entity);
            $value = $this->convert($value, $property, $base, $visited);

            if (null === $value) {
                continue;
            }

            $node->setProperty($property->schemaProperty, $value);
        }

        return $node;
    }

    /**
     * @param array<int, BaseType> $visited
     *
     * @return mixed null means "skip this property"
     */
    private function convert(mixed $value, PropertyMapping $property, string $base, array $visited): mixed
    {
        if (null === $value) {
            return null;
        }

        if (\is_string($value)) {
            $value = trim($value);

            return '' === $value ? null : $this->wrap($value, $property, $base, $visited);
        }

        if ($value instanceof \BackedEnum) {
            return $this->wrap($value->value, $property, $base, $visited);
        }

        // DateTimeInterface is left alone: spatie serializes it to ATOM itself.
        if ($value instanceof \DateTimeInterface) {
            return $value;
        }

        if (is_iterable($value)) {
            $items = [];
            foreach ($value as $item) {
                $converted = $this->convert($item, $property, $base, $visited);
                if (null !== $converted) {
                    $items[] = $converted;
                }
            }

            // An empty array would emit "genre": [], which says "we know it has none"
            // rather than "we don't know" — skip instead.
            return [] === $items ? null : $items;
        }

        if (\is_object($value)) {
            return $this->wrap($value, $property, $base, $visited);
        }

        return $this->wrap($value, $property, $base, $visited);
    }

    /**
     * @param array<int, BaseType> $visited
     */
    private function wrap(mixed $value, PropertyMapping $property, string $base, array $visited): mixed
    {
        // An already-mapped object: recurse, or close the cycle.
        if (\is_object($value) && !$value instanceof BaseType && $this->metadata->supports($value::class)) {
            $existing = $visited[spl_object_id($value)] ?? null;

            if (null !== $existing) {
                return $this->closeCycle($existing);
            }

            return $this->emit($this->build($value, null, $base, $visited), $property);
        }

        if (null === $property->wrapIn) {
            return $value;
        }

        if (!\is_scalar($value)) {
            throw new \LogicException(\sprintf(
                'Cannot wrap a %s in %s for property "%s": #[SchemaProperty(as: ...)] turns a '
                . 'scalar into that type\'s name. Map the related class with #[SchemaOrg] instead.',
                get_debug_type($value),
                $property->wrapIn,
                $property->schemaProperty,
            ));
        }

        $node = new $property->wrapIn();
        $node->setProperty('name', (string) $value);

        if (null !== $property->idPattern) {
            $node->setProperty('identifier', strtr($property->idPattern, [
                '{base}' => rtrim($base, '/'),
                '{value}' => rawurlencode(mb_strtolower((string) $value)),
            ]));
        }

        return $this->emit($node, $property);
    }

    /**
     * Referenced nodes are contributed to the graph as their own top-level node and
     * linked by @id. That is what keeps one Person one node when two movies share
     * them — an embedded copy would appear once per referrer.
     */
    private function emit(BaseType $node, PropertyMapping $property): mixed
    {
        if (!$property->reference) {
            return $node;
        }

        $this->schemaOrg->add($node);

        return $this->reference($node);
    }

    private function reference(BaseType $node): mixed
    {
        // An anonymous node has no @id to point at, so a reference would serialize to
        // {"@id": null}. Embed it instead — worse for dedup, but not broken.
        return $this->hasIdentifier($node) ? $node->referenced() : $node;
    }

    /**
     * Closing a cycle (A knows B knows A) is the one case where embedding is not an
     * option: the node would contain itself, and toArray() would recurse until the
     * stack ran out. A reference by @id is the only valid encoding, so a node with no
     * @id can only be dropped — which is why an @id matters on anything reachable in
     * a cycle.
     */
    private function closeCycle(BaseType $node): mixed
    {
        return $this->hasIdentifier($node) ? $node->referenced() : null;
    }

    private function hasIdentifier(BaseType $node): bool
    {
        return null !== $node->getProperty('identifier') || null !== $node->getProperty('@id');
    }
}
