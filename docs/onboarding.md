# Onboarding checklist

The onboarding checklist is a gentle, dismissible getting-started guide for a new
user. It is built on the same provider-registry shape as the dashboard widgets: the
backend collects steps from registered providers, reads each step's completion from
**live state** (never a stored "done" flag), and ships the result as a shared
Inertia prop a Vue component renders. It nudges, it never forces. Every completion
is computed from the real world, so a step un-checks if the user later clears it,
and the whole checklist renders **nothing** the moment there is nothing left to
nudge - for a guest, the operator, a dismissed user, an all-complete user, or a user
with no steps at all. A host grows it by registering its own provider; it edits
nothing in the core.

## Contents

- [Install](#install)
- [Configuration](#configuration)
- [Usage](#usage)
- [API reference](#api-reference)
- [Security notes](#security-notes)
- [Host integration](#host-integration)
- [Testing](#testing)

## Install

The onboarding checklist ships with the core package (module `src/Onboarding/`);
there is nothing extra to require and **no new migration** (the dismiss flag lives in
the existing `larafoundry_settings` table). The `OnboardingBuilder` is registered as
a singleton and already carries the core step provider; the dismiss route loads
automatically from `routes/onboarding.php`. A host opts in by:

1. Merging the onboarding shared prop into its `HandleInertiaRequests::share()`
   (below), so every page receives the checklist state.
2. Placing the `<OnboardingChecklist>` component on its home page.
3. Optionally registering one or more `OnboardingStepProviderInterface` classes to
   add its own steps.

The two required steps are the [Host integration](#host-integration) section below.

## Configuration

The checklist has no config block of its own. Two existing configs shape it:

- The dismiss flag is registered in the `settings` registry
  (`config/larafoundry.php`) as `onboarding.dismissed` (scope `user`, type
  `boolean`, `form => false` so it never surfaces on the settings form). It is
  written through the settings store, not by hand.
- The last two core steps are teams-only and are omitted when
  `larafoundry.tenancy.mode` is `personal`.

## Usage

### The core steps

`CoreOnboardingStepProvider` ships four auto-detected steps. Each reads live state,
so completion is always the truth of the moment, never a flag that can go stale:

| Step | Complete when |
|------|---------------|
| Complete your profile | The user's name and last name are set. |
| Enable two-factor | `hasEnabledTwoFactorAuthentication()` is true. |
| Set up your company (teams only) | The active company is out of setup (`! isInSetup()`). |
| Invite a teammate (teams only) | The active company has more than one member, or a pending invitation. |

The last two steps are teams-only and are omitted in `personal` tenancy mode, so a
single-user install is never nagged about companies it does not have.

### When the checklist shows nothing

The build is deliberately quiet at both ends:

- `OnboardingBuilder::build()` returns **null** for a guest or the platform
  super-admin (the operator is not onboarding).
- For a user who has dismissed the checklist it **short-circuits** on one settings
  read - no per-step queries run.
- The `<OnboardingChecklist>` component renders **nothing** for a guest, the
  operator, a dismissed user, a user who has completed every step, or a user with no
  steps. Nothing is ever forced onto the screen.

### Dismissing

A user closes the checklist for good with a `POST /onboarding/dismiss` request
(`routes/onboarding.php`, web + auth). The endpoint writes the per-user
`onboarding.dismissed` boolean through the settings store. There is **no new
migration**: the flag lives in the existing `larafoundry_settings` table.

### The shared prop and the component

`LaraFoundryOnboarding::sharedProps()` ships the checklist as the `onboarding`
Inertia shared prop. The `<OnboardingChecklist>` Vue component (exported from the
package barrel) consumes it and renders the steps, their live completion state and
the dismiss control:

```js
import { OnboardingChecklist } from '@dmitryisaenko/larafoundry';
```

### Adding your own steps

A host adds steps by registering an `OnboardingStepProviderInterface` on the shared
`OnboardingBuilder`, exactly like a navigation `MenuProvider` or a dashboard widget
provider:

```php
use Dmitryisaenko\LaraFoundry\Onboarding\Contracts\OnboardingStepProviderInterface;
use Dmitryisaenko\LaraFoundry\Onboarding\Support\OnboardingBuilder;

class ImportDataStepProvider implements OnboardingStepProviderInterface
{
    // return the step(s) this provider contributes, each with its
    // live-state completion check
}

// app/Providers/AppServiceProvider.php
public function boot(): void
{
    $this->app->make(OnboardingBuilder::class)->addProvider(new ImportDataStepProvider);
}
```

Your provider's completion check should read live state too, so a host step behaves
like a core one: it re-checks itself every request and never depends on a stored
flag.

## API reference

### `LaraFoundryOnboarding` (host wiring helper)

| Method | Returns | Purpose |
|--------|---------|---------|
| `sharedProps()` | `array<string, Closure>` | The `onboarding` Inertia prop, lazily evaluated. Merge into `HandleInertiaRequests::share()`. |

### `OnboardingBuilder` (singleton)

| Method | Signature | Purpose |
|--------|-----------|---------|
| `addProvider` | `addProvider(OnboardingStepProviderInterface $provider): self` | Register a provider that contributes steps. |
| `build` | `build(?Authenticatable $user = null): ?array` | Collect steps, resolve their live completion, serialise. Returns null for a guest or the super-admin; short-circuits for a dismissed user. |

### `OnboardingStepProviderInterface`

| Method | Returns | Purpose |
|--------|---------|---------|
| `getSteps` | `array` | The step(s) this provider contributes, each carrying its live-state completion check. |

The core provider is `CoreOnboardingStepProvider` (the four steps above; the last
two teams-only).

### Vue component

`<OnboardingChecklist>` (from the `@dmitryisaenko/larafoundry` barrel) reads the
`onboarding` shared prop, renders the steps with their live completion state, and
posts to the dismiss endpoint. It renders nothing when there is nothing to nudge.

## Security notes

- **The operator and guests are excluded.** The build returns null for a guest and
  for the platform super-admin, so the checklist is never assembled for an identity
  it does not apply to.
- **Completion is read from live state, never trusted from the client.** Each step's
  done-ness is computed server-side from real state (profile fields, 2FA, company
  setup, membership), so a client cannot mark a step complete, and a step that was
  true un-checks itself if the underlying state is later cleared.
- **Dismiss is per-user and scoped.** The dismiss endpoint is behind web + auth and
  writes the caller's own `onboarding.dismissed` setting through the fail-closed
  settings registry (a declared, user-scoped, boolean key), so it cannot write any
  other key or another user's flag.

## Host integration

Two steps are required for a host.

1. **Merge the shared prop.** Add the onboarding prop to the host's
   `HandleInertiaRequests::share()`:

   ```php
   use Dmitryisaenko\LaraFoundry\Onboarding\LaraFoundryOnboarding;

   public function share(Request $request): array
   {
       return [
           ...parent::share($request),
           ...LaraFoundryOnboarding::sharedProps(),
       ];
   }
   ```

2. **Place the component.** Drop `<OnboardingChecklist>` onto the host's home page:

   ```vue
   <script setup>
   import { OnboardingChecklist } from '@dmitryisaenko/larafoundry';
   </script>

   <template>
       <OnboardingChecklist />
   </template>
   ```

The dismiss route and the step registry ship in the package; there is no new
migration. A host adds its own steps by registering a provider on
`app(OnboardingBuilder::class)`.

## Testing

The onboarding suite lives in `tests/Feature/Onboarding/`. It covers the four core
steps and their live-state completion; the teams-only gating (the last two steps
omitted in `personal` mode); the build returning null for a guest and the operator,
and short-circuiting for a dismissed user; the dismiss endpoint writing the per-user
flag through the settings store; and the "renders nothing when there is nothing to
nudge" guarantee for a dismissed, all-complete or step-less user. Every module
passes `/security-review` + `/code-review` before its tag.

Run them with Pest:

```bash
composer test
```
