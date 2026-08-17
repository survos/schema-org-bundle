<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Tests\Fixture;

use Survos\SchemaOrgBundle\Attribute\SchemaOrg;
use Survos\SchemaOrgBundle\Attribute\SchemaProperty;

/** A mistake the mapper must refuse rather than quietly ignore. */
#[SchemaOrg('Thing')]
final class PrivatelyMappedThing
{
    #[SchemaProperty('name')]
    private ?string $name = 'hidden';
}
