<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Tests\Graph;

use PHPUnit\Framework\TestCase;
use Spatie\SchemaOrg\ImageObject;
use Spatie\SchemaOrg\Organization;
use Spatie\SchemaOrg\Person;
use Spatie\SchemaOrg\Schema;
use Survos\SchemaOrgBundle\Graph\SchemaOrgGraph;

final class SchemaOrgGraphTest extends TestCase
{
    public function testEmptyGraph(): void
    {
        $graph = new SchemaOrgGraph();

        self::assertTrue($graph->isEmpty());
        self::assertSame(0, $graph->count());
    }

    public function testOutputIsASingleContextAndGraph(): void
    {
        $graph = new SchemaOrgGraph();
        $graph->add(Schema::person()->identifier('https://example.com/#kubrick')->name('Stanley Kubrick'));
        $graph->add(Schema::movie()->identifier('https://example.com/2001#movie')->name('2001'));

        $document = $graph->toArray();

        self::assertSame('https://schema.org', $document['@context']);
        self::assertCount(2, $document['@graph']);
        // Per-node @context is stripped: exactly one lives at the document root.
        foreach ($document['@graph'] as $node) {
            self::assertArrayNotHasKey('@context', $node);
            self::assertArrayHasKey('@type', $node);
        }
    }

    public function testSameIdentifierIsDedupedLastWriteWins(): void
    {
        $graph = new SchemaOrgGraph();
        $graph->add(Schema::organization()->identifier('https://example.com/#org')->name('First'));
        $graph->add(Schema::organization()->identifier('https://example.com/#org')->name('Second'));

        self::assertSame(1, $graph->count());
        self::assertSame('Second', $graph->toArray()['@graph'][0]['name']);
    }

    /**
     * The reason this bundle keys nodes off @id instead of using spatie's
     * "default" identifier: two distinct people must not collapse into one.
     */
    public function testDistinctIdentifiersOfTheSameTypeBothSurvive(): void
    {
        $graph = new SchemaOrgGraph();
        $graph->add(Schema::person()->identifier('https://example.com/#a')->name('A'));
        $graph->add(Schema::person()->identifier('https://example.com/#b')->name('B'));

        self::assertSame(2, $graph->count());
    }

    public function testUnidentifiedNodesAccumulateInsteadOfOverwriting(): void
    {
        $graph = new SchemaOrgGraph();
        $graph->add(Schema::imageObject()->url('https://example.com/1.jpg'));
        $graph->add(Schema::imageObject()->url('https://example.com/2.jpg'));

        self::assertSame(2, $graph->count());
        self::assertSame(
            ['https://example.com/1.jpg', 'https://example.com/2.jpg'],
            array_column($graph->toArray()['@graph'], 'url'),
        );
    }

    public function testExplicitIdentifierDedupesNodesWithoutAnId(): void
    {
        $graph = new SchemaOrgGraph();
        $graph->add(Schema::imageObject()->url('https://example.com/old.jpg'), 'hero');
        $graph->add(Schema::imageObject()->url('https://example.com/new.jpg'), 'hero');

        self::assertSame(1, $graph->count());
        self::assertSame('https://example.com/new.jpg', $graph->toArray()['@graph'][0]['url']);
    }

    public function testGetReturnsNullWhenAbsentRatherThanThrowing(): void
    {
        $graph = new SchemaOrgGraph();

        self::assertNull($graph->get(Person::class, 'https://example.com/#nobody'));
        self::assertFalse($graph->has(Person::class, 'https://example.com/#nobody'));
    }

    public function testGetReturnsTheAddedNode(): void
    {
        $graph = new SchemaOrgGraph();
        $person = Schema::person()->identifier('https://example.com/#kubrick')->name('Stanley Kubrick');
        $graph->add($person);

        self::assertSame($person, $graph->get(Person::class, 'https://example.com/#kubrick'));
    }

    /** Several contributors filling in one shared node — the site-wide Organization case. */
    public function testGetOrCreateSharesOneNodeAcrossContributors(): void
    {
        $graph = new SchemaOrgGraph();

        $graph->getOrCreate(Organization::class, 'site')->name('Survos');
        $graph->getOrCreate(Organization::class, 'site')->url('https://survos.com');

        self::assertSame(1, $graph->count());
        $node = $graph->toArray()['@graph'][0];
        self::assertSame('Survos', $node['name']);
        self::assertSame('https://survos.com', $node['url']);
    }

    public function testResetClearsEverythingIncludingAnonymousKeys(): void
    {
        $graph = new SchemaOrgGraph();
        $graph->add(Schema::imageObject()->url('https://example.com/1.jpg'));
        $graph->add(Schema::person()->identifier('https://example.com/#a'));

        $graph->reset();
        self::assertTrue($graph->isEmpty());

        // A fresh anonymous node lands on the first key again, and one added after
        // reset must not collide with (or resurrect) anything from before it.
        $graph->add(Schema::imageObject()->url('https://example.com/2.jpg'));
        self::assertSame(1, $graph->count());
        self::assertSame('https://example.com/2.jpg', $graph->toArray()['@graph'][0]['url']);
    }

    public function testAddIsChainable(): void
    {
        $graph = new SchemaOrgGraph();

        self::assertSame(
            $graph,
            $graph->add(new Person())->add(new ImageObject()),
        );
    }
}
