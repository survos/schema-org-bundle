<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Spatie\SchemaOrg\Schema;
use Survos\SchemaOrgBundle\Graph\SchemaOrgGraph;
use Survos\SchemaOrgBundle\Renderer\SchemaOrgRenderer;
use Survos\SchemaOrgBundle\Twig\SchemaOrgExtension;

final class SchemaOrgExtensionTest extends TestCase
{
    private SchemaOrgGraph $graph;

    protected function setUp(): void
    {
        $this->graph = new SchemaOrgGraph();
    }

    public function testRendersNothingWhenNothingWasCollected(): void
    {
        self::assertSame('', $this->extension()->render());
        self::assertSame('', $this->extension(debugPanel: true)->debugPanel());
    }

    public function testRendersOneLdJsonScriptTag(): void
    {
        $this->graph->add(Schema::movie()->identifier('https://example.com/2001#movie')->name('2001'));

        $html = $this->extension()->render();

        self::assertStringStartsWith('<script type="application/ld+json">', $html);
        self::assertStringEndsWith('</script>', $html);
        self::assertSame(1, substr_count($html, '<script'));

        $document = json_decode($this->scriptBody($html), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('https://schema.org', $document['@context']);
        self::assertSame('Movie', $document['@graph'][0]['@type']);
        self::assertSame('2001', $document['@graph'][0]['name']);
    }

    /**
     * The injection case: user-supplied text containing a closing script tag must
     * not be able to end the JSON-LD block early. The escaped form still decodes
     * back to the original string, so the markup is safe without losing data.
     */
    public function testContentCannotBreakOutOfTheScriptTag(): void
    {
        $payload = '</script><img src=x onerror=alert(1)>';
        $this->graph->add(Schema::movie()->name($payload));

        $html = $this->extension()->render();

        self::assertSame(1, substr_count($html, '</script>'), 'the only </script> must be the closing tag');
        self::assertStringNotContainsString('<img', $html);

        $document = json_decode($this->scriptBody($html), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame($payload, $document['@graph'][0]['name']);
    }

    public function testNonceIsEmittedWhenGiven(): void
    {
        $this->graph->add(Schema::movie()->name('2001'));

        self::assertStringContainsString(
            '<script type="application/ld+json" nonce="r4nd0m">',
            $this->extension()->render('r4nd0m'),
        );
    }

    public function testPrettyPrintIsOffByDefaultAndOnWhenConfigured(): void
    {
        $this->graph->add(Schema::movie()->name('2001'));

        self::assertStringNotContainsString("\n", $this->extension()->render());
        self::assertStringContainsString("\n", $this->extension(prettyPrint: true)->render());
    }

    public function testDebugPanelRendersOnlyWhenEnabled(): void
    {
        $this->graph->add(Schema::movie()->name('2001'));

        self::assertSame('', $this->extension()->debugPanel());

        $panel = $this->extension(debugPanel: true)->debugPanel();
        self::assertStringContainsString('Schema.org graph (1 node)', $panel);
        // The panel prints the JSON as text, so its markup must be entity-escaped.
        self::assertStringNotContainsString('{"@context"', $panel);
        self::assertStringContainsString('&quot;@context&quot;', $panel);
    }

    public function testDebugPanelPluralisesNodeCount(): void
    {
        $this->graph->add(Schema::movie()->identifier('https://example.com/#a'));
        $this->graph->add(Schema::movie()->identifier('https://example.com/#b'));

        self::assertStringContainsString('Schema.org graph (2 nodes)', $this->extension(debugPanel: true)->debugPanel());
    }

    private function extension(bool $prettyPrint = false, bool $debugPanel = false): SchemaOrgExtension
    {
        return new SchemaOrgExtension(
            $this->graph,
            new SchemaOrgRenderer($this->graph, $prettyPrint),
            $debugPanel,
        );
    }

    private function scriptBody(string $html): string
    {
        self::assertSame(1, preg_match('#<script[^>]*>(.*)</script>#s', $html, $matches));

        return $matches[1];
    }
}
