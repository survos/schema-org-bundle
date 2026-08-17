<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Mapping;

use Spatie\SchemaOrg\BaseType;

/**
 * The resolved #[SchemaOrg] + #[SchemaProperty] metadata for one class.
 *
 * @see SchemaOrgMetadataFactory which builds and caches these
 */
final readonly class ClassMapping
{
    /**
     * @param class-string<BaseType> $nodeClass  the spatie class to instantiate
     * @param list<PropertyMapping> $properties in declaration order
     */
    public function __construct(
        public string $nodeClass,
        public array $properties,
    ) {
    }
}
