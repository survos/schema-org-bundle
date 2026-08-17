# Attribute mapping reference

`#[SchemaOrg]` on a class and `#[SchemaProperty]` on its members let
`SchemaOrgMapper` build a spatie node without hand-writing the scalar half of a
mapping. This is the full reference; the README has the short version.

The boundary is deliberate and worth stating up front: **attributes map values,
code composes graphs.** Anything that needs a decision — cross-links, `@id`s
derived from a canonical URL, conditional rules — stays in PHP. See
[Where to stop](#where-to-stop).

## `#[SchemaOrg]`

```php
use Survos\SchemaOrgBundle\Attribute\SchemaOrg;

#[SchemaOrg('Movie')]
final class Movie {}
```

| Parameter | Type | Meaning |
|---|---|---|
| `type` | `string` | The Schema.org type name — `'Movie'`, `'MusicComposition'`, `'ArchiveComponent'`. Resolved to `Spatie\SchemaOrg\<Type>`. |

Names are **case-sensitive** and must match Schema.org exactly:
`'MusicComposition'`, not `'Musiccomposition'`. A wrong name fails loudly at
mapping time rather than emitting a broken node:

```
App\Entity\Song declares Schema.org type "Musiccomposition", which is not a
spatie/schema-org type (looked for Spatie\SchemaOrg\Musiccomposition).
```

You can pass the spatie class instead, which static analysis and your IDE can
check:

```php
use Spatie\SchemaOrg\MusicComposition;

#[SchemaOrg(MusicComposition::class)]
final class Song {}
```

## `#[SchemaProperty]`

```php
use Survos\SchemaOrgBundle\Attribute\SchemaProperty;

#[SchemaProperty('name')] public ?string $title = null;
```

| Parameter | Type | Default | Meaning |
|---|---|---|---|
| `name` | `string` | — | The Schema.org property: `'name'`, `'dateCreated'`, `'director'`. |
| `as` | `?string` | `null` | Wrap the value in this Schema.org type instead of using it raw. A scalar becomes that type's `name`. |
| `reference` | `bool` | `true` | With `as`, add the wrapped node to the graph as its own top-level node and link it by `@id`, rather than nesting a copy. |
| `idPattern` | `?string` | `null` | The `@id` for the wrapped node. `{base}` → the mapper's base URL, `{value}` → the url-encoded lowercased value. |

`#[SchemaProperty]` is **orthogonal to `#[Field]`** from `survos/field-bundle`.
That describes how a property is exposed in grids, search, and the API; this
describes what it means in JSON-LD. Different axes, same property, no conflict:

```php
#[Field(searchable: true, order: 10)]
#[SchemaProperty('name')]
public ?string $title = null;
```

## What can carry it

All of these work, and the last two are the non-obvious ones — both are proven
against `survos-sites/packages`' `Package` entity:

```php
#[SchemaOrg('SoftwareSourceCode')]
class Package
{
    // 1. plain public property
    #[SchemaProperty('description')]
    public ?string $description = null;

    // 2. virtual property (a get hook, no backing column)
    #[SchemaProperty('keywords')]
    public array $keywords { get => $this->data['keywords'] ?? []; }

    // 3. asymmetric visibility -- public get, private set
    #[SchemaProperty('codeRepository')]
    private(set) ?string $repo = null;

    // 4. constructor-promoted, readonly
    public function __construct(
        #[SchemaProperty('name')]
        private(set) readonly ?string $name = null,
    ) {}

    // 5. zero-argument getter, for a computed value
    #[SchemaProperty('alternateName')]
    public function getDisplayTitle(): ?string { /* ... */ }
}
```

A getter that is `static` or requires arguments throws at metadata-build time —
the mapper can only call a non-static method with no required parameters.

A `private` property with `#[SchemaProperty]` **throws** — the mapper cannot read
it, and silently skipping it would emit a plausible-looking node quietly missing a
field, which is worse than an exception:

```
App\Entity\Thing::$name carries #[SchemaProperty] but is not publicly readable,
so the mapper cannot read it. Make it public, use private(set) for a
public-get/private-set property, or move the attribute to a zero-argument getter.
```

## Value handling

| Input | Emitted |
|---|---|
| `null` | property omitted |
| `''`, `'   '` | property omitted (trimmed first) |
| `[]` | property omitted |
| `string` | trimmed |
| `int`, `float`, `bool` | as-is |
| `DateTimeInterface` | left to spatie, which formats ATOM |
| backed enum | its `->value` |
| `iterable` | mapped element-wise; nulls dropped; omitted if nothing survives |
| object with `#[SchemaOrg]` | recursed |

**Absent, not null.** `"name": null` is not a valid way to say "we don't know",
and `"genre": []` asserts *"this has no genres"* rather than *"we didn't look"*.
Both are omitted instead. This is the same principle as
[no silent defaults](#where-to-stop): publish what you know.

## Relations

### A column that holds a name, not an entity

The common shape in real data: `director` is a `varchar`, not a `Person`.

```php
#[SchemaProperty('director', as: 'Person', idPattern: '{base}/people/{value}')]
public ?string $director = null;
```

```php
$mapper->add($movie, $canonicalUrl . '#movie', 'https://example.com');
```

emits, on the Movie node:

```json
"director": { "@id": "https://example.com/people/stanley%20kubrick" }
```

plus a top-level `Person` node with that `@id` and `"name": "Stanley Kubrick"`.

Because the `@id` derives from the value, **the same person appearing twice is one
node** — a director who also acts, or one author across twelve packages.

An array works the same way and produces a list of references:

```php
#[SchemaProperty('actor', as: 'Person', idPattern: '{base}/people/{value}')]
public ?array $actors = null;
```

### A related object

If the related class also carries `#[SchemaOrg]`, the mapper recurses; no `as:`
needed.

```php
#[SchemaProperty('knows')]
public ?MappedPerson $knows = null;
```

## References vs. embedding

Wrapped and nested nodes are **referenced** by default: added to the graph as
their own top-level node, linked by `@id`. That is what a graph is for, and it is
what keeps one `Person` one node however many things point at them.

Pass `reference: false` to embed a copy instead — reasonable for a value object
nothing else will ever refer to:

```php
#[SchemaProperty('countryOfOrigin', as: 'Country', reference: false)]
public ?string $country = null;
```

```json
"countryOfOrigin": { "@type": "Country", "name": "United Kingdom" }
```

A node with no `@id` cannot be referenced (`{"@id": null}` is meaningless), so it
is embedded regardless of `reference`. Give wrapped nodes an `idPattern` when you
want them deduplicated.

### Cycles

`A knows B knows A` terminates. The mapper tracks objects it is mid-way through
building, so the second visit does not recurse.

Closing a cycle is the one case where embedding is **not** an option — the node
would contain itself, and `toArray()` would recurse until the stack ran out. So:

- cycle target **has** an `@id` → emitted as a reference
- cycle target has **no** `@id` → the back-link is **dropped**

Give anything reachable in a cycle an `@id`, or accept the missing edge.

## Where to stop

The mapper covers the declarative 80%. These stay in PHP, on purpose — expressing
them in attributes means inventing a DSL:

- **cross-links** — `WebPage.mainEntity` ↔ `Thing.mainEntityOfPage`
- **`@id`s derived from the request** — a canonical URL isn't knowable from a class
- **conditional rules** — "only emit `aggregateRating` when there is at least one
  vote", "prefer `date` over `year`"
- **anything requiring a judgement** — whether a publisher is a `copyrightHolder`,
  whether a star count is a rating (it isn't)

`add()` returns the node, so the hand-written half is ordinary fluent spatie code:

```php
$node = $mapper->add($movie, $canonicalUrl . '#movie', $siteUrl);
$node->mainEntityOfPage($webPage->referenced());
```

Real examples of the split: `PackageSchema` in
[survos-sites/packages](https://github.com/survos-sites/packages) (attributes +
code), `MovieSchema` in
[survos-sites/bench](https://github.com/survos-sites/bench) (all code).

## API

```php
use Survos\SchemaOrgBundle\Mapping\SchemaOrgMapper;
```

| Method | Does |
|---|---|
| `add(object $entity, ?string $id = null, string $base = ''): BaseType` | Maps and contributes it to the request graph. |
| `toNode(object $entity, ?string $id = null, string $base = ''): BaseType` | Maps without touching the graph. |

`$id` becomes the node's `@id`. Without one the node cannot be deduplicated or
referenced, which is rarely what you want for a page's main entity. `$base`
replaces `{base}` in any `idPattern`.

Mapping a class with no `#[SchemaOrg]` throws:

```
stdClass has no #[SchemaOrg] attribute, so there is nothing to map it to.
Add #[SchemaOrg('SomeType')] to the class, or build the node by hand.
```

## Performance

Reflection runs **once per class per process**, cached in
`SchemaOrgMetadataFactory` — including the negative answer, so repeatedly asking
about an unmapped class is cheap too. Mapping 500 rows is one reflection pass, not
500.

The cache is per-process and in-memory. Under FrankenPHP worker mode it persists
across requests, which is the point; it is derived from code, so it cannot go
stale within a process.
