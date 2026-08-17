<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Attribute;

/**
 * Marks a class as mappable to a Schema.org type.
 *
 *     #[SchemaOrg('Movie')]
 *     final class Movie { ... }
 *
 * The type is the Schema.org type name ('Movie', 'MusicComposition',
 * 'ArchiveComponent'), which SchemaOrgMapper resolves to the spatie class.
 * Passing the spatie class directly (Spatie\SchemaOrg\Movie::class) also works and
 * is what static analysis can check.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class SchemaOrg
{
    public function __construct(
        public string $type,
    ) {
    }
}
