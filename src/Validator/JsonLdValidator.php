<?php

declare(strict_types=1);

namespace Survos\SchemaOrgBundle\Validator;

/**
 * Structural checks on one JSON-LD block.
 *
 * Scope is deliberately narrow: things that are objectively wrong regardless of who
 * is consuming the data. It does NOT check per-type "required" properties, because
 * schema.org marks nothing required — those lists belong to Google's rich-result
 * program, they change without notice, and hardcoding them here would be asserting
 * an authority this bundle does not have. Use Google's validator for eligibility;
 * use this for correctness.
 */
final readonly class JsonLdValidator
{
    /**
     * @param string|null $pageUrl the URL the block was served from, when known —
     *                             enables the scheme/host cross-check
     */
    public function validate(string $json, ?string $pageUrl = null): ValidationResult
    {
        try {
            $document = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return new ValidationResult(0, [], ['Malformed JSON: ' . $e->getMessage()], []);
        }

        if (!\is_array($document)) {
            return new ValidationResult(0, [], ['Top level is not an object or array.'], []);
        }

        $errors = [];
        $warnings = [];

        if (!\array_key_exists('@context', $document) && !$this->isNodeList($document)) {
            $errors[] = 'No @context. Consumers cannot resolve the vocabulary without it.';
        }

        $nodes = $this->nodes($document);
        if ([] === $nodes) {
            return new ValidationResult(0, [], ['No nodes found.'], []);
        }

        $types = [];
        $ids = [];
        foreach ($nodes as $position => $node) {
            $label = $this->label($node, $position);

            $type = $node['@type'] ?? null;
            if (!\is_string($type) || '' === $type) {
                $errors[] = \sprintf('%s has no @type.', $label);
            } else {
                $types[$type] = ($types[$type] ?? 0) + 1;
            }

            $id = $node['@id'] ?? null;
            if (\is_string($id) && '' !== $id) {
                if (isset($ids[$id])) {
                    // Two nodes claiming one identity: a consumer merging them gets a
                    // chimera, and which one wins is undefined.
                    $errors[] = \sprintf('Duplicate @id "%s" — two nodes claim the same identity.', $id);
                }
                $ids[$id] = true;
            }

            if ($this->hasNullValue($node)) {
                $warnings[] = \sprintf('%s has a null property value; omit the property instead.', $label);
            }
        }

        arsort($types);

        foreach ($this->danglingReferences($nodes, $ids) as $reference) {
            $warnings[] = \sprintf(
                'Reference to "%s" matches no node in this block. Legitimate if that node lives elsewhere, a typo otherwise.',
                $reference,
            );
        }

        if (null !== $pageUrl) {
            $warnings = [...$warnings, ...$this->schemeMismatches($nodes, $pageUrl)];
        }

        return new ValidationResult(\count($nodes), $types, $errors, $warnings);
    }

    /**
     * @param array<mixed> $document
     *
     * @return list<array<string, mixed>>
     */
    private function nodes(array $document): array
    {
        if (isset($document['@graph']) && \is_array($document['@graph'])) {
            return array_values(array_filter($document['@graph'], \is_array(...)));
        }

        if ($this->isNodeList($document)) {
            return array_values(array_filter($document, \is_array(...)));
        }

        return [$document];
    }

    /** @param array<mixed> $document */
    private function isNodeList(array $document): bool
    {
        return array_is_list($document) && [] !== $document;
    }

    /**
     * Any {"@id": ...} object whose target is not a node in this block.
     *
     * A reference is an @id-only object; a node that happens to have an @id is not a
     * reference to itself.
     *
     * @param list<array<string, mixed>> $nodes
     * @param array<string, true>        $ids
     *
     * @return list<string>
     */
    private function danglingReferences(array $nodes, array $ids): array
    {
        $dangling = [];
        $walk = function (mixed $value) use (&$walk, $ids, &$dangling): void {
            if (!\is_array($value)) {
                return;
            }

            if (['@id'] === array_keys($value) && \is_string($value['@id']) && !isset($ids[$value['@id']])) {
                $dangling[$value['@id']] = true;

                return;
            }

            foreach ($value as $child) {
                $walk($child);
            }
        };

        foreach ($nodes as $node) {
            foreach ($node as $value) {
                $walk($value);
            }
        }

        return array_keys($dangling);
    }

    /**
     * @id and url values whose scheme or host disagrees with the page.
     *
     * This is the check that catches a reverse-proxy misconfiguration: TLS
     * terminates upstream, the app never learns it, and every identity it publishes
     * says http:// on an https:// page. An @id is an identity, so that names a
     * different resource than the page describing it.
     *
     * @param list<array<string, mixed>> $nodes
     *
     * @return list<string>
     */
    private function schemeMismatches(array $nodes, string $pageUrl): array
    {
        $pageScheme = parse_url($pageUrl, \PHP_URL_SCHEME);
        $pageHost = parse_url($pageUrl, \PHP_URL_HOST);
        if (!\is_string($pageScheme) || !\is_string($pageHost)) {
            return [];
        }

        $mismatched = [];
        foreach ($nodes as $node) {
            foreach (['@id', 'url'] as $key) {
                $value = $node[$key] ?? null;
                if (!\is_string($value)) {
                    continue;
                }

                // Same host, different scheme. A different host is somebody else's URL
                // and none of our business.
                if (parse_url($value, \PHP_URL_HOST) === $pageHost
                    && parse_url($value, \PHP_URL_SCHEME) !== $pageScheme) {
                    $mismatched[$value] = true;
                }
            }
        }

        return array_map(
            static fn (string $url): string => \sprintf(
                '%s is %s:// on a %s:// page — check trusted_proxies and the upstream TLS mode.',
                $url,
                parse_url($url, \PHP_URL_SCHEME) ?: '?',
                $pageScheme,
            ),
            array_keys($mismatched),
        );
    }

    /** @param array<string, mixed> $node */
    private function hasNullValue(array $node): bool
    {
        foreach ($node as $value) {
            if (null === $value) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $node */
    private function label(array $node, int $position): string
    {
        foreach (['@id', 'name'] as $key) {
            if (\is_string($node[$key] ?? null)) {
                return \sprintf('Node "%s"', $node[$key]);
            }
        }

        return \sprintf('Node #%d', $position + 1);
    }
}
