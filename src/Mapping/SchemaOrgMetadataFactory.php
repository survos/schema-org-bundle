<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Mapping;

use Spatie\SchemaOrg\BaseType;
use Survos\SchemaOrgBundle\Attribute\SchemaOrg;
use Survos\SchemaOrgBundle\Attribute\SchemaProperty;

/**
 * Reads #[SchemaOrg]/#[SchemaProperty] off a class once and caches the result.
 *
 * Reflection is done per class, never per object — the same shape Doctrine and
 * survos/field-bundle's FieldReader use, and the reason mapping 500 rows costs one
 * reflection pass rather than 500.
 */
final class SchemaOrgMetadataFactory
{
    /** @var array<class-string, ClassMapping|null> */
    private array $cache = [];

    /** True when the class carries #[SchemaOrg]. */
    public function supports(string $class): bool
    {
        return null !== $this->getMapping($class);
    }

    /**
     * @param class-string $class
     *
     * @return ClassMapping|null null when the class has no #[SchemaOrg]
     */
    public function getMapping(string $class): ?ClassMapping
    {
        // array_key_exists, not ??=: null is a real cached answer ("not mapped"),
        // and ??= would re-run the whole reflection pass for it every time.
        if (!\array_key_exists($class, $this->cache)) {
            $this->cache[$class] = $this->build($class);
        }

        return $this->cache[$class];
    }

    /** @param class-string $class */
    private function build(string $class): ?ClassMapping
    {
        $reflection = new \ReflectionClass($class);

        $classAttribute = $reflection->getAttributes(SchemaOrg::class)[0] ?? null;
        if (null === $classAttribute) {
            return null;
        }

        return new ClassMapping(
            nodeClass: $this->resolveNodeClass($classAttribute->newInstance()->type, $class),
            properties: $this->buildProperties($reflection),
        );
    }

    /** @return list<PropertyMapping> */
    private function buildProperties(\ReflectionClass $reflection): array
    {
        $mappings = [];

        // All properties, not just public ones: a #[SchemaProperty] on a property the
        // mapper cannot read is a mistake, and skipping it silently would emit a node
        // quietly missing a field. Asymmetric visibility (private(set)) reads as public
        // here, which is why it works.
        foreach ($reflection->getProperties() as $property) {
            $attribute = $property->getAttributes(SchemaProperty::class)[0] ?? null;
            if (null === $attribute) {
                continue;
            }

            if (!$property->isPublic()) {
                throw new \LogicException(\sprintf(
                    '%s::$%s carries #[SchemaProperty] but is not publicly readable, so the '
                    . 'mapper cannot read it. Make it public, use private(set) for a '
                    . 'public-get/private-set property, or move the attribute to a '
                    . 'zero-argument getter.',
                    $reflection->getName(),
                    $property->getName(),
                ));
            }

            $name = $property->getName();
            $mappings[] = $this->toMapping(
                $attribute->newInstance(),
                static fn (object $entity): mixed => $entity->{$name},
                $reflection->getName(),
            );
        }

        // Getters too, so a computed value (a property hook's get, an accessor over a
        // private field) can be mapped without adding a public property for it.
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $attribute = $method->getAttributes(SchemaProperty::class)[0] ?? null;
            if (null === $attribute) {
                continue;
            }

            if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
                throw new \LogicException(\sprintf(
                    '%s::%s() carries #[SchemaProperty] but is static or requires arguments; '
                    . 'the mapper can only call a non-static method with no required parameters.',
                    $reflection->getName(),
                    $method->getName(),
                ));
            }

            $name = $method->getName();
            $mappings[] = $this->toMapping(
                $attribute->newInstance(),
                static fn (object $entity): mixed => $entity->{$name}(),
                $reflection->getName(),
            );
        }

        return $mappings;
    }

    /** @param \Closure(object): mixed $accessor */
    private function toMapping(SchemaProperty $attribute, \Closure $accessor, string $owner): PropertyMapping
    {
        return new PropertyMapping(
            schemaProperty: $attribute->name,
            accessor: $accessor,
            wrapIn: null === $attribute->as ? null : $this->resolveNodeClass($attribute->as, $owner),
            reference: $attribute->reference,
            idPattern: $attribute->idPattern,
        );
    }

    /**
     * 'Movie' → Spatie\SchemaOrg\Movie. A fully-qualified spatie class is accepted
     * as-is, so callers who want static analysis can write Movie::class.
     *
     * @return class-string<BaseType>
     */
    private function resolveNodeClass(string $type, string $owner): string
    {
        $class = class_exists($type) ? $type : 'Spatie\\SchemaOrg\\' . $type;

        if (!class_exists($class) || !is_subclass_of($class, BaseType::class)) {
            throw new \LogicException(\sprintf(
                '%s declares Schema.org type "%s", which is not a spatie/schema-org type '
                . '(looked for %s). Check the spelling against schema.org — the type names '
                . 'are case-sensitive, e.g. "MusicComposition", not "Musiccomposition".',
                $owner,
                $type,
                $class,
            ));
        }

        return $class;
    }
}
