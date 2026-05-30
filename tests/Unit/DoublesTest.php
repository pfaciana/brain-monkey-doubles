<?php

declare(strict_types=1);

use BrainMonkey\Doubles;

/*
 * The Unit suite bootstrap (tests/Pest.php) runs Doubles::setUp(['hooks'])
 * and Monkey\setUp() before each test, and tears both down after. So when a
 * test body starts, Doubles is ALREADY enabled with ['hooks'].
 */

describe('BrainMonkey\\Doubles facade', function () {

    it('reports whether a double is enabled', function (string $probe, bool $expected) {
        expect(Doubles::isEnabled($probe))->toBe($expected);
    })->with([
        'enabled hooks double' => ['hooks', true],
        'unknown name'         => ['nope', false],
        'empty name'           => ['', false],
    ]);

    it('rejects unknown double names with the exact message', function (array $request, string $badName) {
        expect(fn () => Doubles::setUp($request))
            ->toThrow(InvalidArgumentException::class, "Unknown double: {$badName}");
    })->with([
        'single bad name'        => [['hook'], 'hook'],
        'another bad name'       => [['nope'], 'nope'],
        'good name then bad one' => [['hooks', 'bogus'], 'bogus'],
    ]);

    it('keeps doubles enabled after reset()', function (string $probe) {
        Doubles::reset();

        expect(Doubles::isEnabled($probe))->toBeTrue();
    })->with([
        'hooks stays enabled' => ['hooks'],
    ]);

    it('disables all doubles on tearDown()', function (string $probe) {
        expect(Doubles::isEnabled($probe))->toBeTrue();

        Doubles::tearDown();

        expect(Doubles::isEnabled($probe))->toBeFalse();

        // The shared afterEach also calls Doubles::tearDown(); a second call
        // must be safe (idempotent) and not throw.
        Doubles::tearDown();

        expect(Doubles::isEnabled($probe))->toBeFalse();
    })->with([
        'hooks gets disabled' => ['hooks'],
    ]);

    it('rejects an unknown enabled double during tearDown/reset', function (string $method) {
        // setUp() never stores an unknown key, so the match() default arms in
        // tearDown()/reset() are only reachable if state is corrupted. Force it.
        $enabled = new ReflectionProperty(Doubles::class, 'enabled');
        $enabled->setValue(null, ['bogus' => true]);

        try {
            expect(fn () => Doubles::{$method}())
                ->toThrow(InvalidArgumentException::class, 'Unknown double: bogus');
        } finally {
            $enabled->setValue(null, []);
        }
    })->with([
        'tearDown' => ['tearDown'],
        'reset'    => ['reset'],
    ]);
});
