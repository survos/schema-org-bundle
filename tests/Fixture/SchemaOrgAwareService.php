<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Tests\Fixture;

use Survos\SchemaOrgBundle\Graph\SchemaOrgAwareTrait;

/** A consumer with no constructor at all — the case SchemaOrgAwareTrait exists for. */
final class SchemaOrgAwareService
{
    use SchemaOrgAwareTrait;
}
