<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Setup\Support;

use Closure;
use Dmitryisaenko\LaraFoundry\Admin\Support\ActingAsSomeoneElse;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The first-login setup wizard (v0.49.0): which steps it asks, and when it opens.
 *
 * The one rule, read by the login listener, the shared `setup_wizard` prop and the
 * wizard's own endpoints. Lifted from a production app, where each guard below was
 * paid for by a bug.
 *
 * WHEN. After every login, not on every page, until the user presses "Done" or
 * "Don't show again" - existing accounts included. "Skip" closes it until the next
 * login and writes nothing on the account.
 *
 * WHAT. Steps are keys in one order (`order`, gaps of ten). Each belongs to a PART;
 * a part is finished as a whole (`users.setup_done`, `{part: when}`), so a step
 * added to a part later is asked only of people who have not finished it. The core
 * asks four steps in the `common` part; a host adds its own steps to that part or
 * to parts of its own, and may ask a part only on some requests (`askPartWhen`) -
 * a product's own steps on its own address.
 *
 * A SESSION KEY, NOT A FLASH. A login is usually followed by a redirect or two
 * before the page the user actually sees, and a flash is spent on the first of
 * them. The key stays until the wizard closes; the session ending with the logout
 * is what makes it once per login.
 *
 * Never while an operator is signed in as somebody else: the wizard would ask the
 * operator for the other person's name and photo, and "Don't show again" would be
 * pressed on their behalf. A host with a way of its own to act as a user registers
 * it on `Admin\Support\ActingAsSomeoneElse` - which also refuses the endpoints.
 *
 * Registered as a singleton, so steps and conditions registered at boot hold.
 */
class SetupWizard
{
    public const SESSION_KEY = 'larafoundry.setup_wizard.pending';

    /** Which login this session is - what a "Skip" is kept against. */
    public const LOGIN_KEY = 'larafoundry.setup_wizard.login';

    /** The part the core's own steps belong to. */
    public const COMMON = 'common';

    /**
     * Every step, by key: its part and its place in the wizard.
     *
     * @var array<string, array{part: string, order: int}>
     */
    protected array $steps = [
        'theme' => ['part' => self::COMMON, 'order' => 10],
        'locale' => ['part' => self::COMMON, 'order' => 20],
        'about' => ['part' => self::COMMON, 'order' => 70],
        'date' => ['part' => self::COMMON, 'order' => 80],
    ];

    /**
     * Parts asked only on some requests: part => fn (Request): bool.
     *
     * @var array<string, Closure>
     */
    protected array $partConditions = [];

    /**
     * Extra reasons not to open the wizard: fn (Request): bool, true hides it.
     *
     * @var array<int, Closure>
     */
    protected array $hiders = [];

    /**
     * Extra fields for the wizard's opening state: fn (Authenticatable): array.
     *
     * @var array<int, Closure>
     */
    protected array $stateFillers = [];

    /** The whole wizard on or off (`larafoundry.setup_wizard.enabled`). */
    public function enabled(): bool
    {
        return (bool) config('larafoundry.setup_wizard.enabled', true);
    }

    /**
     * Add a step, or move an existing one (the same key replaces it).
     *
     * The key is what the screen draws: the core's own dialog draws its four and any
     * step registered on the front with `registerSetupStep()`.
     */
    public function addStep(string $key, int $order, string $part = self::COMMON): self
    {
        $this->steps[$key] = ['part' => $part, 'order' => $order];

        return $this;
    }

    /** Drop a step - one of the core's included - from the wizard. */
    public function removeStep(string $key): self
    {
        unset($this->steps[$key]);

        return $this;
    }

    /**
     * Ask a part only on the requests the condition accepts (the other parts are
     * asked everywhere). Finishing the wizard on such a request finishes only the
     * parts asked there.
     *
     * @param  Closure(Request): bool  $when
     */
    public function askPartWhen(string $part, Closure $when): self
    {
        $this->partConditions[$part] = $when;

        return $this;
    }

    /**
     * One more reason to keep the wizard shut on a request.
     *
     * @param  Closure(Request): bool  $hide
     */
    public function hideWhen(Closure $hide): self
    {
        $this->hiders[] = $hide;

        return $this;
    }

    /**
     * More fields for what the wizard opens with (`GET setup-wizard/state`) - the
     * user's current values of a host's own steps.
     *
     * @param  Closure(Authenticatable): array<string, mixed>  $filler
     */
    public function addState(Closure $filler): self
    {
        $this->stateFillers[] = $filler;

        return $this;
    }

