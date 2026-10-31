# Zero for Laravel docs

Versioned v0.x documentation using [docs-base](https://github.com/nickwelsh/docs-base).
The shared package owns the Waku/Fumadocs application and rendering. This directory
owns site configuration, MDX guides, and Refquill-generated API JSON.

From the repository root:

```sh
pnpm install --frozen-lockfile
pnpm docs:dev
```

Open [localhost:3000/zero-for-laravel/](http://localhost:3000/zero-for-laravel/).

```sh
pnpm docs:generate
pnpm --filter @zero-for-laravel/docs api:validate
pnpm docs:typecheck
pnpm docs:build
pnpm docs:start
```

Production preview: [localhost:8080/zero-for-laravel/](http://localhost:8080/zero-for-laravel/).
Build and dev regenerate the API reference automatically. Commit
`generated/api.json` after API source changes; never edit or reformat it manually.
Refquill reads PHP from `packages/laravel/src` and TypeScript from the public core,
React, Inertia, and optional search entry points. All paths in `refquill.json` resolve
relative to this directory.

Authored guides live under `content/docs/0.x/docs`. Version and section navigation
come from `meta.json`. The generated reference is mounted at
`/zero-for-laravel/docs/0.x/api`; avoid authored pages under that prefix.

`waku.config.ts` sets the public origin to `https://docs.nickwelsh.me` with
`/zero-for-laravel` as the base path. Change those hosting values before deployment if
needed. The Waku request-handler patch supplied by docs-base is registered in the
root workspace config. The site uses the root pnpm version and lockfile.

## Cloudflare Workers deployment

The site is deployed as Workers Static Assets by the reusable `Deploy docs`
GitHub Actions workflow, called by the stable tagged release workflow after CI,
npm staging and PHP mirror tagging succeed. It waits for you to approve the
staged npm version and for that version to become public as npm's `latest`, then
builds the exact release commit. Main-branch CI and prereleases do not deploy.
If npm approval takes longer than one hour, approve the package and use
**Re-run failed jobs** on the release workflow. It checks npm's `latest` again
immediately before deployment so a superseded release cannot replace current docs.
To redeploy an existing stable release after fixing deployment settings, run
**Deploy docs** manually from `main` and supply its tag, such as `v0.1.0`.
It deploys that tag's source and applies the same npm `latest` checks.
`cloudflare.config.ts` owns the `zero-for-laravel-docs` Worker and only the
`docs.nickwelsh.me/zero-for-laravel` and `docs.nickwelsh.me/zero-for-laravel/*` routes.
The existing proxied DNS record for `docs.nickwelsh.me` must remain in place.

Create the GitHub environment `docs` and set:

| Setting                 | Type               | Value                                  |
| ----------------------- | ------------------ | -------------------------------------- |
| `CLOUDFLARE_API_TOKEN`  | Environment secret | Cloudflare deployment token            |
| `CLOUDFLARE_ACCOUNT_ID` | Environment secret | Account containing the Worker and zone |

The release workflow explicitly forwards these secret names to the reusable
docs workflow. GitHub requires that forwarding even for secrets stored in the
`docs` environment; the environment values take precedence.

For the first deployment, set the token's Workers **default permissions to Admin**
at the product scope: creating a Worker requires Admin and cannot be granted to a
Worker that does not exist. Also grant **Zone / Workers Routes / Write** and
**Zone / Zone / Read**, scoped to `nickwelsh.me`, so the CLI can resolve the zone
and attach the routes. After creation, Workers access can be narrowed to
**Editor** on `zero-for-laravel-docs`; retain route permissions while deployment
manages the routes. No DNS editing permission or runtime secrets are needed.
See [Cloudflare Workers roles](https://developers.cloudflare.com/workers/authorization/workers/).

To validate locally without credentials:

```sh
pnpm docs:build
pnpm --filter @zero-for-laravel/docs deploy:check
pnpm --filter @zero-for-laravel/docs exec vite preview
```

The separate Vite build packages Waku's `dist/public` into Cloudflare Build Output
under a `zero-for-laravel/` directory, without rebuilding the application or requiring
Cloudflare's account-gated `assets.basePath` feature. `cf deploy --prebuilt --mode production`
deploys those exact assets. `cf build` detects Waku and runs its framework build,
which does not produce this static Cloudflare package, so use `deploy:check`.

After setting the credentials locally, deploy the checked build with:

```sh
pnpm --filter @zero-for-laravel/docs run deploy
```
