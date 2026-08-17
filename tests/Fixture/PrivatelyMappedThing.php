<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Tests\Fixture;

use Survos\SchemaOrgBundle\Attribute\SchemaOrg;
use Survos\SchemaOrgBundle\Attribute\SchemaProperty;

/**
 * The realistic version of the mistake: a private field with a public getter, and
 * the attribute put on the field instead of the getter. The mapper must refuse it
 * rather than quietly emit a Thing with no name.
 */
#[SchemaOrg('Thing')]
final class PrivatelyMappedThing
{
    #[SchemaProperty('name')]
    private string $name = 'hidden';

    public function getName(): string
    {
        return $this->name;
    }
}
