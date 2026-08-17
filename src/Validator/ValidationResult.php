<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Validator;

/**
 * The outcome of checking one JSON-LD block.
 *
 * errors are things that are wrong; warnings are things that are usually wrong but
 * have legitimate exceptions (a reference to a node published on another page, for
 * instance).
 */
final readonly class ValidationResult
{
    /**
     * @param array<string, int> $types    type name => node count
     * @param list<string>       $errors
     * @param list<string>       $warnings
     */
    public function __construct(
        public int $nodeCount,
        public array $types,
        public array $errors,
        public array $warnings,
    ) {
    }

    public function isValid(): bool
    {
        return [] === $this->errors;
    }
}
