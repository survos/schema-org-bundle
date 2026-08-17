<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Tests\Fixture;

/**
 * Adds nothing at all — exactly data-contracts' DrawingDto, which extends ArtworkDto
 * and declares only a contentType(). Exists to prove the type is inherited.
 */
final class DrawingDtoFixture extends ArtworkDtoFixture
{
}
