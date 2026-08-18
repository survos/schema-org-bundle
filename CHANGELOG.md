# Changelog

Versions follow the survos/mono monorepo release, so numbers are shared with every
other Survos bundle and gaps are normal — a version with no entry here means the
release contained no change to this package.

## 2.24.19

### Docs

- Documented attribute **inheritance**, which had shipped in 2.24.15 undocumented.
  `#[SchemaOrg]` resolves up the parent chain (nearest declaration wins);
  `#[SchemaProperty]` needs nothing special because `getProperties()` already
  includes inherited properties. Also documents the trap it exposes: a base class
  shared by two schema.org branches can only carry `Thing`-level properties.
- `survos/data-contracts` added as a fourth worked example — the attributes-only,
  inherited case.

## 2.24.15

### Added

- `schema:map` — lists classes carrying `#[SchemaOrg]`, what each maps to, and
  **the public properties it does not map**. A mapping covering six of
  twenty-seven fields looks finished from the outside; this makes coverage visible.
- `schema:validate <url>` — fetches a URL and checks the JSON-LD it publishes.
  Errors on malformed JSON, missing `@context`, a node with no `@type`, or a
  duplicate `@id`; warns on a reference resolving to no node in the block, a null
  property value, and an `@id`/`url` whose scheme disagrees with the page it came
  from. Deliberately no per-type "required property" checks — schema.org marks
  nothing required, and those lists belong to Google's rich-result program.
- `JsonLdExtractor` and `JsonLdValidator` as public services, so an app can assert
  on its own structured data from a functional test without shelling out.
- `#[SchemaOrg]` is now inherited up the parent chain, so a DTO hierarchy declares
  it only where the type actually changes.

### Changed

- **A `#[SchemaProperty]` on a non-publicly-readable property now throws.** It was
  silently skipped, which emitted a plausible-looking node quietly missing a field.
  The message names the three ways to fix it. `private(set)` is unaffected — it
  reads as public.

### Dependencies

- `symfony/console` moved to `require` (the commands are a real feature).
  `doctrine/persistence` and `symfony/http-client` added to `require-dev`; both are
  optional at runtime, declared in `suggest`, and guarded at the call site.

## 2.24.13

### Added

- `SchemaOrgGraph` implements `ResetInterface`, so it is cleared by the
  `kernel.reset` tag. This matters for **`messenger:consume` workers**: a consumer
  never fires `kernel.request`, so the reset listener alone would not run once for
  the whole life of the process, and one message's nodes would still be in the
  graph for the next. The listener stays as well, guaranteeing a clean start even
  if the previous request died.

## 2.24.12

### Removed

- **`schema_org_debug()` and the `survos_schema_org.debug_panel` config node.**
  The floating `<details>` panel is replaced by a proper profiler data collector —
  a toolbar item with the node count and a panel listing every node plus the full
  JSON-LD.

  **Upgrading:** delete any `{{ schema_org_debug() }}` from your templates. Left in
  place it throws `Unknown "schema_org_debug" function` and the page 500s.

### Added

- Profiler data collector. The panel reports whether the graph **actually reached
  the page** — the one failure mode that is otherwise invisible, since collecting
  nodes and publishing none renders identically to a page with no structured data.

## 2.24.11

### Added

- **Attribute mapping**: `#[SchemaOrg]` on a class, `#[SchemaProperty]` on
  properties and zero-argument getters, and `SchemaOrgMapper` to build the node.
  Reflection is cached per class. Covers scalars, `DateTimeInterface`, backed
  enums, lists, name-only relations, nested mapped objects, and cycles. Null,
  empty-string and empty-list values are omitted rather than emitted as `null`.
- **`survos_schema_org.auto_inject`** (default `false`): inserts the JSON-LD before
  the first `</head>` on successful, non-streamed `text/html` responses, for apps
  whose layout you would rather not edit. A template that calls
  `render_schema_org()` suppresses the injection, so it can never double up.
- `SchemaOrgRenderer`, extracted so the Twig function and the listener share one
  encoder — the `JSON_HEX_TAG` script-breakout guard must not drift between copies.

## 2.24.10

Initial release.

- `SchemaOrgGraph` — request-scoped collector wrapping `Spatie\SchemaOrg\Graph`,
  keyed by each node's own `@id` with last-write-wins, so several places can
  contribute to one graph.
- `render_schema_org()` — the whole graph as one `@graph` script tag, encoded with
  `JSON_HEX_TAG` so a title containing `</script>` cannot break out.
- `SchemaOrgAwareTrait` — `#[Required]` property injection.
- `SchemaOrgResetListener` — clears the graph per main request, for worker mode.
