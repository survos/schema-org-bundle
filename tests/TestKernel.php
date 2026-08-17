<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Tests;

use Survos\SchemaOrgBundle\SurvosSchemaOrgBundle;
use Survos\SchemaOrgBundle\Tests\Fixture\SchemaOrgAwareService;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

final class TestKernel extends Kernel
{
    /**
     * SurvosKitBundle is deliberately absent: the bundle declares it with
     * #[RequiredBundle], so leaving it out here is what proves that declaration
     * works — see BundleBootTest::testKitBundleIsAutoRegistered().
     */
    public function registerBundles(): array
    {
        return [
            new FrameworkBundle(),
            new TwigBundle(),
            new SurvosSchemaOrgBundle(),
        ];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test',
                'test' => true,
                'http_method_override' => false,
            ]);
            $container->loadFromExtension('twig', [
                'strict_variables' => true,
            ]);

            // Autowired so #[Required] property injection (SchemaOrgAwareTrait) actually runs.
            $container->register(SchemaOrgAwareService::class, SchemaOrgAwareService::class)
                ->setAutowired(true)
                ->setPublic(true);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/schema-org-bundle-tests/cache/' . spl_object_hash($this);
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/schema-org-bundle-tests/log';
    }
}
