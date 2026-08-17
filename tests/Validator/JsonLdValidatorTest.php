<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Tests\Validator;

use PHPUnit\Framework\TestCase;
use Spatie\SchemaOrg\Schema;
use Survos\SchemaOrgBundle\Graph\SchemaOrgGraph;
use Survos\SchemaOrgBundle\Renderer\SchemaOrgRenderer;
use Survos\SchemaOrgBundle\Validator\JsonLdExtractor;
use Survos\SchemaOrgBundle\Validator\JsonLdValidator;

final class JsonLdValidatorTest extends TestCase
{
    private JsonLdValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new JsonLdValidator();
    }

    /** The bundle's own output must pass its own validator. */
    public function testThisBundlesOutputValidates(): void
    {
        $graph = new SchemaOrgGraph();
        $person = Schema::person()->identifier('https://example.com/people/kubrick')->name('Stanley Kubrick');
        $graph->add($person);
        $graph->add(
            Schema::movie()
                ->identifier('https://example.com/2001#movie')
                ->url('https://example.com/2001')
                ->name('2001')
                ->director($person->referenced()),
        );

        $json = (new SchemaOrgRenderer($graph, false))->json(pretty: false);
        $result = $this->validator->validate($json, 'https://example.com/2001');

        self::assertTrue($result->isValid(), implode(' | ', $result->errors));
        self::assertSame([], $result->warnings, implode(' | ', $result->warnings));
        self::assertSame(2, $result->nodeCount);
        self::assertSame(['Person' => 1, 'Movie' => 1], $result->types);
    }

    public function testMalformedJsonIsAnError(): void
    {
        $result = $this->validator->validate('{"@context": "https://schema.org",');

        self::assertFalse($result->isValid());
        self::assertStringContainsString('Malformed JSON', $result->errors[0]);
    }

    public function testMissingContextIsAnError(): void
    {
        $result = $this->validator->validate('{"@type": "Movie", "name": "2001"}');

        self::assertStringContainsString('No @context', $result->errors[0]);
    }

    public function testMissingTypeIsAnError(): void
    {
        $result = $this->validator->validate('{"@context":"https://schema.org","@graph":[{"name":"2001"}]}');

        self::assertStringContainsString('has no @type', $result->errors[0]);
    }

    /**
     * Two nodes claiming one identity: a consumer merging them produces a chimera,
     * and which one wins is undefined.
     */
    public function testDuplicateIdIsAnError(): void
    {
        $json = '{"@context":"https://schema.org","@graph":['
            . '{"@type":"Movie","@id":"https://example.com/#a","name":"One"},'
            . '{"@type":"Movie","@id":"https://example.com/#a","name":"Two"}]}';

        $result = $this->validator->validate($json);

        self::assertStringContainsString('Duplicate @id', $result->errors[0]);
    }

    /** A typo in a cross-link. Warning, not error: the target may live on another page. */
    public function testDanglingReferenceIsAWarning(): void
    {
        $json = '{"@context":"https://schema.org","@graph":['
            . '{"@type":"Movie","@id":"https://example.com/#movie","director":{"@id":"https://example.com/#nobody"}}]}';

        $result = $this->validator->validate($json);

        self::assertTrue($result->isValid());
        self::assertStringContainsString('#nobody', $result->warnings[0]);
    }

    public function testAResolvedReferenceIsNotFlagged(): void
    {
        $json = '{"@context":"https://schema.org","@graph":['
            . '{"@type":"Movie","@id":"https://example.com/#movie","director":{"@id":"https://example.com/#k"}},'
            . '{"@type":"Person","@id":"https://example.com/#k","name":"Stanley Kubrick"}]}';

        self::assertSame([], $this->validator->validate($json)->warnings);
    }

    /**
     * The check that would have caught the reverse-proxy bug on
     * packages.survos.com: TLS terminated upstream, the app never learned it, and
     * every @id it published said http:// on an https:// page.
     */
    public function testSchemeMismatchWithThePageIsAWarning(): void
    {
        $json = '{"@context":"https://schema.org","@graph":['
            . '{"@type":"Movie","@id":"http://example.com/#movie","url":"http://example.com/2001"}]}';

        $result = $this->validator->validate($json, 'https://example.com/2001');

        self::assertCount(2, $result->warnings);
        self::assertStringContainsString('trusted_proxies', $result->warnings[0]);
    }

    /** A different host is somebody else's URL — not our business. */
    public function testOtherHostsAreNotFlaggedForScheme(): void
    {
        $json = '{"@context":"https://schema.org","@graph":['
            . '{"@type":"Movie","@id":"https://example.com/#movie","url":"http://imdb.example/title"}]}';

        self::assertSame([], $this->validator->validate($json, 'https://example.com/x')->warnings);
    }

    public function testNullPropertyValueIsAWarning(): void
    {
        $json = '{"@context":"https://schema.org","@graph":[{"@type":"Movie","name":null}]}';

        $result = $this->validator->validate($json);

        self::assertStringContainsString('null property value', $result->warnings[0]);
    }

    public function testBareNodeListNeedsNoContext(): void
    {
        // [{...},{...}] with per-node @context is valid JSON-LD, just not what this
        // bundle emits.
        $json = '[{"@context":"https://schema.org","@type":"Movie","name":"2001"}]';

        self::assertTrue($this->validator->validate($json)->isValid());
    }

    public function testExtractorFindsEveryBlockInDocumentOrder(): void
    {
        $html = '<html><head>'
            . '<script type="application/ld+json">{"@type":"A"}</script>'
            . '<script type="text/javascript">ignored()</script>'
            . "<script type='application/ld+json'>\n  {\"@type\":\"B\"}\n</script>"
            . '</head></html>';

        self::assertSame(
            ['{"@type":"A"}', '{"@type":"B"}'],
            (new JsonLdExtractor())->extract($html),
        );
    }

    public function testExtractorReturnsNothingWhenThereIsNoJsonLd(): void
    {
        self::assertSame([], (new JsonLdExtractor())->extract('<html><body>hi</body></html>'));
    }
}
