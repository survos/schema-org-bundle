<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Command;

use Doctrine\Persistence\ManagerRegistry;
use Survos\SchemaOrgBundle\Attribute\SchemaProperty;
use Survos\SchemaOrgBundle\Mapping\SchemaOrgMetadataFactory;
use Survos\SchemaOrgBundle\Validator\JsonLdExtractor;
use Survos\SchemaOrgBundle\Validator\JsonLdValidator;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Both schema.org commands live here rather than one class each, per our
 * single-class-per-feature convention.
 */
final class SchemaOrgCommands
{
    public function __construct(
        private readonly SchemaOrgMetadataFactory $metadata,
        private readonly JsonLdExtractor $extractor,
        private readonly JsonLdValidator $validator,
        private readonly ?ManagerRegistry $doctrine = null,
        private readonly ?HttpClientInterface $httpClient = null,
    ) {
    }

    /**
     * Which classes are mapped, and what each one leaves on the table.
     *
     * The unmapped-property list is the point: a mapping that silently covers three
     * of twelve fields looks finished from the outside. This makes coverage visible
     * without reading the entity.
     */
    #[AsCommand('schema:map', 'List classes mapped with #[SchemaOrg] and their unmapped public properties')]
    public function map(
        SymfonyStyle $io,
        #[Argument('Limit to one class (FQCN); defaults to every Doctrine entity')] ?string $class = null,
        #[Option('Also list classes that have no #[SchemaOrg] at all')] bool $unmapped = false,
    ): int {
        $classes = null !== $class ? [$class] : $this->doctrineEntities();

        if ([] === $classes) {
            $io->warning(
                null !== $class
                    ? 'No such class.'
                    : 'No Doctrine entities found. Pass a class name, or install doctrine/orm.',
            );

            return Command::INVALID;
        }

        $mapped = 0;
        $skipped = [];

        foreach ($classes as $candidate) {
            if (!class_exists($candidate)) {
                $io->error(\sprintf('Class "%s" does not exist.', $candidate));

                return Command::INVALID;
            }

            $mapping = $this->metadata->getMapping($candidate);
            if (null === $mapping) {
                $skipped[] = $candidate;

                continue;
            }

            ++$mapped;
            $io->section($candidate);
            $io->text(\sprintf('maps to <info>%s</info>', $this->shortType($mapping->nodeClass)));

            $rows = [];
            foreach ($mapping->properties as $property) {
                // "link" only means anything when the value is wrapped; for a plain
                // scalar there is no node to reference or embed.
                $rows[] = [
                    $property->schemaProperty,
                    $property->wrapIn ? $this->shortType($property->wrapIn) : '—',
                    $property->wrapIn ? ($property->reference ? 'reference' : 'embed') : '—',
                ];
            }
            $io->table(['schema.org property', 'wrapped as', 'link'], $rows);

            $missing = $this->unmappedPublicProperties($candidate);
            if ([] !== $missing) {
                $io->text(\sprintf('<comment>unmapped public properties (%d):</comment> %s', \count($missing), implode(', ', $missing)));
                $io->newLine();
            }
        }

        if ($unmapped && [] !== $skipped) {
            $io->section(\sprintf('No #[SchemaOrg] (%d)', \count($skipped)));
            $io->listing($skipped);
        }

        $io->success(\sprintf('%d mapped, %d unmapped.', $mapped, \count($skipped)));

        return Command::SUCCESS;
    }

    /**
     * Fetch a URL and check the JSON-LD it publishes.
     *
     * Checks only things that are objectively wrong -- malformed JSON, missing
     * @type, duplicate @ids, references pointing at nothing, a scheme that
     * disagrees with the page it was served from. It deliberately does NOT
     * invent per-type "required" properties: schema.org marks nothing required,
     * and Google's rich-result requirements are Google's, not the vocabulary's.
     * Use their validator for eligibility; use this for correctness.
     */
    #[AsCommand('schema:validate', 'Fetch a URL and sanity-check the JSON-LD it publishes')]
    public function validate(
        SymfonyStyle $io,
        #[Argument('URL to fetch')] string $url,
        #[Option('Print the extracted JSON-LD')] bool $dump = false,
    ): int {
        if (null === $this->httpClient) {
            $io->error('symfony/http-client is not installed, so this command cannot fetch anything.');

            return Command::INVALID;
        }

        try {
            $html = $this->httpClient->request('GET', $url)->getContent();
        } catch (\Throwable $e) {
            $io->error(\sprintf('Could not fetch %s: %s', $url, $e->getMessage()));

            return Command::FAILURE;
        }

        $blocks = $this->extractor->extract($html);

        if ([] === $blocks) {
            $io->warning('No <script type="application/ld+json"> found.');

            return Command::FAILURE;
        }

        $io->text(\sprintf('%d JSON-LD block%s', \count($blocks), 1 === \count($blocks) ? '' : 's'));

        $failed = false;
        foreach ($blocks as $index => $block) {
            $result = $this->validator->validate($block, $url);

            if ($dump) {
                $io->writeln($block);
            }

            $io->section(\sprintf('Block %d — %d node%s', $index + 1, $result->nodeCount, 1 === $result->nodeCount ? '' : 's'));

            if ([] !== $result->types) {
                $io->text(implode(', ', array_map(
                    static fn (string $type, int $count): string => \sprintf('%s×%d', $type, $count),
                    array_keys($result->types),
                    $result->types,
                )));
            }

            foreach ($result->errors as $error) {
                $io->error($error);
                $failed = true;
            }
            foreach ($result->warnings as $warning) {
                $io->warning($warning);
            }

            if ([] === $result->errors && [] === $result->warnings) {
                $io->text('<info>no problems found</info>');
            }
        }

        if ($failed) {
            return Command::FAILURE;
        }

        $io->success('JSON-LD looks structurally sound.');

        return Command::SUCCESS;
    }

    /** @return list<class-string> */
    private function doctrineEntities(): array
    {
        if (null === $this->doctrine) {
            return [];
        }

        $classes = [];
        foreach ($this->doctrine->getManagers() as $manager) {
            foreach ($manager->getMetadataFactory()->getAllMetadata() as $metadata) {
                $classes[] = $metadata->getName();
            }
        }

        sort($classes);

        return array_values(array_unique($classes));
    }

    /**
     * Public properties and zero-argument getters with no #[SchemaProperty].
     *
     * @param class-string $class
     *
     * @return list<string>
     */
    private function unmappedPublicProperties(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        $unmapped = [];

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || [] !== $property->getAttributes(SchemaProperty::class)) {
                continue;
            }
            $unmapped[] = '$' . $property->getName();
        }

        return $unmapped;
    }

    private function shortType(string $class): string
    {
        return (new \ReflectionClass($class))->getShortName();
    }
}
