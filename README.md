# Brain Monkey Doubles

Testing a WordPress plugin without booting WordPress means faking the WP functions and hooks your code calls. [Brain Monkey](https://brain-wp.github.io/BrainMonkey/) is the standard library for that. Brain Monkey Doubles fills two gaps it leaves:

- **Functions you can stub *and* assert.** Give a function a default return value, then still assert elsewhere that it was called with specific arguments. Brain Monkey makes you pick one or the other.
- **Hooks that actually run.** Your registered `add_filter` / `add_action` callbacks execute during the test, instead of being recorded but ignored.

> **Brain Monkey basics:** it stubs WordPress functions with `when()`, asserts calls with `expect()`, and tracks hooks with `Filters\expectApplied` / `Actions\expectDone` — all without loading WordPress. See its [docs](https://brain-wp.github.io/BrainMonkey/).

## Installing

```bash
composer require --dev pfaciana/brain-monkey-doubles
```

Requires PHP ≥ 8.1 and `brain/monkey` ^2.7. Examples below use Pest 4.

## Setting up

Wire it into your test bootstrap. `Doubles::setUp()` runs **before** `Monkey\setUp()`, and teardown unwinds in reverse. This enables every double; the function helpers below also work under plain Brain Monkey.

```php
// tests/Pest.php
use Brain\Monkey;
use BrainMonkey\Doubles;

pest()->beforeEach(function () {
    Doubles::setUp();   // installs the doubles first
    Monkey\setUp();
})->afterEach(function () {
    Monkey\tearDown();
    Doubles::tearDown();
});
```

> **Order matters.** `Doubles::setUp()` defines `add_filter` and friends itself. Call it *after* `Monkey\setUp()` (or with real WordPress loaded) and it throws `RuntimeException` because `add_filter` already exists.

### Process isolation

Brain Monkey Doubles defines WordPress hook functions such as `add_filter()` and `apply_filters()` in PHP's global namespace. PHP cannot unload global functions once they are defined, so `Doubles::tearDown()` resets hook state but does not remove those functions.

Do not bootstrap real WordPress later in the same PHP process after enabling `Doubles`. If a suite mixes Brain Monkey tests and real WordPress integration tests, run them in separate PHPUnit/Pest processes, separate commands, or separate CI jobs.

## Doubling functions

Brain Monkey's `when()` and `expect()` don't compose: once `when()` stubs a function, calling `expect()` on that same function silently no-ops — the mock is never routed, so the assertion fails quietly at teardown. These two helpers fix that. Import the namespace:

```php
use BrainMonkey\Functions;
```

| Helper          | vs Brain Monkey                           | Why it exists                                                          |
|-----------------|-------------------------------------------|------------------------------------------------------------------------|
| `stub($name)`   | a re-callable alias of `Functions\when()` | a later layer (a file's `beforeEach`) overrides an earlier global one  |
| `expect($name)` | `Functions\expect()` that survives a stub | forces the Patchwork re-route BM skips once `when()` owns the function |

`stub()` sets a function's baseline, and it's re-callable — define broad defaults globally, then override them in a narrower scope. It returns Brain Monkey's `FunctionStub`, so the full `when()` API works: `justReturn()`, `returnArg()`, `alias()`, `justEcho()`, `echoArg()`.

```php
// global beforeEach — silence WP notices and grant caps for every test
Functions\stub('_doing_it_wrong')->justReturn();
Functions\stub('current_user_can')->alias(fn() => true);

// one file's beforeEach — revoke caps for these tests only
Functions\stub('current_user_can')->alias(fn() => false);
```

| Scope               | Helper         | Purpose                         |
|---------------------|----------------|---------------------------------|
| Global `beforeEach` | `stub()`       | baseline behavior for all tests |
| File `beforeEach`   | `stub()` again | override it for one file        |
| `it()`              | `expect()`     | assert calls/args for one test  |

`expect()` asserts a function **even when a `stub()` / `when()` baseline already exists**, and returns the Mockery expectation for chaining:

```php
it('asserts a call that already has a stub baseline', function () {
    // native Brain Monkey expect() would silently never fire over the stub above — but this does!
    Functions\expect('current_user_can')
        ->once()
        ->with('edit_posts')
        ->andReturn(true);

    expect(current_user_can('edit_posts'))->toBeTrue();
});
```

> **Heads-up:** call `Functions\expect()` (namespace-qualified), never `use function BrainMonkey\Functions\expect;`. A bare function import shadows Pest's global `expect()` and breaks every `expect()->toBe()` in the file.

> **Caveat:** once asserted this way, the function is fully owned by the mock for the rest of the test — the `when()` baseline no longer answers other arguments.

## Doubling hooks

The `'hooks'` double runs your registered callbacks for real — vanilla Brain Monkey records hooks but never fires them. So your plugin's hooks behave in a test the way they do in WordPress.

Say your plugin registers a filter:

```php
// greeting.php — your plugin
namespace Greeting;

function register(): void {
    add_filter('greeting', fn($name) => "Hello, $name");
}
```

Load it before each test. From here the test bodies only fire hooks and assert — `add_filter` stays in the plugin, where it belongs:

```php
// GreetingTest.php
use Brain\Monkey\Filters;

beforeEach(fn() => Greeting\register());
```

**A filter actually runs.** Fire it; your callback transforms the value:

```php
it('runs the filter callback', function () {
    expect(apply_filters('greeting', 'World'))->toBe('Hello, World');
});
```

**Assert it was applied.** `expectApplied` is the everyday check — was the filter applied, with these args:

```php
it('applies the greeting filter', function () {
    Filters\expectApplied('greeting')->once()->with('World');

    apply_filters('greeting', 'World');
});
```

**Check it's wired up — without running it.** `has_filter` asks only whether the hook was registered:

```php
it('registers the greeting filter', function () {
    expect(has_filter('greeting'))->toBeTrue();
});
```

**Count how many times it ran.** `did_filter` returns the number of times the hook fired:

```php
it('fires once per apply', function () {
    apply_filters('greeting', 'World');
    apply_filters('greeting', 'Cleveland!');

    expect(did_filter('greeting'))->toBe(2);
});
```

Actions mirror filters: `add_action` / `do_action`, asserted with `Actions\expectDone`, counted with `did_action`.

### vs vanilla Brain Monkey

| Call                                           | Vanilla Brain Monkey          | Brain Monkey Doubles                     |
|------------------------------------------------|-------------------------------|------------------------------------------|
| `add_filter` / `add_action`                    | recorded, callback never runs | recorded **and** registered to run       |
| `apply_filters`                                | returns input unchanged       | runs the real callback chain             |
| `do_action`                                    | no-op (counts only)           | runs the real callbacks                  |
| `expectApplied()->andReturn()`                 | overrides the return          | overrides the return (callbacks skipped) |
| `has_filter` / `did_action` / `current_filter` | partial                       | full WP behavior                         |

### Covered functions

The full WordPress hook API is shimmed. Each is defined only if it doesn't already exist, so a real WP function of the same name wins.

**Filters**

| Function                                                     | Behavior                                              |
|--------------------------------------------------------------|-------------------------------------------------------|
| `add_filter($hook, $cb, $priority = 10, $accepted_args = 1)` | Registers `$cb` in real `WP_Hook`; tracked as added   |
| `apply_filters($hook, $value, ...$args)`                     | Runs the callback chain; return expectation overrides |
| `apply_filters_ref_array($hook, $args)`                      | Same, args passed as an array                         |
| `has_filter($hook, $cb = false, $priority = false)`          | Real registration check (priority or bool)            |
| `remove_filter($hook, $cb, $priority = 10)`                  | Removes from `WP_Hook`; tracked as removed            |
| `remove_all_filters($hook, $priority = false)`               | Clears callbacks **and** Brain Monkey's added-records |
| `current_filter()` / `doing_filter($hook = null)`            | Current hook name / is-it-running                     |
| `did_filter($hook)`                                          | Times applied                                         |
| `apply_filters_deprecated($hook, $args, $version, ...)`      | Tracked + applied even with no callbacks              |

**Actions**

| Function                                                     | Behavior                                          |
|--------------------------------------------------------------|---------------------------------------------------|
| `add_action($hook, $cb, $priority = 10, $accepted_args = 1)` | Like `add_filter`                                 |
| `do_action($hook, ...$args)`                                 | Runs callbacks, increments count, tracked as done |
| `do_action_ref_array($hook, $args)`                          | Same, args passed as an array                     |
| `has_action($hook, $cb = false, $priority = false)`          | Alias of `has_filter`                             |
| `remove_action($hook, $cb, $priority = 10)`                  | Removes; tracked as an *action* removal           |
| `remove_all_actions($hook, $priority = false)`               | Clears callbacks + added-records                  |
| `current_action()` / `doing_action($hook = null)`            | Aliases of `current_filter` / `doing_filter`      |
| `did_action($hook)`                                          | Times fired                                       |
| `do_action_deprecated($hook, $args, $version, ...)`          | Tracked + fired even with no callbacks            |

The special `all` hook also runs before the named hook for `apply_filters`, `apply_filters_ref_array`, `do_action`, and `do_action_ref_array`, using WordPress's direct-call and ref-array argument shapes.

> `remove_action` and `remove_filter` track separately: a filter-removal expectation won't be satisfied by `remove_action`, and vice versa.

### Asserting WP functions vs Expecting Brain Monkey methods

| `Filters\`        | WP equivalent     | `Actions\`        | WP equivalent     | Args                             |
|-------------------|-------------------|-------------------|-------------------|----------------------------------|
| `expectAdded()`   | `add_filter()`    | `expectAdded()`   | `add_action()`    | `$hook_name`                     |
| `expectApplied()` | `apply_filters()` | `expectDone()`    | `do_action()`     | `$hook_name`                     |
| `doing()`         | `doing_filter()`  | `doing()`         | `doing_action()`  | `$hook_name`                     |
| `applied()`       | `did_filter()`    | `did()`           | `did_action()`    | `$hook_name`                     |
| `expectRemoved()` | `remove_filter()` | `expectRemoved()` | `remove_action()` | `$hook_name`                     |
| `has()`           | `has_filter()`    | `has()`           | `has_action()`    | `$hook_name`, `$cb`, `$priority` |

## Reference

`BrainMonkey\Doubles` is the facade for the WordPress doubles. `setUp()`'s argument selects which to enable — the extension point for future doubles; `'hooks'` is the only one today. (The `BrainMonkey\Functions` helpers above are independent and need only Brain Monkey.)

| Method                                       | Does                                                 |
|----------------------------------------------|------------------------------------------------------|
| `Doubles::setUp(array $doubles = ['hooks'])` | Enable doubles. Throws on an unknown name            |
| `Doubles::tearDown()`                        | Clear hook state and disable all doubles             |
| `Doubles::reset()`                           | Clear hook state between tests, keep doubles enabled |
| `Doubles::isEnabled(string $double): bool`   | Whether a double is on                               |

> Call `Doubles::setUp()` **before** `Monkey\setUp()` — it throws `RuntimeException` if `add_filter` is already defined.
