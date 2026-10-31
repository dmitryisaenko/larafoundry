# First-login setup wizard

A step-by-step dialog that comes up over the user's own shell after every login and
walks them through the main settings, one step at a time. It keeps coming back
after each login until the user presses **Done** (on the last step) or **Don't show
again**; **Skip** closes it until the next login and writes nothing on the account.
Any step may be left as it is.

It is not the [onboarding checklist](onboarding.md): that one is a gentle list of
to-dos the host places on a page and never opens by itself. Both can live in one app.

The core asks four steps; a host adds its own, to the core's part or to parts of its
own, and may ask a part only on some requests (a product's own steps on its own
address).

## Contents

- [Install](#install)
- [Configuration](#configuration)
- [Usage](#usage)
- [API reference](#api-reference)
- [Security notes](#security-notes)
- [Host integration](#host-integration)
- [Testing](#testing)

## Install

The wizard ships with the core (module `src/Setup/`). One migration adds
`users.setup_done` (JSON, nullable) - guarded, so a host that already keeps a column
by that name keeps it and its data. The routes load from `routes/setup.php`, the
shared prop is part of the core's `HandleInertiaRequests`, and `AppLayout` mounts the
dialog. A host rendering through `AppLayout` / `LayoutSwitcher` has a working wizard
with no wiring; the cast `setup_done => array` comes with `laraFoundryCasts()`.

## Configuration

```php
// config/larafoundry.php
'setup_wizard' => [
    'enabled' => env('LARAFOUNDRY_SETUP_WIZARD_ENABLED', true),
],
```

Off: the routes are not registered at all (the paths stay free for a host's own
wizard), a login marks nothing, and the `setup_wizard` prop is always `false`.

## Usage

### The steps and their parts

Every step is a key with a place in one order (`order`, gaps of ten) and a part:

| Step | Order | Part | Writes through |
|------|-------|------|----------------|
| `theme` | 10 | `common` | `PUT /profile/ui-settings` (`theme`) |
| `locale` | 20 | `common` | `POST larafoundry/language` |
| `about` | 70 | `common` | Fortify `PUT /user/profile-information` (name, last name; only when changed) and `POST /profile/avatar` |
| `date` | 80 | `common` | `PUT /profile/ui-settings` (`date_format`) |

A part is finished as a whole: `users.setup_done` holds `{part: finished at}`. So a
step added to a part later is asked only of people who have not finished that part
yet - someone who went through the wizard does not see it come back for one new step.

### When it opens

- Every kind of login (password, OAuth, a mailed link, QR, passkey, "remember me")
  fires Laravel's `Login` event; while any part is unfinished, the listener puts a
  key in the session. The wizard opens over the next page the user sees - a session
  key, not a flash, because a login is usually followed by a redirect or two and a
  flash would be spent on the first of them.
- It opens only on pages whose request still has unfinished parts (see
  `askPartWhen`), and never while an operator is signed in as the user
  (impersonation, or a host's own way registered on
  `Admin\Support\ActingAsSomeoneElse`) or a host's own `hideWhen` condition holds.
  Signed in as somebody else, the endpoints `later` / `done` / `again` answer 403
  and the profile hub does not offer "again": the marks are the user's own.
- **Skip** is kept in the cache against this login's mark, not by removing the
  session key alone: with database sessions every request writes its whole copy
  back, and a request that read the session before the skip would put the key back.
  The next login has a new mark, so the wizard is offered again.
- **Done** / **Don't show again** finish every part asked on this request.
- **Go through the setup again** (the profile hub's Preferences tab, shown while
  the request has steps) clears the marks of the parts asked here and opens the
  wizard at once.

### Adding steps

```php
use Dmitryisaenko\LaraFoundry\Setup\Support\SetupWizard;

// app/Providers/AppServiceProvider.php
public function boot(): void
{
    app(SetupWizard::class)
        ->addStep('translate', 30)                      // into the common part
        ->addStep('learning', 40, 'lessons')            // a part of the host's own
        ->askPartWhen('lessons', fn ($request) => $request->getHost() === 'lessons.example.com')
        ->addState(fn ($user) => ['learning' => $user->learning_lang]);
}
```

and draw the step on the front:

```js
import { registerSetupStep } from '@dmitryisaenko/larafoundry';
import TranslateStep from './Setup/TranslateStep.vue';

registerSetupStep('translate', { component: TranslateStep, title: 'Translation' });
```

⚠️ A part's condition is asked of the wizard's own endpoints too (`/setup-wizard/done`,
`/again`), so it must look at the ADDRESS (`$request->getHost()`), never at the
path: a condition on the page's path is false on `/setup-wizard/done`, the part is
not finished, and it comes back at every login.

The step component receives the opening state as the `state` prop and saves through
its own endpoint, the way the core's steps do. If the server asks a step that nobody
registered on the front, the core's dialog does not open at all: the host is taken to
draw a wizard of its own, and opening without that step would let "Done" finish a part
whose steps the user never saw.

### A shell of the host's own

A host that registered its own shell with `registerAppShell()` gets no core dialog from `AppLayout` (the shell replaces the whole frame). Such a host, or one that does not render through `AppLayout` at all, mounts `<SetupWizardDialog
:steps="$page.props.setup_wizard">` in its shell while the prop is a list - or draws a
dialog of its own over the same prop and endpoints (`setupWizardCall('state' |
'later' | 'done' | 'again')` from the barrel). After `later` / `done` / `again`, read
the prop again (`router.reload({ only: ['setup_wizard'] })`): it goes `false` and the
dialog unmounts, so a later "again" mounts a fresh one.

## API reference

### `Setup\Support\SetupWizard` (singleton)

| Method | Purpose |
|--------|---------|
| `addStep(string $key, int $order, string $part = 'common')` | Add a step, or move an existing one. |
| `removeStep(string $key)` | Drop a step, the core's included. |
| `askPartWhen(string $part, Closure $when)` | Ask the part only on requests the condition accepts. |
| `hideWhen(Closure $hide)` | One more reason to keep the wizard shut on a request. |
| `addState(Closure $filler)` | More fields for `GET setup-wizard/state`. |
| `offer(Request $request): array\|false` | The steps to open with over this page, or false (the shared prop). |
| `stepsFor(Authenticatable $user, ?Request $request): array` | Steps still to ask; null request = every part. |
| `partsOn(?Request $request): array` | Parts asked on the request, with their steps. |
| `finish()` / `reopen()` / `skip()` / `unskip()` / `canReopen()` | What the endpoints call. |

### Endpoints (`web`, `auth`, `throttle:30,1`)

| Route | Name | Does |
|-------|------|------|
| `GET setup-wizard/state` | `larafoundry.setup-wizard.state` | The user's name, last name, e-mail, photo and the host's extra fields. |
| `POST setup-wizard/later` | `larafoundry.setup-wizard.later` | Skip until the next login. |
| `POST setup-wizard/done` | `larafoundry.setup-wizard.done` | Finish the parts asked here. |
| `POST setup-wizard/again` | `larafoundry.setup-wizard.again` | Clear those marks and open the wizard. |

### Shared prop

`setup_wizard` - a list of step keys, or `false`. Never an empty list: an empty array
is truthy in the page's JavaScript and would mount an empty dialog.

### Front end (barrel)

`SetupWizardDialog`, `registerSetupStep(key, { component, title })`, `setupSteps`,
`setupWizardCall(action)`.

## Security notes

- Every write goes through the endpoint that already owns the setting, with its own
  validation; the wizard's endpoints only write the user's own marks.
- The marks are the caller's own (`auth`), so a user cannot finish or reopen anybody
  else's wizard.
- Neither offered nor written under impersonation (or a host's own way of acting
  as a user, registered on `ActingAsSomeoneElse`): the operator would answer the
  user's wizard and "Don't show again" would land on the user's account.

## Host integration

Nothing is required for a host rendering through the core's `AppLayout`. A host with
its own shell mounts the dialog (or its own) as described above, and adds any way of
its own of acting as a user on `Admin\Support\ActingAsSomeoneElse::addCheck()` (it
also keeps "Set a password" from being used on the user's behalf).

## Testing

`tests/Feature/Setup/SetupWizardTest.php` covers the login mark (and none when
finished or switched off), the prop over the core's shared props, skip holding
against a late session write, done, again, the profile's "again" flag, impersonation
and `hideWhen`, host steps and parts asked only on some requests, a step added to a
finished part, and guests kept out. `tests-js/SetupWizardDialog.spec.js` covers the
dialog: the server's order, staying shut over a step it cannot draw, a registered host step, the theme saved at once,
skip and done reading the prop again, an expired session reported.
