# Workspace tooling

The repository root owns workspace tooling and cross-language contract tests.
`packages/laravel` owns the `nickwelsh/zero-for-laravel` Composer package, its source,
configuration, tests and PHP tools. `packages/client` owns the
`@nickwelsh/zero-for-laravel` npm package and its core and React exports.

`apps/docs` uses [`@nickwelsh/docs-base`](https://github.com/nickwelsh/docs-base)
for the shared Waku/Fumadocs application. It owns site configuration, v0.x guides,
and Refquill-generated PHP and TypeScript references. Run `pnpm docs:dev` at
`http://localhost:3000/zero-for-laravel/`, or `pnpm docs:build` followed by
`pnpm docs:start` for a production preview at `http://localhost:8080/zero-for-laravel/`.
Both dev and build regenerate the API reference. `pnpm docs:generate` regenerates
it independently; commit `apps/docs/generated/api.json` after source changes.
The Waku patch supplied by docs-base is registered in the root workspace config.
See [the site README](../apps/docs/README.md) for content and hosting configuration.

## npm releases

`Publish npm` (`.github/workflows/publish-npm.yml`) stages the client when a
GitHub Release is published. It runs CI, builds the package, checks exports, and
uses `npm stage publish` with npm provenance and OIDC. No `NPM_TOKEN` or
`NODE_AUTH_TOKEN` secret is required.

The bootstrap package `@nickwelsh/zero-for-laravel@0.0.0-placeholder.0` contains only
`package.json` and uses the `bootstrap` dist-tag. It reserves the package name for
trusted publishing and is not an installable client release. Stable releases use
the `latest` tag, starting with **0.1.0**; installation guides use the unversioned
package name to select that tag.

Create the GitHub environment `npm`. In the npm package's trusted-publisher
settings, choose GitHub Actions and use these exact values:

| Field                | Value                       |
| -------------------- | --------------------------- |
| Organization or user | `nickwelsh`                 |
| Repository           | `zero-for-laravel-monorepo` |
| Workflow filename    | `publish-npm.yml`           |
| Environment name     | `npm`                       |
| Allowed action       | Allow `npm stage publish`   |

Allow staged publishing only; disable direct publishing for this trusted
publisher. See [npm trusted publishing](https://docs.npmjs.com/trusted-publishers/).
The publishing job declares `contents: read` and `id-token: write`; the repository's
default workflow permissions can remain read-only. Repository Admin access is
needed for initial environment and secret setup; package-owner access is used to
configure npm. Do not grant an admin token to the publish job.

The client package version is `0.4.0`. To release, set the version in
`packages/client/package.json` and publish a matching GitHub Release tagged
`v<version>` (for example `v0.4.0`) from `main`. Stable releases
publish to `latest`; prerelease versions (for example `0.2.0-beta.1`) must be marked
as GitHub prereleases and use `next`.

After CI stages a version, review it in npm and approve it with your package-owner
account and 2FA. From an authenticated local npm CLI, use
`npm stage list @nickwelsh/zero-for-laravel`, `npm stage view <stage-id>` (or
`npm stage download <stage-id>`), then `npm stage approve <stage-id>`. CI never
approves staged packages. The PHP mirror tag is published after CI; docs wait
until the approved stable version is publicly available as npm's `latest`.
If approval takes longer than one hour, use **Re-run failed jobs** on the release
workflow after approval. Successful staging and mirror jobs are not rerun.
Prereleases do not replace production docs. Main-branch CI continues validating
the docs build without deploying it.

## Dependency maintenance

`.github/dependabot.yml` schedules weekly updates for the complete pnpm workspace,
both Composer projects, and GitHub Actions. Vite tooling and the docs framework
dependencies are grouped for compatibility review. Dependabot security updates
are enabled on the source repository; dependency PRs still require review and CI.

`pnpm-workspace.yaml` contains targeted security overrides where upstream
dependencies still pin vulnerable releases. Remove each override once its owner
adopts a fixed version. These overrides protect this workspace; npm consumers
manage their own Zero peer dependency and its transitive dependencies.

The library's Composer manifest excludes PHPStan 2.3.4: with Rector 2.8.2,
its PHPDoc parser passes a `FullyQualifiedNameNode` to a constructor expecting
`IdentifierTypeNode`, crashing `rector:check`. PHPStan 2.3.3 passes the same check.
This exact-version conflict also applies to CI's fresh Composer updates; remove
it after verifying a compatible upstream release.

Run `pnpm audit` and `composer audit` in both Composer directories when reviewing
updates. The npm audit also reports GHSA-vfj7-8cjw-p6xm in `braces` 3.0.3, which
has no published fix and no upstream fix planned. It affects deeply nested glob
patterns in development lint tooling. This repository supplies checked-in glob
patterns, without taking patterns from application users. This is an accepted
development-tooling risk for that trusted input; reassess if glob patterns ever
come from untrusted sources.

## Composer distribution

The PHP package is named `nickwelsh/zero-for-laravel`. Its current `composer.json` is
under `packages/laravel`; Packagist.org requires it at the repository root.
See [Packagist's package requirements](https://packagist.org/about).

The source of truth is `nickwelsh/zero-for-laravel-monorepo`. The public
`nickwelsh/zero-for-laravel` repository is an automated subtree split of
`packages/laravel`; never edit the mirror directly. Its README sends contributors
and issues back to the monorepo.

`Sync PHP mirror` (`.github/workflows/sync-php.yml`) updates the mirror's `main`
branch after CI passes on the latest monorepo `main`. It can also be run manually.
The npm release workflow calls the same workflow after CI passes to publish the
matching release tag to the mirror. Pushes never force-update branches or tags.

The repository secret `PHP_MIRROR_SSH_KEY` contains a deploy key with write access
only to the PHP mirror. The normal `GITHUB_TOKEN` cannot push to another repository.
Development files and the lockfile remain in mirror history but are excluded from
Composer distribution archives by `packages/laravel/.gitattributes`.

Submit `https://github.com/nickwelsh/zero-for-laravel` to Packagist.org as
`nickwelsh/zero-for-laravel`, then enable Packagist's GitHub hook for that mirror.
Packagist discovers versions from its tags; do not add a Composer `version` field.
The PHP package and npm package share release tags from the monorepo. Neither
publishing workflow changes package versions automatically.

## Development checks

Use PHP 8.3+, Composer, Node 22.18+ (22.x) or 24.11+, and the pinned pnpm version. The
Composer platform is pinned to PHP 8.3 so development dependencies remain usable
on the minimum supported runtime. Laravel 12 and 13 are tested in CI.

```sh
composer --working-dir=packages/laravel install
pnpm install --frozen-lockfile
pnpm check
```

`pnpm check` checks formatting, lint, PHPStan, Rector, spelling, TypeScript, Pest,
frontend tests, generated-contract drift, the build, and package export isolation.
Set `ZERO_TEST_PG_DATABASE=laravel_zero_test` to include PostgreSQL tests. The
database must be empty and disposable. CI always supplies it. Do not run two PHP
test suites against the same database concurrently.

| Command                        | Purpose                                                          |
| ------------------------------ | ---------------------------------------------------------------- |
| `pnpm format` / `format:check` | Oxfmt for workspace files and Pint for PHP                       |
| `pnpm lint`                    | Apply frontend fixes and Markdown fixes                          |
| `pnpm lint:check`              | Frontend, Markdown, PHPStan, Rector dry run and Peck             |
| `pnpm typecheck`               | Strict TypeScript checking for package contracts and docs        |
| `pnpm test`                    | Vite+ tests, including React and PHP/native Zero AST comparisons |
| `pnpm php:test`                | Pest with Testbench and optional PostgreSQL integration tests    |
| `pnpm php:rector`              | Apply PHP 8.3 / Laravel 12-compatible Rector changes             |
| `pnpm build`                   | Vite+ library packaging and Waku documentation build             |
| `pnpm contracts:check`         | Verify generated TypeScript matches PHP model metadata           |
| `pnpm package:check`           | Verify built exports and load the core with React blocked        |

Coverage is opt-in locally:

```sh
pnpm test:coverage
XDEBUG_MODE=coverage pnpm php:test:coverage
```

Frontend reports go to `coverage/client`; PHP reports go to
`packages/laravel/coverage`. PHP needs Xdebug or PCOV. Include PostgreSQL when
measuring the full suite. CI saves both reports. There is no artificial 100%
coverage or type-coverage gate; behavior tests and level-10 analysis are required.

## Amp orbs

The executable `.agents/setup` installs PHP 8.5 and its extensions, Composer,
the pinned pnpm version, both Composer projects (including development tools),
and the locked pnpm workspace. It keeps a compatible preinstalled Node runtime
or installs Node 24. It also generates the example's ignored Wayfinder imports
and builds the client package so workspace exports resolve immediately.
Amp snapshots these dependencies; warm setup checks the lockfiles without
redownloading unchanged packages. Setup can be rerun from the repository root.

PostgreSQL runs under systemd, with logical replication enabled and durability
disabled for disposable orb data. Only loopback connections as the `postgres`
test role use passwordless authentication. Repository-scoped login shells default
`ZERO_TEST_PG_DATABASE` to `laravel_zero_test`, `ZERO_TEST_PG_HOST` to `127.0.0.1`,
and `ZERO_TEST_PG_USER` to `postgres`, enabling the PostgreSQL tests in `pnpm check`.
Existing environment overrides are preserved. `.agents/resume` checks database
readiness and starts a stopped cluster; it never installs dependencies.

Setup copies the todos environment template only when `.env` is absent. It does
not migrate or seed the example, generate shared application secrets, authenticate
deployment tools, or start web servers. Configure the example's database, app key,
cache key, and public URLs per thread before running it. Use supervised orb
services and portals rather than the template's developer-specific Herd URLs.
Do not use the orb PostgreSQL configuration for production or durable data.

## Adaptations from the application configs

- **Vite+ replaces direct Vitest, Prettier and tsup tooling.** `vp pack` builds the
  library; an application bundle, dev server, SSR build and Laravel Vite plugin
  are unnecessary. Export paths and the optional React peer remain unchanged.
- **Application integrations are omitted:** Inertia, Wayfinder, Tailwind,
  auto-imports, icons, application aliases and application dependency prebundling.
  React Compiler transformation and its blanket compiler lint rules are omitted;
  consumers control compilation. Standard hook rules and dependency checks remain.
- **Stylelint and Tailwind sorting are omitted:** there is no CSS or Tailwind
  stylesheet. Browser-compatibility and HTML baseline plugins are omitted until
  supported browser targets are defined; JSX accessibility checks remain enabled.
- **Lint categories:** correctness and suspicious rules are enabled. Blanket
  nursery, pedantic, performance, restriction and style presets are omitted.
  Regex rules, focused-test detection, native-prototype protection, type imports,
  and type-aware checking are enabled explicitly. Automatic JSX runtime disables
  `react-in-jsx-scope`. The official hook plugin replaces duplicate native memo
  dependency diagnostics. Compiler `refs`, `immutability` and `globals` rules are
  disabled only in files containing the existing mutation identity guard, external store
  factories and test harnesses. Unsafe-assertion and redundant-generic-argument
  bans are disabled because generated registry bridges require mapped-type
  assertions. A documented exception preserves the public filter tuple generic.
  Compile-only fixtures permit unused values and expressions; tests
  permit locally scoped harness functions. One documented hook dependency exception
  preserves filter store identity when equivalent names/options are recreated.
- **Generated fixtures** are excluded from formatting and lint transformations;
  `contracts:check` verifies their exact bytes, TypeScript checks their contracts,
  and integration tests execute them. Vendor, build output and lockfile formatting
  are excluded. Markdown keeps the reference's disabled line-length rule.
- **Pint keeps the Laravel preset and extra strict rules**, except automatic
  finalization (`final_class`, `final_internal_class`,
  `final_public_method_for_abstract_class`) and `protected_to_private`, which would
  obstruct package extension, Eloquent fixtures and Testbench overrides.
  `mb_str_functions` is disabled because filesystem offsets and protocol lengths
  intentionally use byte semantics. TestCase is formatted instead of excluded.
- **PHPStan runs at level 10 on source and tests without a baseline.** PHPDoc types
  are not assumed certain, preserving runtime validation for untyped consumers.
  Pest 4 closure bindings are explicitly documented in the tests.
- **Pest 5 PHPStan/Rector plugins are omitted:** their available releases require
  PHP 8.4 and Pest 5. Pest 4 and its bundled PHPStan extension preserve PHP 8.3
  support. Browser/stress testing plugins and application-only Composer hooks are
  unnecessary for this library. Tests run serially because PostgreSQL fixtures own
  a shared disposable schema; the reference's parallel flag is omitted.
- **Rector targets PHP 8.3 and Laravel 12**, rather than PHP 8.5 / Laravel 13.
  Naming, privatization, Carbon and blanket PHPDoc rewrite sets are omitted to
  preserve public APIs, framework overrides and date behavior. Laravel exception
  helper rewrites are omitted to retain explicit guards and type narrowing.
  Three rule exceptions preserve exception codes, property initialization checks,
  and Pint's import/trait formatting; their exact names are in `rector.php`.
- **Peck checks package source.** Pest feature files execute test registration
  when reflected by Peck, so they are covered by Pest, Pint and PHPStan instead.
  The spelling allowlist contains project/protocol terms rather than application
  business names. Vendor and caches are ignored.

The Vite+ core alias uses its wrapper version, so pnpm permits that exact version
for Vitest's Vite peer requirements. Vite+ and its core alias are pinned together.

## Publishing

Publish releases from the source monorepo. npm publishes `packages/client` as
`@nickwelsh/zero-for-laravel`; Packagist indexes the generated PHP mirror as
`nickwelsh/zero-for-laravel`. See the release setup above and
[the docs deployment guide](../apps/docs/README.md#cloudflare-workers-deployment).
