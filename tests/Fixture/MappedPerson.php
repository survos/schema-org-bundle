<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Tests\Fixture;

use Survos\SchemaOrgBundle\Attribute\SchemaOrg;
use Survos\SchemaOrgBundle\Attribute\SchemaProperty;

/**
 * Two of these pointing at each other is the recursion case: without a guard the
 * mapper would follow knows → knows → knows until it ran out of stack.
 */
#[SchemaOrg('Person')]
final class MappedPerson
{
    #[SchemaProperty('name')]
    public ?string $name = null;

    #[SchemaProperty('knows')]
    public ?MappedPerson $knows = null;

    public function __construct(?string $name = null)
    {
        $this->name = $name;
    }
}
