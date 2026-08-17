<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Tests\Fixture;

use Survos\SchemaOrgBundle\Attribute\SchemaProperty;

/**
 * Mirrors data-contracts' AbstractEntityDto: carries the shared properties and no
 * #[SchemaOrg] of its own, because "what type is this" is a subclass's decision.
 */
abstract class BaseThingDto
{
    #[SchemaProperty('name')]
    public ?string $title = null;

    #[SchemaProperty('description')]
    public ?string $summary = null;
}
