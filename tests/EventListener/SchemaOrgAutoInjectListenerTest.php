<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Tests\EventListener;

use PHPUnit\Framework\TestCase;
use Spatie\SchemaOrg\Schema;
use Survos\SchemaOrgBundle\EventListener\SchemaOrgAutoInjectListener;
use Survos\SchemaOrgBundle\Graph\SchemaOrgGraph;
use Survos\SchemaOrgBundle\Renderer\SchemaOrgRenderer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SchemaOrgAutoInjectListenerTest extends TestCase
{
    private const PAGE = '<html><head><title>T</title></head><body>hi</body></html>';

    private SchemaOrgGraph $graph;

    protected function setUp(): void
    {
        $this->graph = new SchemaOrgGraph();
        $this->graph->add(Schema::movie()->identifier('https://example.com/2001#movie')->name('2001'));
    }

    public function testInjectsBeforeHead(): void
    {
        $response = $this->dispatch(new Response(self::PAGE));

        self::assertStringContainsString('<script type="application/ld+json">', $response->getContent());
        self::assertStringContainsString('</script></head>', $response->getContent());
        self::assertStringContainsString('<body>hi</body>', $response->getContent());
    }

    /**
     * The whole reason SchemaOrgGraph tracks a rendered flag: a template calling
     * render_schema_org() with auto_inject also on must not produce two @graph blocks.
     */
    public function testDoesNotInjectWhenATemplateAlreadyRendered(): void
    {
        $renderer = new SchemaOrgRenderer($this->graph, false);
        $renderer->scriptTag(); // what {{ render_schema_org() }} does

        $response = $this->dispatch(new Response(self::PAGE), $renderer);

        self::assertSame(self::PAGE, $response->getContent());
    }

    public function testDoesNothingWhenTheGraphIsEmpty(): void
    {
        $this->graph->reset();

        self::assertSame(self::PAGE, $this->dispatch(new Response(self::PAGE))->getContent());
    }

    /**
     * A Content-Length set upstream describes the pre-injection body; leaving it
     * would truncate the page at exactly the point the script was added.
     */
    public function testStaleContentLengthIsRemoved(): void
    {
        $response = new Response(self::PAGE);
        $response->headers->set('Content-Length', (string) \strlen(self::PAGE));

        self::assertFalse($this->dispatch($response)->headers->has('Content-Length'));
    }

    /** @param array<string, string> $headers */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonInjectableResponses')]
    public function testLeavesNonHtmlResponsesAlone(int $status, array $headers): void
    {
        $response = new Response(self::PAGE, $status, $headers);

        self::assertSame(self::PAGE, $this->dispatch($response)->getContent());
    }

    /** @return iterable<string, array{int, array<string, string>}> */
    public static function nonInjectableResponses(): iterable
    {
        yield 'json' => [200, ['Content-Type' => 'application/json']];
        yield 'redirect' => [302, ['Content-Type' => 'text/html']];
        yield 'error' => [500, ['Content-Type' => 'text/html']];
        yield 'compressed' => [200, ['Content-Type' => 'text/html', 'Content-Encoding' => 'gzip']];
        yield 'download' => [200, ['Content-Type' => 'text/html', 'Content-Disposition' => 'attachment; filename="p.html"']];
    }

    public function testLeavesResponsesWithNoHeadAlone(): void
    {
        $fragment = '<div>just a fragment</div>';

        self::assertSame($fragment, $this->dispatch(new Response($fragment))->getContent());
    }

    /** Streamed and file responses have no content string to rewrite. */
    public function testSkipsStreamedAndBinaryResponses(): void
    {
        $streamed = new StreamedResponse(static function (): void { echo self::PAGE; });
        $this->dispatch($streamed);
        self::assertFalse($streamed->getContent());

        $file = new BinaryFileResponse(__FILE__);
        $this->dispatch($file);
        self::assertSame(__FILE__, $file->getFile()->getPathname());
    }

    public function testSkipsSubRequests(): void
    {
        $response = $this->dispatch(new Response(self::PAGE), null, HttpKernelInterface::SUB_REQUEST);

        self::assertSame(self::PAGE, $response->getContent());
    }

    private function dispatch(
        Response $response,
        ?SchemaOrgRenderer $renderer = null,
        int $type = HttpKernelInterface::MAIN_REQUEST,
    ): Response {
        $listener = new SchemaOrgAutoInjectListener(
            $this->graph,
            $renderer ?? new SchemaOrgRenderer($this->graph, false),
        );

        // A stub, not a mock: the listener never calls the kernel, it only needs one
        // to construct the event.
        $listener->onKernelResponse(new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/'),
            $type,
            $response,
        ));

        return $response;
    }
}
