# SurvosSchemaOrgBundle

Symfony integration for [spatie/schema-org](https://github.com/spatie/schema-org).

One request-scoped graph that any controller, listener, or service can contribute
nodes to, rendered once as a single JSON-LD `@graph` script tag.

```bash
composer require survos/schema-org-bundle
```

## Why a graph and not one script tag per template

A page is rarely one thing. A movie page is a `WebSite`, a `WebPage`, a `Movie`,
the `Person`s who directed and starred in it, an `ImageObject`, an
`AggregateRating` — and the site-wide `Organization` that every page carries.
Those come from different places in the code, so emitting them means either
threading everything into one template variable or scattering `<script>` tags.

This bundle takes the first option and makes it painless: everyone writes into a
shared `SchemaOrgGraph`, and `render_schema_org()` emits the lot.

## Usage

Contribute nodes from anywhere:

```php
use Spatie\SchemaOrg\Schema;
use Survos\SchemaOrgBundle\Graph\SchemaOrgGraph;

final class MovieController extends AbstractController
{
    public function show(Movie $movie, SchemaOrgGraph $schemaOrg): Response
    {
        $schemaOrg->add(
            Schema::movie()
                ->identifier($this->generateUrl('movie_show', ['id' => $movie->getId()], UrlGeneratorInterface::ABSOLUTE_URL) . '#movie')
                ->name($movie->getTitle())
                ->director(Schema::person()->identifier('https://example.com/people/kubrick#person')->name('Stanley Kubrick')),
        );

        return $this->render('movie/show.html.twig', ['movie' => $movie]);
    }
}
```

Render it once, in `base.html.twig`:

```twig
{% block head %}
    {{ parent() }}
    {{ render_schema_org() }}
{% endblock %}
```

It returns an empty string when the page collected nothing, so it is safe to call
unconditionally. Pass a CSP nonce if the app needs one:
`{{ render_schema_org(csp_nonce) }}`.

To see what a page collected, use the **Schema.org** item in the web debug
toolbar — it shows the node count and opens a panel listing every node with the
full JSON-LD. The panel also reports whether the graph actually reached the page,
which is the one failure that is otherwise invisible: collecting nodes and
publishing none looks exactly like a page with no structured data.

### Without touching the constructor

```php
use Survos\SchemaOrgBundle\Graph\SchemaOrgAwareTrait;

final class MovieController extends AbstractController
{
    use SchemaOrgAwareTrait; // public SchemaOrgGraph $schemaOrg, via #[Required]
}
```

Prefer normal constructor injection when you're editing the constructor anyway.

## Identifiers and deduplication

`add()` keys each node by its own `@id` (spatie's `identifier()`), and the last
write wins. Contributing the same `@id` from two places updates one node instead
of duplicating it — which is what you want for the site-wide `Organization`, or a
`Person` who is both the director and an actor.

Nodes with no `@id` are never deduplicated; each `add()` is a new node. To dedupe
one anyway, pass an explicit key: `$schemaOrg->add($image, 'hero')`.

When several places each fill in *part* of a node, use `getOrCreate()`:

```php
$schemaOrg->getOrCreate(Organization::class, 'site')->name('Survos');
$schemaOrg->getOrCreate(Organization::class, 'site')->url('https://survos.com');
```

`graph()` returns the underlying `Spatie\SchemaOrg\Graph` for anything this
wrapper doesn't expose (`hide()`/`show()`, a custom `@context`).

## Attribute mapping

Annotate the entity and let `SchemaOrgMapper` build the node:

```php
use Survos\SchemaOrgBundle\Attribute\SchemaOrg;
use Survos\SchemaOrgBundle\Attribute\SchemaProperty;

#[SchemaOrg('Movie')]
final class Movie
{
    #[SchemaProperty('name')]        public ?string $title = null;
    #[SchemaProperty('description')] public ?string $overview = null;
    #[SchemaProperty('dateCreated')] public ?int $year = null;
    #[SchemaProperty('genre')]       public ?array $genres = null;

    // The column holds a name, not a Person — wrap it, and give the wrapped node
    // an @id so the same person is one node however many times they appear.
    #[SchemaProperty('director', as: 'Person', idPattern: '{base}/people/{value}')]
    public ?string $director = null;

    #[SchemaProperty('actor', as: 'Person', idPattern: '{base}/people/{value}')]
    public ?array $actors = null;
}
```

```php
$node = $mapper->add($movie, $canonicalUrl . '#movie', $siteUrl);
```

`#[SchemaProperty]` is orthogonal to `#[Field]` from `survos/field-bundle` — that
one describes grid/search/API exposure, this one describes JSON-LD meaning. They
coexist on the same property.

**What it handles:** scalars, `DateTimeInterface`, backed enums, lists, name-only
relations (`as:`), nested `#[SchemaOrg]` objects, and cycles. Null, empty-string,
and empty-list values are omitted rather than emitted as `null` — an absent
property is correct JSON-LD; `"name": null` is not.

Property hooks, `private(set)` properties, constructor-promoted `readonly`
properties, and zero-argument getters can all carry `#[SchemaProperty]`.
`#[SchemaOrg]` is inherited up the parent chain (nearest declaration wins), so a
DTO hierarchy needs it declared only where the type actually changes.

**Full reference: [docs/attributes.md](docs/attributes.md)** — every parameter,
what can carry the attribute, value handling, relations, references vs. embedding,
cycle behaviour, and where the declarative half stops.

**What it does not:** cross-links, canonical-URL-derived `@id`s, and conditional
rules stay hand-written. That's a deliberate boundary — expressing them in
attributes means inventing a DSL. `add()` returns the node, so the rest is
ordinary fluent spatie code:

```php
$node = $mapper->add($movie, $canonicalUrl . '#movie', $siteUrl);
$node->mainEntityOfPage($webPage->referenced());
```

Reflection runs once per class and is cached, so mapping 500 rows is one
reflection pass, not 500.

### References vs. embedding

A wrapped or nested node is by default added to the graph as its own top-level
node and linked by `@id` — that's what keeps one `Person` one node when several
entities share them. Pass `reference: false` to embed a copy instead.

A node with no `@id` can't be referenced, so it's embedded. The one exception is
closing a cycle (`A knows B knows A`): embedding there would put the node inside
itself and recurse until the stack ran out, so an un-`@id`'d back-link is dropped.
Give anything reachable in a cycle an `@id`.

## Worked examples

Four real consumers, in public repos, each exercising a different shape:

| App | Type | Shows |
|---|---|---|
| [survos-sites/packages](https://github.com/survos-sites/packages) — `src/Schema/PackageSchema.php` | `SoftwareSourceCode` | **Both halves together**: `#[SchemaProperty]` attributes on the entity for the scalars, hand-written code for authors/vendor/statistics. Plus `CollectionPage` + `ItemList` on the listing page, and `auto_inject` for a detail template that extends EasyAdmin's layout. |
| [survos-sites/bench](https://github.com/survos-sites/bench) — `src/Schema/MovieSchema.php` | `Movie` | Hand-written throughout. Name-only person columns, `AggregateRating`, `ImageObject`. |
| [survos/data-contracts](https://github.com/survos/data-contracts) — `src/Dto/Item/*` | 22 types | **Attributes only, and inherited**: `#[SchemaOrg]` on the DTO hierarchy, so `CartoonDto` resolves to `Drawing` three levels up. Every consumer of the contracts gets the mapping. |
| kpa — `src/Schema/SongSchema.php` | `MusicComposition` | Work-vs-recording modelling: one composition, N `MusicRecording`s each wrapping an `AudioObject`. Copyright, and lyrics parsed out of ChordPro. |

`packages` is the one to read first: it is the smallest, and it is the only one
that uses the attribute mapper and hand-written composition side by side.

### A note on picking types

`packages` deliberately models a Symfony bundle as `SoftwareSourceCode`, not
`SoftwareApplication` — a bundle is source you compose into an app, not an
application anyone installs and runs. That costs the Google software rich result,
which only `SoftwareApplication` gets. It also declines to turn a GitHub star
count into an `aggregateRating`, which *would* earn a rich result and would be a
number nobody stated; stars become `interactionStatistic` with a `LikeAction`
instead.

Both are the same trade: structured data is a claim about what something *is*.
Reach for the type that is true, not the type with the SERP feature.

## Commands

```bash
bin/console schema:map                       # every Doctrine entity
bin/console schema:map 'App\Entity\Package'  # one class
bin/console schema:map --unmapped            # also list classes with no #[SchemaOrg]
```

Prints what each mapped class maps to, property by property, **and the public
properties it doesn't map**. That last list is the point: a mapping covering six of
twenty-seven fields looks finished from the outside.

```bash
bin/console schema:validate https://packages.survos.com/packages/index
bin/console schema:validate https://example.com/x --dump
```

Fetches a URL, extracts every JSON-LD block, and checks it. **Errors** (exit 1):
malformed JSON, missing `@context`, a node with no `@type`, duplicate `@id`.
**Warnings**: a reference pointing at no node in the block, a null property value,
and an `@id`/`url` whose scheme disagrees with the page it came from — that last
one found a live reverse-proxy misconfiguration publishing `http://` identities on
an HTTPS site.

It deliberately does **not** check per-type "required" properties. Schema.org marks
nothing required; those lists belong to Google's rich-result program, change without
notice, and hardcoding them here would claim an authority this bundle doesn't have.
Use Google's validator for eligibility, this for correctness.

`JsonLdExtractor` and `JsonLdValidator` are public services, so you can assert on a
page's structured data from a functional test without shelling out.

## Configuration

```yaml
# config/packages/survos_schema_org.yaml
survos_schema_org:
    pretty_print: '%kernel.debug%'   # indent the JSON-LD
    auto_inject:  false              # insert before </head> with no Twig call
```

`pretty_print` defaults to `%kernel.debug%`, so there is normally nothing to
configure.

### auto_inject

With `auto_inject: true` a `kernel.response` listener inserts the script before
the first `</head>` on successful, non-streamed `text/html` responses — no Twig
call, whatever the layout.

It is **off by default on purpose**: `{{ render_schema_org() }}` is greppable, and
injected output is not. Turn it on for apps whose layout you'd rather not edit, or
that have several layouts.

A template that calls `render_schema_org()` suppresses the injection for that
request, so enabling it can never produce two `@graph` blocks. The listener skips
streamed, binary, compressed, `attachment`, non-HTML, redirect, and error
responses, and drops a now-stale `Content-Length`.

## Escaping

`render_schema_org()` encodes with `JSON_HEX_TAG`. A title or description
containing `</script>` therefore cannot close the tag early — the angle brackets
are emitted as JSON unicode escapes, which JSON-LD parsers read back as the
original characters. This is not optional hardening; it is the difference between
valid structured data and injected markup on any page with user-supplied text.

## Worker mode

`SchemaOrgGraph` is a service, so under FrankenPHP worker mode or RoadRunner it
outlives the request. `SchemaOrgResetListener` empties it on every main request
(priority 4096) so one page's nodes never leak into the next. Under php-fpm this
is a no-op.

## Changelog

[CHANGELOG.md](CHANGELOG.md). Note 2.24.12 **removed** `schema_org_debug()` and the
`debug_panel` config node in favour of the profiler data collector; a template still
calling it will 500.

## Tests

```bash
composer install && vendor/bin/phpunit
```
