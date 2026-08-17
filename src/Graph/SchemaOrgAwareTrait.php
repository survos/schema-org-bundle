<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Graph;

use Symfony\Contracts\Service\Attribute\Required;

/**
 * Graph access without touching the constructor — for controllers extending a
 * base class, or classes whose constructor signature is awkward to change.
 *
 *     final class MovieController extends AbstractController
 *     {
 *         use SchemaOrgAwareTrait;
 *
 *         public function show(Movie $movie): Response
 *         {
 *             $this->schemaOrg->add(Schema::movie()->name($movie->getTitle()));
 *             // ...
 *         }
 *     }
 *
 * If you're editing the constructor anyway, inject SchemaOrgGraph normally
 * instead — this trait exists for the case where you'd rather not.
 */
trait SchemaOrgAwareTrait
{
    #[Required]
    public SchemaOrgGraph $schemaOrg;
}
