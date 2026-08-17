<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle;

use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Survos\SchemaOrgBundle\EventListener\SchemaOrgAutoInjectListener;
use Survos\SchemaOrgBundle\EventListener\SchemaOrgResetListener;
use Survos\SchemaOrgBundle\Graph\SchemaOrgGraph;
use Survos\SchemaOrgBundle\Renderer\SchemaOrgRenderer;
use Survos\SchemaOrgBundle\Twig\SchemaOrgExtension;
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
                ->scalarNode('debug_panel')
                    ->defaultValue('%kernel.debug%')
                    ->info('Let schema_org_debug() render its panel. Follows kernel.debug by default; set false to keep the Twig call in the template but render nothing.')
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
        $services->set(SchemaOrgExtension::class)
            ->arg('$debugPanel', $config['debug_panel']);

        $services->set(SchemaOrgResetListener::class);

        if ($config['auto_inject']) {
            $services->set(SchemaOrgAutoInjectListener::class);
        }
    }
}
