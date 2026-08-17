<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle;

use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Survos\SchemaOrgBundle\DataCollector\SchemaOrgCollector;
use Survos\SchemaOrgBundle\EventListener\SchemaOrgAutoInjectListener;
use Survos\SchemaOrgBundle\EventListener\SchemaOrgResetListener;
use Survos\SchemaOrgBundle\Graph\SchemaOrgGraph;
use Survos\SchemaOrgBundle\Mapping\SchemaOrgMapper;
use Survos\SchemaOrgBundle\Mapping\SchemaOrgMetadataFactory;
use Survos\SchemaOrgBundle\Renderer\SchemaOrgRenderer;
use Survos\SchemaOrgBundle\Twig\SchemaOrgExtension;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

#[RequiredBundle(SurvosKitBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosSchemaOrgBundle extends AbstractSurvosBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('pretty_print')
                    ->defaultValue('%kernel.debug%')
                    ->info('Indent the JSON-LD. Readable in dev, wasted bytes in prod, so it follows kernel.debug by default. Accepts a bool or a parameter reference.')
                ->end()
                ->booleanNode('auto_inject')
                    ->defaultFalse()
                    ->info('Insert the JSON-LD before </head> on HTML responses instead of calling render_schema_org() in a template. For apps whose layout you would rather not edit. Off by default: an explicit Twig call is greppable, injected output is not. A template that calls render_schema_org() suppresses the injection, so enabling this can never double up.')
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Kit base: auto-registers src/Command/ and src/Controller/ (neither exists yet).
        parent::loadExtension($config, $container, $builder);

        $services = $container->services()
            ->defaults()
                ->autowire()
                ->autoconfigure();

        // Public: the graph is injected directly by app controllers and services, so it
        // must survive compilation even when nothing else in the container references it.
        $services->set(SchemaOrgGraph::class)->public();

        // '%kernel.debug%' arrives here as an unresolved parameter string when the app
        // hasn't overridden it — DI resolves it to a real bool, which config processing
        // can't do.
        $services->set(SchemaOrgRenderer::class)
            ->arg('$prettyPrint', $config['pretty_print']);

        // Autoconfigured so twig-bundle's AttributeExtensionPass picks up the
        // #[AsTwigFunction] methods.
        $services->set(SchemaOrgExtension::class);

        $services->set(SchemaOrgResetListener::class);

        // Attribute mapping. The factory is stateful (per-class reflection cache) and
        // shared, so the reflection pass happens once per class per process.
        $services->set(SchemaOrgMetadataFactory::class);
        $services->set(SchemaOrgMapper::class)->public();

        // Profiler integration, debug only — same guard and explicit tag as
        // elastic-bundle, the established shape in this monorepo. The tag is explicit
        // rather than left to autoconfiguration so the panel keeps a stable id.
        if ($builder->getParameter('kernel.debug') && class_exists(AbstractDataCollector::class)) {
            $services->set(SchemaOrgCollector::class)
                ->tag('data_collector', [
                    'template' => '@SurvosSchemaOrg/data_collector/schema_org.html.twig',
                    'id' => 'survos_schema_org',
                ]);
        }

        if ($config['auto_inject']) {
            $services->set(SchemaOrgAutoInjectListener::class);
        }
    }
}
