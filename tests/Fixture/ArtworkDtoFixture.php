<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Tests\Fixture;

use Survos\SchemaOrgBundle\Attribute\SchemaOrg;
use Survos\SchemaOrgBundle\Attribute\SchemaProperty;

#[SchemaOrg('VisualArtwork')]
class ArtworkDtoFixture extends BaseThingDto
{
    #[SchemaProperty('artMedium')]
    public ?string $medium = null;
}
