<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Tests\Fixture;

use Survos\SchemaOrgBundle\Attribute\SchemaOrg;
use Survos\SchemaOrgBundle\Attribute\SchemaProperty;

/** Modelled on bench's Movie: scalars, a list, and name-only person columns. */
#[SchemaOrg('Movie')]
final class MappedMovie
{
    #[SchemaProperty('name')]
    public ?string $title = null;

    #[SchemaProperty('description')]
    public ?string $overview = null;

    #[SchemaProperty('dateCreated')]
    public ?int $year = null;

    /** @var list<string>|null */
    #[SchemaProperty('genre')]
    public ?array $genres = null;

    #[SchemaProperty('director', as: 'Person', idPattern: '{base}/people/{value}')]
    public ?string $director = null;

    /** @var list<string>|null */
    #[SchemaProperty('actor', as: 'Person', idPattern: '{base}/people/{value}')]
    public ?array $actors = null;

    #[SchemaProperty('countryOfOrigin', as: 'Country', reference: false)]
    public ?string $country = null;

    /** A getter, not a property — the computed-value case. */
    #[SchemaProperty('alternateName')]
    public function getDisplayTitle(): ?string
    {
        return null === $this->title ? null : $this->title . ' (' . $this->year . ')';
    }
}
