<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Tests\DataCollector;

use PHPUnit\Framework\TestCase;
use Spatie\SchemaOrg\Schema;
use Survos\SchemaOrgBundle\DataCollector\SchemaOrgCollector;
use Survos\SchemaOrgBundle\Graph\SchemaOrgGraph;
use Survos\SchemaOrgBundle\Renderer\SchemaOrgRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SchemaOrgCollectorTest extends TestCase
{
    private SchemaOrgGraph $graph;
    private SchemaOrgRenderer $renderer;
    private SchemaOrgCollector $collector;

    protected function setUp(): void
    {
        $this->graph = new SchemaOrgGraph();
        $this->renderer = new SchemaOrgRenderer($this->graph, false);
        $this->collector = new SchemaOrgCollector($this->graph, $this->renderer);
    }

    public function testCollectsNothingForAnEmptyGraph(): void
    {
        $this->collect();

        self::assertSame(0, $this->collector->getCount());
        self::assertSame([], $this->collector->getTypes());
        self::assertSame('', $this->collector->getJson());
    }

    public function testCountsNodesAndGroupsTypesMostFrequentFirst(): void
    {
        $this->graph->add(Schema::movie()->identifier('https://example.com/#movie')->name('2001'));
        $this->graph->add(Schema::person()->identifier('https://example.com/#a')->name('A'));
        $this->graph->add(Schema::person()->identifier('https://example.com/#b')->name('B'));

        $this->collect();

        self::assertSame(3, $this->collector->getCount());
        self::assertSame(['Person' => 2, 'Movie' => 1], $this->collector->getTypes());
        self::assertSame('https://schema.org', $this->collector->getContext());
    }

    public function testSummarisesEachNodeForThePanelTable(): void
    {
        $this->graph->add(Schema::movie()->identifier('https://example.com/#movie')->name('2001'));
        $this->graph->add(Schema::imageObject()->url('https://example.com/p.jpg'));

        $this->collect();

        self::assertSame(
            [
                ['type' => 'Movie', 'id' => 'https://example.com/#movie', 'label' => '2001'],
                ['type' => 'ImageObject', 'id' => null, 'label' => null],
            ],
            $this->collector->getNodes(),
        );
    }

    /**
     * The failure the panel exists to surface: nodes collected, but no
     * render_schema_org() in the layout and auto_inject off, so the page publishes
     * nothing. Both states look identical in the rendered HTML.
     */
    public function testTracksWhetherTheGraphActuallyReachedThePage(): void
    {
        $this->graph->add(Schema::movie()->identifier('https://example.com/#movie')->name('2001'));

        $this->collect();
        self::assertFalse($this->collector->isRendered());

        $this->renderer->scriptTag(); // what {{ render_schema_org() }} does
        $this->collect();
        self::assertTrue($this->collector->isRendered());
    }

    public function testJsonIsPrettyPrintedRegardlessOfThePrettyPrintSetting(): void
    {
        // The panel is for reading; the page setting is about bytes on the wire.
        $this->graph->add(Schema::movie()->identifier('https://example.com/#movie')->name('2001'));

        $this->collect();

        self::assertStringContainsString("\n", $this->collector->getJson());
        self::assertSame('2001', json_decode($this->collector->getJson(), true, flags: \JSON_THROW_ON_ERROR)['@graph'][0]['name']);
    }

    private function collect(): void
    {
        $this->collector->collect(Request::create('/'), new Response('<html></html>'));
    }
}
