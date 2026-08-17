<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Tests\Fixture;

use Survos\SchemaOrgBundle\Attribute\SchemaOrg;

/** Narrows the inherited VisualArtwork to Painting: nearest declaration wins. */
#[SchemaOrg('Painting')]
final class PaintingDtoFixture extends ArtworkDtoFixture
{
}