    /**
     * Every step key, in the wizard's order.
     *
     * @return list<string>
     */
    public function steps(): array
    {
        $steps = $this->steps;

        uasort($steps, static fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return array_keys($steps);
    }

    /**
     * The parts asked on this request, each with its steps in the wizard's order.
     * Null - no request, as at login - is every part there is.
     *
     * @return array<string, list<string>>
     */
    public function partsOn(?Request $request): array
    {
        $parts = [];

        foreach ($this->steps() as $key) {
            $part = $this->steps[$key]['part'];

            if ($request !== null && isset($this->partConditions[$part])
                && ! ($this->partConditions[$part])($request)) {
                continue;
            }

            $parts[$part][] = $key;
        }

        return $parts;
    }

    /**
     * The steps this user has still to see on this request, in the wizard's order.
     * Empty when every part asked here is finished.
     *
     * @return list<string>
     */
    public function stepsFor(Authenticatable $user, ?Request $request): array
    {
        $done = $this->marks($user);
        $wanted = [];

        foreach ($this->partsOn($request) as $part => $steps) {
            if (! isset($done[$part])) {
                $wanted = [...$wanted, ...$steps];
            }
        }

        return array_values(array_intersect($this->steps(), $wanted));
    }

    /** "Done" or "Don't show again": every part asked on this request, for good. */
    public function finish(Authenticatable $user, ?Request $request): void
    {
        $done = $this->marks($user);

        foreach (array_keys($this->partsOn($request)) as $part) {
            $done[$part] ??= now()->toDateTimeString();
        }

        $this->saveMarks($user, $done);
    }

    /**
     * "Go through the setup again": the parts asked on this request are offered
     * again - the common one included, so the user sees every step asked here.
     * Parts asked elsewhere stay finished.
     */
    public function reopen(Authenticatable $user, ?Request $request): void
    {
        $done = array_diff_key($this->marks($user), $this->partsOn($request));

        $this->saveMarks($user, $done);
    }

    /** Whether this request has any step at all to offer again from the profile. */
    public function canReopen(Request $request): bool
    {
        return $this->enabled() && ! $this->actedForSomeoneElse($request) && $this->partsOn($request) !== [];
    }

    /**
     * An operator signed in as the user (the core's impersonation, or a host's way
     * registered on ActingAsSomeoneElse): the wizard is neither offered nor written -
     * "Done" or "again" would land on the user's account (review 2026-10-31).
     */
    public function actedForSomeoneElse(Request $request): bool
    {
        return app(ActingAsSomeoneElse::class)->active($request);
    }

    /**
     * "Skip" for THIS login, kept outside the session.
     *
     * Forgetting the session key alone does not hold: when sessions live in the
     * database every request writes its whole copy back, so a request that read the
     * session before the skip and answered after it (the wizard's own state call, a
     * link pressed at once) puts the key back, and the wizard comes up again on the
     * next page. Kept against THIS LOGIN's mark, which a late write carries back
     * unchanged and the next login replaces. Not the session id: a session can be
     * given a new id without a new login, and the skip would quietly lapse.
     */
    public function skip(Request $request): void
    {
        Cache::put(
            $this->skipKey($request),
            true,
            now()->addMinutes(max(1, (int) config('session.lifetime', 120))),
        );
    }

    public function unskip(Request $request): void
    {
        Cache::forget($this->skipKey($request));
    }

    /**
     * Every kind of login - password, OAuth, a mailed link, QR, passkey, "remember
     * me" - fires this one event.
     *
     * Marked while ANY part is still unfinished: the login does not know which
     * address the user will open, and `offer()` asks the one they are on.
     */
    public function markLogin(Login $event): void
    {
        if (! $this->enabled() || $this->stepsFor($event->user, null) === []) {
            return;
        }

        if (app()->bound('session')) {
            session()->put(self::SESSION_KEY, true);
            session()->put(self::LOGIN_KEY, Str::random(32));
        }
    }

    /**
     * The steps the wizard opens with over this page, or false when it stays shut.
     *
     * False, never an empty list: the shell mounts the wizard on a truthy prop, and
     * an empty array is truthy in the page's JavaScript.
     *
     * @return list<string>|false
     */
    public function offer(Request $request): array|false
    {
        $user = $request->user();

        if (! $this->enabled() || $user === null || ! $request->hasSession()) {
            return false;
        }

        if ($this->actedForSomeoneElse($request)) {
            return false;
        }

        foreach ($this->hiders as $hide) {
            if ($hide($request)) {
                return false;
            }
        }

        if (! $request->session()->get(self::SESSION_KEY, false) || Cache::has($this->skipKey($request))) {
            return false;
        }

        $steps = $this->stepsFor($user, $request);

        return $steps === [] ? false : $steps;
    }

    /**
     * What the wizard opens with: the user's own values where they have them (an
     * existing account is shown ITS settings, not the defaults). Theme, date format
     * and language the page already has from the shared props.
     *
     * @return array<string, mixed>
     */
    public function state(Authenticatable $user): array
    {
        $state = [
            'name' => (string) $this->attribute($user, 'name'),
            'lastname' => (string) $this->attribute($user, 'lastname'),
            'email' => (string) $this->attribute($user, 'email'),
            'avatar_url' => $this->attribute($user, 'avatar_url'),
        ];

        foreach ($this->stateFillers as $filler) {
            $state = [...$state, ...$filler($user)];
        }

        return $state;
    }

    /**
     * @return array<string, string>
     */
    protected function marks(Authenticatable $user): array
    {
        $done = $this->attribute($user, 'setup_done');

        // A host model that does not merge the core's casts hands the JSON over raw.
        if (is_string($done)) {
            $done = json_decode($done, true);
        }

        return is_array($done) ? $done : [];
    }

    /**
     * @param  array<string, string>  $done
     */
    protected function saveMarks(Authenticatable $user, array $done): void
    {
        if (! $user instanceof Model) {
            return;
        }

        $value = $done === [] ? null : $done;

        if ($value !== null && ! $user->hasCast('setup_done')) {
            $value = json_encode($value, JSON_THROW_ON_ERROR);
        }

        $user->forceFill(['setup_done' => $value])->save();
    }

    protected function attribute(Authenticatable $user, string $key): mixed
    {
        return $user instanceof Model ? $user->getAttribute($key) : null;
    }

    protected function skipKey(Request $request): string
    {
        $login = $request->session()->get(self::LOGIN_KEY);

        if (! is_string($login) || $login === '') {
            $login = Str::random(32);
            $request->session()->put(self::LOGIN_KEY, $login);
        }

        return 'larafoundry.setup_wizard.skipped.'.$request->user()?->getAuthIdentifier().'.'.$login;
    }
}
