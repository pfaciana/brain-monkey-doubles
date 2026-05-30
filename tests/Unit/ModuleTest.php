<?php

declare(strict_types=1);

use BrainMonkey\Hooks\Module;

/*
 * The Unit suite bootstrap (tests/Pest.php) runs Doubles::setUp(['hooks']) ->
 * Module::setUp() and Monkey\setUp() before each test. So when a test body
 * starts, Module has ALREADY loaded the global hook functions
 * (add_filter, etc.) are defined.
 */

describe('BrainMonkey\\Hooks\\Module lifecycle', function () {

    it('clears each hook-state global on reset()', function (string $global) {
        $GLOBALS[$global] = ['seeded' => 1];

        Module::reset();

        expect($GLOBALS[$global])->toBe([]);
    })->with('hook-globals');

    it('reports initialization state', function (Closure $scenario, bool $expected) {
        expect($scenario())->toBe($expected);
    })->with([
        'functions loaded in warm process' => [fn (): bool => Module::isInitialized(), true],
    ]);

    it('is idempotent when setUp() is called again', function (Closure $scenario) {
        // Functions are already loaded: calling setUp() again must not throw
        // and must leave the module loaded (it just resets state).
        $scenario();

        expect(Module::isInitialized())->toBeTrue();
    })->with([
        'second setUp resets, no re-require' => [fn () => Module::setUp()],
        'third setUp still fine'             => [function () {
            Module::setUp();
            Module::setUp();
        }],
    ]);

    it('throws when add_filter is already defined but the module functions are not marked loaded', function (Closure $scenario) {
        $functionsLoaded = new ReflectionProperty(Module::class, 'functionsLoaded');

        // Force the "functions not marked loaded but add_filter exists" state.
        // In the warm process add_filter IS defined and $functionsLoaded IS
        // true, so this is the only way to exercise the RuntimeException guard.
        $functionsLoaded->setValue(null, false);

        try {
            $scenario();
        } finally {
            // CRITICAL: restore the static so every later test still works.
            $functionsLoaded->setValue(null, true);
        }
    })->with([
        'guard fires when warm-process hooks pre-exist' => [function () {
            expect(function_exists('add_filter'))->toBeTrue();
            expect(Module::isInitialized())->toBeFalse();

            expect(fn () => Module::setUp())->toThrow(RuntimeException::class);
        }],
    ]);
});
