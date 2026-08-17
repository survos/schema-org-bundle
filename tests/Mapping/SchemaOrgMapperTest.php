<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Tests\Mapping;

use PHPUnit\Framework\TestCase;
use Spatie\SchemaOrg\Person;
use Survos\SchemaOrgBundle\Graph\SchemaOrgGraph;
use Survos\SchemaOrgBundle\Mapping\SchemaOrgMapper;
use Survos\SchemaOrgBundle\Mapping\SchemaOrgMetadataFactory;
use Survos\SchemaOrgBundle\Tests\Fixture\MappedMovie;
use Survos\SchemaOrgBundle\Tests\Fixture\MappedPerson;

final class SchemaOrgMapperTest extends TestCase
{
    private SchemaOrgGraph $graph;
    private SchemaOrgMapper $mapper;

    protected function setUp(): void
    {
        $this->graph = new SchemaOrgGraph();
        $this->mapper = new SchemaOrgMapper(new SchemaOrgMetadataFactory(), $this->graph);
    }

    public function testMapsScalarsAndLists(): void
    {
        $movie = new MappedMovie();
        $movie->title = '2001: A Space Odyssey';
        $movie->overview = 'A voyage.';
        $movie->year = 1968;
        $movie->genres = ['Science Fiction', 'Adventure'];

        $node = $this->mapper->toNode($movie, 'https://example.com/2001#movie')->toArray();

        self::assertSame('Movie', $node['@type']);
        self::assertSame('https://example.com/2001#movie', $node['@id']);
        self::assertSame('2001: A Space Odyssey', $node['name']);
        self::assertSame('A voyage.', $node['description']);
        self::assertSame(1968, $node['dateCreated']);
        self::assertSame(['Science Fiction', 'Adventure'], $node['genre']);
    }

    /**
     * Absent, not null: "name": null is not valid JSON-LD for a missing value, and an
     * empty list asserts "we know there are none" rather than "we don't know".
     */
    public function testNullEmptyStringAndEmptyListAreOmitted(): void
    {
        $movie = new MappedMovie();
        $movie->title = 'Solaris';
        $movie->overview = '   ';
        $movie->genres = [];

        $node = $this->mapper->toNode($movie)->toArray();

        self::assertArrayNotHasKey('description', $node);
        self::assertArrayNotHasKey('genre', $node);
        self::assertArrayNotHasKey('dateCreated', $node);
        self::assertArrayNotHasKey('@id', $node);
    }

    public function testGettersAreMappedToo(): void
    {
        $movie = new MappedMovie();
        $movie->title = 'Solaris';
        $movie->year = 1972;

        self::assertSame('Solaris (1972)', $this->mapper->toNode($movie)->toArray()['alternateName']);
    }

    public function testNameOnlyRelationBecomesAReferencedNode(): void
    {
        $movie = new MappedMovie();
        $movie->title = '2001';
        $movie->director = 'Stanley Kubrick';

        $node = $this->mapper->toNode($movie, base: 'https://example.com')->toArray();

        // Linked by @id from the movie...
        self::assertSame(['@id' => 'https://example.com/people/stanley%20kubrick'], $node['director']);

        // ...and present in the graph as its own top-level node.
        $person = $this->graph->get(Person::class, 'https://example.com/people/stanley%20kubrick');
        self::assertNotNull($person);
        self::assertSame('Stanley Kubrick', $person->getProperty('name'));
    }

    public function testAListOfNamesBecomesAListOfReferences(): void
    {
        $movie = new MappedMovie();
        $movie->actors = ['Keir Dullea', 'Gary Lockwood'];

        $node = $this->mapper->toNode($movie, base: 'https://example.com')->toArray();

        self::assertSame([
            ['@id' => 'https://example.com/people/keir%20dullea'],
            ['@id' => 'https://example.com/people/gary%20lockwood'],
        ], $node['actor']);
        self::assertSame(2, $this->graph->count());
    }

    /** The same person as director and actor must end up as one node, not two. */
    public function testARepeatedPersonIsOneNode(): void
    {
        $movie = new MappedMovie();
        $movie->director = 'Clint Eastwood';
        $movie->actors = ['Clint Eastwood'];

        $this->mapper->toNode($movie, base: 'https://example.com');

        self::assertSame(1, $this->graph->count());
    }

    public function testReferenceFalseEmbedsInsteadOfLinking(): void
    {
        $movie = new MappedMovie();
        $movie->country = 'United Kingdom';

        $node = $this->mapper->toNode($movie, base: 'https://example.com')->toArray();

        self::assertSame('Country', $node['countryOfOrigin']['@type']);
        self::assertSame('United Kingdom', $node['countryOfOrigin']['name']);
        self::assertTrue($this->graph->isEmpty(), 'an embedded node must not also be a top-level node');
    }

    /** Without an idPattern there is no @id to point at, so the node is embedded. */
    public function testAnonymousWrappedNodeIsEmbeddedRatherThanLinkedToNothing(): void
    {
        $movie = new MappedMovie();
        $movie->country = 'Malta';

        $node = $this->mapper->toNode($movie)->toArray();

        self::assertArrayNotHasKey('@id', $node['countryOfOrigin']);
        self::assertSame('Malta', $node['countryOfOrigin']['name']);
    }

    public function testMappedRelationRecurses(): void
    {
        $alice = new MappedPerson('Alice');
        $alice->knows = new MappedPerson('Bob');

        $node = $this->mapper->toNode($alice)->toArray();

        self::assertSame('Bob', $node['knows']['name']);
    }

    /**
     * A ↔ B would recurse for ever without the visited guard.
     *
     * With no @id there is nothing to reference back to, and embedding would put
     * Alice inside herself — an infinite structure that blows the stack in
     * toArray(), not just an ugly one. Dropping the back-link is the only valid
     * encoding, so that is what the mapper does.
     */
    public function testCyclicRelationWithoutIdsDropsTheBackLink(): void
    {
        $alice = new MappedPerson('Alice');
        $bob = new MappedPerson('Bob');
        $alice->knows = $bob;
        $bob->knows = $alice;

        $node = $this->mapper->toNode($alice)->toArray();

        self::assertSame('Alice', $node['name']);
        self::assertSame('Bob', $node['knows']['name']);
        self::assertArrayNotHasKey('knows', $node['knows']);
    }

    public function testCyclicRelationWithIdsLinksByReference(): void
    {
        $alice = new MappedPerson('Alice');
        $bob = new MappedPerson('Bob');
        $alice->knows = $bob;
        $bob->knows = $alice;

        $node = $this->mapper->toNode($alice, 'https://example.com/#alice')->toArray();

        self::assertSame(['@id' => 'https://example.com/#alice'], $node['knows']['knows']);
    }

    public function testAddPutsTheNodeInTheGraph(): void
    {
        $movie = new MappedMovie();
        $movie->title = '2001';

        $this->mapper->add($movie, 'https://example.com/2001#movie');

        self::assertSame(1, $this->graph->count());
        self::assertSame('https://example.com/2001#movie', $this->graph->toArray()['@graph'][0]['@id']);
    }

    public function testUnmappedClassIsRejectedWithAnActionableMessage(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/has no #\[SchemaOrg\] attribute/');

        $this->mapper->toNode(new \stdClass());
    }
}
