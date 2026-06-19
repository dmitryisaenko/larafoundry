# CLAUDE.md

This is the `dmitryisaenko/larafoundry` core package. The full dev-context for working **on the package** lives in [`AGENTS.md`](AGENTS.md) — read it first.

Quick orientation:

- **One service provider** (`src/LaraFoundryServiceProvider.php`, auto-discovered) is the spine — read it before any structural change.
- **Never edit the host.** Extend through seams (model traits, provider registries, config registries, published pages + the `@dmitryisaenko/larafoundry` barrel, core services). See `docs/README.md`.
- **Fail-closed** tenancy/security; **contracts + bindings** for anything swappable; **config-driven registries** over hard-coded lists.
- **Frontend:** Inertia + Vue 3 + Tailwind 4. Pages are *published* (`larafoundry-pages`); the shared library is the barrel `resources/js/index.js`, imported as `@dmitryisaenko/larafoundry` via a host **Vite alias**. There is **no npm package**.
- **Tests:** Pest on Testbench — `composer test`; lint — `composer lint`. DB-touching suites bind to `AuthTestCase` in `tests/Pest.php`. If config behaves oddly in tests, `composer dump-autoload` re-purges leaked testbench config.
- **Git is the maintainer's.** Bring the change to green + clean, run `/security-review` + `/code-review`, then hand over the commit name and semver tag — never run `git commit`/`tag`/`push`.

Consuming the package in a host app? See [`docs/integrating-into-an-existing-app.md`](docs/integrating-into-an-existing-app.md).
