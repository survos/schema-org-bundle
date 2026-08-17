<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Tests\Fixture;

use Survos\SchemaOrgBundle\Attribute\SchemaOrg;
use Survos\SchemaOrgBundle\Attribute\SchemaProperty;

/** Constructor-promoted + private(set) + readonly, as survos-sites/packages does it. */
#[SchemaOrg('Movie')]
final class AsymmetricMovie
{
    public function __construct(
        #[SchemaProperty('name')]
        private(set) readonly ?string $title = null,
    ) {
    }
}
