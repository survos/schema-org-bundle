<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Tests;

use Spatie\SchemaOrg\Schema;
use Survos\SchemaOrgBundle\EventListener\SchemaOrgResetListener;
use Survos\SchemaOrgBundle\Graph\SchemaOrgGraph;
use Survos\SchemaOrgBundle\Tests\Fixture\SchemaOrgAwareService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Twig\Environment;

final class BundleBootTest extends KernelTestCase
{
    /**
     * TestKernel registers this bundle and not the kit; #[RequiredBundle] has to
     * pull the kit in. If it silently stopped doing so, every kit convention
     * (command/controller auto-registration, twig + asset namespaces) would go
     * quiet rather than fail, so assert it explicitly.
     */
    public function testKitBundleIsAutoRegistered(): void
    {
        self::bootKernel();

        self::assertArrayHasKey('SurvosKitBundle', self::$kernel->getBundles());
    }

    public function testGraphIsPubliclyAvailable(): void
    {
        self::bootKernel();

        self::assertInstanceOf(SchemaOrgGraph::class, static::getContainer()->get(SchemaOrgGraph::class));
    }

    /** The trait's whole promise: a class with no constructor still gets the graph. */
    public function testSchemaOrgAwareTraitInjectsTheSameGraphInstance(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $consumer = $container->get(SchemaOrgAwareService::class);

        self::assertSame($container->get(SchemaOrgGraph::class), $consumer->schemaOrg);
    }

    /**
     * Guards the wiring rather than the rendering: #[AsTwigFunction] only becomes a
     * real Twig function if twig-bundle's AttributeExtensionPass sees the service,
     * which it does only when the definition is autoconfigured.
     */
    public function testTwigFunctionsAreRegistered(): void
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = static::getContainer()->get('twig');

        self::assertNotNull($twig->getFunction('render_schema_org'));
    }

    public function testRenderedTemplateEmitsTheCollectedGraph(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $container->get(SchemaOrgGraph::class)
            ->add(Schema::movie()->identifier('https://example.com/2001#movie')->name('2001'));

        /** @var Environment $twig */
        $twig = $container->get('twig');
        $html = $twig->createTemplate('{{ render_schema_org() }}')->render();

        // Twig must not escape the tag — the function is declared isSafe: ['html'].
        self::assertStringStartsWith('<script type="application/ld+json">', $html);
        self::assertStringContainsString('"2001"', $html);
    }

    /**
     * Worker-mode safety: the container (and the graph in it) outlives the request
     * under FrankenPHP/RoadRunner, so nodes from one page must not appear on the next.
     */
    public function testMainRequestResetsTheGraph(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $graph = $container->get(SchemaOrgGraph::class);
        $graph->add(Schema::movie()->identifier('https://example.com/2001#movie')->name('2001'));
        self::assertSame(1, $graph->count());

        $listener = new SchemaOrgResetListener($graph);

        $listener->onKernelRequest($this->requestEvent(HttpKernelInterface::SUB_REQUEST));
        self::assertSame(1, $graph->count(), 'sub-requests must leave the main request graph alone');

        $listener->onKernelRequest($this->requestEvent(HttpKernelInterface::MAIN_REQUEST));
        self::assertTrue($graph->isEmpty());
    }

    private function requestEvent(int $type): RequestEvent
    {
        return new RequestEvent(self::$kernel, Request::create('/'), $type);
    }
}
