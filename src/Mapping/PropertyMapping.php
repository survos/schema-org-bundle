<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Mapping;

use Spatie\SchemaOrg\BaseType;

/**
 * One resolved property mapping: how to read a value off the entity, and what to
 * do with it. Produced once per class by SchemaOrgMetadataFactory and cached.
 */
final readonly class PropertyMapping
{
    /**
     * @param string                  $schemaProperty the Schema.org property name
     * @param \Closure(object): mixed $accessor       reads the value off the entity
     * @param class-string<BaseType>|null $wrapIn         spatie class to wrap the value in
     */
    public function __construct(
        public string $schemaProperty,
        public \Closure $accessor,
        public ?string $wrapIn = null,
        public bool $reference = true,
        public ?string $idPattern = null,
    ) {
    }
}
