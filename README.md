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
{% block javascripts %}
    {{ render_schema_org() }}
    {{ schema_org_debug() }}
{% endblock %}
```

Both return an empty string when the page collected nothing, so they are safe to
call unconditionally. Pass a CSP nonce if the app needs one:
`{{ render_schema_org(csp_nonce) }}`.

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

## Configuration

```yaml
# config/packages/survos_schema_org.yaml
survos_schema_org:
    pretty_print: '%kernel.debug%'   # indent the JSON-LD
    debug_panel:  '%kernel.debug%'   # let schema_org_debug() render
    auto_inject:  false              # insert before </head> with no Twig call
```

The first two default to `%kernel.debug%`, so there is normally nothing to
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

It does **not** inject `schema_org_debug()` — that panel is a dev aid you place
yourself; auto-inject handles the semantic payload only.

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

## Tests

```bash
composer install && vendor/bin/phpunit
```
