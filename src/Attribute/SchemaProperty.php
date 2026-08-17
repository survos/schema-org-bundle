<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Attribute;

/**
 * Maps one property (or zero-argument getter) to a Schema.org property.
 *
 *     #[SchemaProperty('name')]        public ?string $title = null;
 *     #[SchemaProperty('genre')]       public ?array $genres = null;
 *     #[SchemaProperty('dateCreated')] public ?int $year = null;
 *
 * Deliberately orthogonal to #[Field] from survos/field-bundle: that describes how
 * a property is exposed in grids, search, and the API; this describes what it
 * means in JSON-LD. They are different axes and coexist on the same property.
 *
 * Null and empty-string values are always skipped — an absent property is correct
 * JSON-LD, whereas "name": null is not. Empty arrays are skipped too.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final readonly class SchemaProperty
{
    /**
     * @param string      $name  the Schema.org property ('name', 'dateCreated', 'director')
     * @param string|null $as    wrap the value in this Schema.org type instead of using it
     *                           raw — a plain string becomes that type's `name`. Use for the
     *                           very common "the column holds a person's name, not a Person"
     *                           case: #[SchemaProperty('director', as: 'Person')].
     * @param bool        $reference when $as is set, add the wrapped node to the graph as its
     *                           own top-level node and link it by @id, instead of nesting a
     *                           copy inside this one. Defaults true: that is what makes a
     *                           graph a graph, and it is how a Person shared by two movies
     *                           stays one node.
     * @param string|null $idPattern @id for the wrapped node, with {value} replaced by the
     *                           url-encoded lowercased value and {base} by the mapper's base
     *                           URL — e.g. '{base}/people/{value}'. Without it the wrapped
     *                           node is anonymous, which means it cannot be deduplicated.
     */
    public function __construct(
        public string $name,
        public ?string $as = null,
        public bool $reference = true,
        public ?string $idPattern = null,
    ) {
    }
}
