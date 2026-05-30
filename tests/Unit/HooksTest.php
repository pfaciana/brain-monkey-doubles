<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;

describe('apply_filters precedence', function () {

    it('resolves a filter by precedence between real callbacks and return expectations', function (Closure $scenario) {
        [$result, $expectedResult, $callbackRan, $expectedRan] = $scenario();

        expect($result)->toBe($expectedResult)
            ->and($callbackRan)->toBe($expectedRan);
    })->with([
        'no return expectation runs the real callback' => [function () {
            $ran = false;
            Filters\expectApplied('bmd_prec_real')->once()->with('in');
            add_filter('bmd_prec_real', function ($v) use (&$ran) {
                $ran = true;

                return "{$v}-real";
            });

            return [apply_filters('bmd_prec_real', 'in'), 'in-real', $ran, true];
        }],
        'andReturn overrides and skips the callback' => [function () {
            $ran = false;
            Filters\expectApplied('bmd_prec_mock')->once()->with('in')->andReturn('mocked');
            add_filter('bmd_prec_mock', function ($v) use (&$ran) {
                $ran = true;

                return "{$v}-real";
            });

            return [apply_filters('bmd_prec_mock', 'in'), 'mocked', $ran, false];
        }],
        'ref-array andReturn overrides' => [function () {
            $ran = false;
            Filters\expectApplied('bmd_prec_ref')->once()->with('in', 'extra')->andReturn('mocked-ref');
            add_filter('bmd_prec_ref', function ($v) use (&$ran) {
                $ran = true;

                return "{$v}-real";
            }, 10, 2);

            return [apply_filters_ref_array('bmd_prec_ref', ['in', 'extra']), 'mocked-ref', $ran, false];
        }],
        'ref-array with no return expectation runs the real callback' => [function () {
            $ran = false;
            Filters\expectApplied('bmd_prec_ref_real')->once()->with('in', 'extra');
            add_filter('bmd_prec_ref_real', function ($v, $x) use (&$ran) {
                $ran = true;

                return "{$v}-{$x}";
            }, 10, 2);

            return [apply_filters_ref_array('bmd_prec_ref_real', ['in', 'extra']), 'in-extra', $ran, true];
        }],
    ]);

    it('returns the value untouched when nothing is registered', function (Closure $apply, $expected) {
        expect($apply())->toBe($expected);
    })->with([
        'apply_filters passthrough'           => [fn () => apply_filters('bmd_none_a', 'val'), 'val'],
        'apply_filters_ref_array passthrough' => [fn () => apply_filters_ref_array('bmd_none_b', ['val', 'x']), 'val'],
    ]);

});

describe('hook counters', function () {

    it('counts how many times a hook fires', function (string $fireFn, string $countFn, int $times, int $expected) {
        for ($i = 0; $i < $times; $i++) {
            $fireFn === 'apply_filters' ? apply_filters('bmd_count', 'x') : do_action('bmd_count');
        }

        expect($countFn('bmd_count'))->toBe($expected);
    })->with([
        'filter never fired' => ['apply_filters', 'did_filter', 0, 0],
        'filter three times' => ['apply_filters', 'did_filter', 3, 3],
        'action twice'       => ['do_action', 'did_action', 2, 2],
    ]);

});

describe('hook context', function () {

    it('exposes the live hook context inside a callback', function (Closure $scenario, array $expected) {
        expect($scenario())->toBe($expected);
    })->with([
        'filter context' => [function () {
            $ctx = [];
            Filters\expectApplied('bmd_ctx_f')->once()->andReturnUsing(function ($v) use (&$ctx) {
                $ctx = [
                    'current'     => current_filter(),
                    'doing'       => doing_filter('bmd_ctx_f'),
                    'doing_other' => doing_filter('nope'),
                ];

                return $v;
            });
            apply_filters('bmd_ctx_f', 'x');

            return $ctx;
        }, ['current' => 'bmd_ctx_f', 'doing' => true, 'doing_other' => false]],
        'action context' => [function () {
            $ctx = [];
            Actions\expectDone('bmd_ctx_a')->once()->whenHappen(function () use (&$ctx) {
                $ctx = [
                    'current'     => current_action(),
                    'doing'       => doing_action('bmd_ctx_a'),
                    'doing_other' => doing_action('nope'),
                ];
            });
            do_action('bmd_ctx_a');

            return $ctx;
        }, ['current' => 'bmd_ctx_a', 'doing' => true, 'doing_other' => false]],
    ]);

    it('reports a clean context outside any hook', function (Closure $probe, $expected) {
        expect($probe())->toBe($expected);
    })->with([
        'current_filter'     => [fn () => current_filter(), false],
        'current_action'     => [fn () => current_action(), false],
        'doing_filter(null)' => [fn () => doing_filter(), false],
        'doing_action(null)' => [fn () => doing_action(), false],
        'doing_filter(name)' => [fn () => doing_filter('whatever'), false],
    ]);

    it('cleans the live hook context after a callback throws', function (Closure $scenario, string $probeFn, string $hookName) {
        $thrown = false;

        try {
            $scenario($hookName);
        } catch (RuntimeException $e) {
            $thrown = true;
            expect($e->getMessage())->toBe('boom');
        }

        expect($thrown)->toBeTrue()
            ->and($probeFn())->toBeFalse()
            ->and(doing_filter($hookName))->toBeFalse()
            ->and(doing_action($hookName))->toBeFalse();
    })->with([
        'apply_filters' => [
            function (string $hookName) {
                add_filter($hookName, fn () => throw new RuntimeException('boom'));
                apply_filters($hookName, 'x');
            },
            'current_filter',
            'bmd_throw_filter',
        ],
        'apply_filters_ref_array' => [
            function (string $hookName) {
                add_filter($hookName, fn () => throw new RuntimeException('boom'));
                apply_filters_ref_array($hookName, ['x']);
            },
            'current_filter',
            'bmd_throw_filter_ref',
        ],
        'do_action' => [
            function (string $hookName) {
                add_action($hookName, fn () => throw new RuntimeException('boom'));
                do_action($hookName);
            },
            'current_action',
            'bmd_throw_action',
        ],
        'do_action_ref_array' => [
            function (string $hookName) {
                add_action($hookName, fn () => throw new RuntimeException('boom'));
                do_action_ref_array($hookName, ['x']);
            },
            'current_action',
            'bmd_throw_action_ref',
        ],
    ]);

});

describe('hook removal', function () {

    it('tracks removal against the right hook family', function (string $addFn, string $removeFn, string $family) {
        $callback = fn ($v) => $v;

        if ($family === 'action') {
            Actions\expectRemoved('bmd_rm')->once();
            Filters\expectRemoved('bmd_rm')->never();
        } else {
            Filters\expectRemoved('bmd_rm')->once();
            Actions\expectRemoved('bmd_rm')->never();
        }

        $addFn('bmd_rm', $callback);

        expect($removeFn('bmd_rm', $callback))->toBeTrue();
    })->with([
        'action' => ['add_action', 'remove_action', 'action'],
        'filter' => ['add_filter', 'remove_filter', 'filter'],
    ]);

    it('clears WordPress and Brain Monkey state on remove_all', function (string $addFn, string $removeAllFn, string $hasFn, Closure $bmHas) {
        $callback = fn ($v) => $v;
        $addFn('bmd_all', $callback);

        expect($hasFn('bmd_all'))->toBeTrue()
            ->and($bmHas('bmd_all'))->toBeTrue();

        expect($removeAllFn('bmd_all'))->toBeTrue();

        expect($hasFn('bmd_all'))->toBeFalse()
            ->and($bmHas('bmd_all'))->toBeFalse();
    })->with([
        'filters' => ['add_filter', 'remove_all_filters', 'has_filter', fn ($h) => Filters\has($h)],
        'actions' => ['add_action', 'remove_all_actions', 'has_action', fn ($h) => Actions\has($h)],
    ]);

    it('remove_all_filters can target a single priority', function ($priority, array $remainingPriorities) {
        add_filter('bmd_prio', fn ($v) => $v, 10);
        add_filter('bmd_prio', fn ($v) => $v, 20);

        remove_all_filters('bmd_prio', $priority);

        $remaining = isset($GLOBALS['wp_filter']['bmd_prio'])
            ? array_keys($GLOBALS['wp_filter']['bmd_prio']->callbacks)
            : [];

        expect($remaining)->toBe($remainingPriorities);
    })->with([
        'all priorities'    => [false, []],
        'only priority 10'  => [10, [20]],
        'missing priority'  => [99, [10, 20]],
    ]);

});

describe('actions', function () {

    it('counts no-callback actions and leaves a clean context', function (int $times, int $expectedCount) {
        for ($i = 0; $i < $times; $i++) {
            do_action('bmd_empty_action');
        }

        expect(did_action('bmd_empty_action'))->toBe($expectedCount)
            ->and(current_action())->toBeFalse()
            ->and(doing_action('bmd_empty_action'))->toBeFalse();
    })->with([
        'once'  => [1, 1],
        'twice' => [2, 2],
    ]);

    it('coerces an empty arg list before dispatch', function (array $args, $expectedFirst) {
        $seen = null;
        add_action('bmd_coerce', function ($a = null) use (&$seen) {
            $seen = $a;
        });

        do_action('bmd_coerce', ...$args);

        expect($seen)->toBe($expectedFirst);
    })->with([
        'no args coerced to empty string' => [[], ''],
        'explicit arg preserved'          => [['real'], 'real'],
    ]);

    it('dispatches and counts do_action_ref_array', function (Closure $scenario, array $expected) {
        expect($scenario())->toBe($expected);
    })->with([
        'with callback' => [function () {
            $seen = null;
            Actions\expectDone('bmd_ref_a')->once()->with(1, 2);
            add_action('bmd_ref_a', function ($a, $b) use (&$seen) {
                $seen = [$a, $b];
            }, 10, 2);
            do_action_ref_array('bmd_ref_a', [1, 2]);

            return ['seen' => $seen, 'count' => did_action('bmd_ref_a')];
        }, ['seen' => [1, 2], 'count' => 1]],
        'without callback still counts' => [function () {
            do_action_ref_array('bmd_ref_none', [1]);

            return ['count' => did_action('bmd_ref_none')];
        }, ['count' => 1]],
    ]);

});

describe('all hook', function () {

    it('runs before named direct hook callbacks', function (Closure $scenario, array $expected) {
        expect($scenario())->toBe($expected);
    })->with([
        'filter' => [function () {
            $events = [];

            add_filter('all', function (...$args) use (&$events) {
                $events[] = ['phase' => 'all', 'current' => current_filter(), 'args' => $args];
            }, 20, 1);

            add_filter('bmd_all_direct_filter', function ($value, $extra) use (&$events) {
                $events[] = ['phase' => 'named', 'current' => current_filter(), 'args' => [$value, $extra]];

                return "{$value}-{$extra}";
            }, 10, 2);

            return [
                'result'    => apply_filters('bmd_all_direct_filter', 'in', 'extra'),
                'events'    => $events,
                'count'     => did_filter('bmd_all_direct_filter'),
                'all_count' => did_filter('all'),
            ];
        }, [
            'result' => 'in-extra',
            'events' => [
                ['phase' => 'all', 'current' => 'bmd_all_direct_filter', 'args' => ['bmd_all_direct_filter', 'in', 'extra']],
                ['phase' => 'named', 'current' => 'bmd_all_direct_filter', 'args' => ['in', 'extra']],
            ],
            'count'     => 1,
            'all_count' => 0,
        ]],
        'action' => [function () {
            $events = [];

            add_action('all', function (...$args) use (&$events) {
                $events[] = ['phase' => 'all', 'current' => current_action(), 'args' => $args];
            }, 20, 1);

            add_action('bmd_all_direct_action', function ($first, $second) use (&$events) {
                $events[] = ['phase' => 'named', 'current' => current_action(), 'args' => [$first, $second]];
            }, 10, 2);

            do_action('bmd_all_direct_action', 'first', 'second');

            return [
                'events'    => $events,
                'count'     => did_action('bmd_all_direct_action'),
                'all_count' => did_action('all'),
            ];
        }, [
            'events' => [
                ['phase' => 'all', 'current' => 'bmd_all_direct_action', 'args' => ['bmd_all_direct_action', 'first', 'second']],
                ['phase' => 'named', 'current' => 'bmd_all_direct_action', 'args' => ['first', 'second']],
            ],
            'count'     => 1,
            'all_count' => 0,
        ]],
    ]);

    it('uses WordPress ref-array argument shape for all callbacks', function (Closure $scenario, array $expected) {
        expect($scenario())->toBe($expected);
    })->with([
        'filter ref array' => [function () {
            $events = [];

            add_filter('all', function (...$args) use (&$events) {
                $events[] = ['current' => current_filter(), 'args' => $args];
            });

            add_filter('bmd_all_ref_filter', fn ($value, $extra) => "{$value}-{$extra}", 10, 2);

            return [
                'result' => apply_filters_ref_array('bmd_all_ref_filter', ['in', 'extra']),
                'events' => $events,
            ];
        }, [
            'result' => 'in-extra',
            'events' => [
                ['current' => 'bmd_all_ref_filter', 'args' => ['bmd_all_ref_filter', ['in', 'extra']]],
            ],
        ]],
        'action ref array' => [function () {
            $events = [];
            $seen   = null;

            add_action('all', function (...$args) use (&$events) {
                $events[] = ['current' => current_action(), 'args' => $args];
            });

            add_action('bmd_all_ref_action', function ($first, $second) use (&$seen) {
                $seen = [$first, $second];
            }, 10, 2);

            do_action_ref_array('bmd_all_ref_action', ['first', 'second']);

            return ['events' => $events, 'seen' => $seen];
        }, [
            'events' => [
                ['current' => 'bmd_all_ref_action', 'args' => ['bmd_all_ref_action', ['first', 'second']]],
            ],
            'seen' => ['first', 'second'],
        ]],
    ]);

    it('runs even when the named hook has no callbacks', function (Closure $scenario, array $expected) {
        expect($scenario())->toBe($expected);
    })->with([
        'filter without named callbacks' => [function () {
            $events = [];

            add_filter('all', function (...$args) use (&$events) {
                $events[] = ['current' => current_filter(), 'args' => $args];
            });

            return [
                'result'  => apply_filters('bmd_all_no_named_filter', 'in'),
                'events'  => $events,
                'current' => current_filter(),
            ];
        }, [
            'result' => 'in',
            'events' => [
                ['current' => 'bmd_all_no_named_filter', 'args' => ['bmd_all_no_named_filter', 'in']],
            ],
            'current' => false,
        ]],
        'action without named callbacks' => [function () {
            $events = [];

            add_action('all', function (...$args) use (&$events) {
                $events[] = ['current' => current_action(), 'args' => $args];
            });

            do_action('bmd_all_no_named_action', 'arg');

            return [
                'events'  => $events,
                'current' => current_action(),
            ];
        }, [
            'events' => [
                ['current' => 'bmd_all_no_named_action', 'args' => ['bmd_all_no_named_action', 'arg']],
            ],
            'current' => false,
        ]],
    ]);

    it('runs before a Brain Monkey return expectation overrides a filter result', function (string $hookName) {
        $events   = [];
        $namedRan = false;

        add_filter('all', function (...$args) use (&$events) {
            $events[] = ['current' => current_filter(), 'args' => $args];
        });

        Filters\expectApplied($hookName)->once()->with('in')->andReturn('mocked');

        add_filter($hookName, function ($value) use (&$namedRan) {
            $namedRan = true;

            return "{$value}-named";
        });

        expect(apply_filters($hookName, 'in'))->toBe('mocked')
            ->and($events)->toBe([
                ['current' => $hookName, 'args' => [$hookName, 'in']],
            ])
            ->and($namedRan)->toBeFalse();
    })->with([
        'filter return override' => ['bmd_all_return_expectation'],
    ]);

    it('runs all callbacks by priority and ignores accepted_args like WordPress', function (string $hookName) {
        $seen = [];

        add_filter('all', function (...$args) use (&$seen) {
            $seen[] = ['priority' => 20, 'args' => $args];
        }, 20, 0);

        add_filter('all', function (...$args) use (&$seen) {
            $seen[] = ['priority' => 5, 'args' => $args];
        }, 5, 1);

        apply_filters($hookName, 'value', 'extra');

        expect($seen)->toBe([
            ['priority' => 5, 'args' => [$hookName, 'value', 'extra']],
            ['priority' => 20, 'args' => [$hookName, 'value', 'extra']],
        ]);
    })->with([
        'accepted args ignored' => ['bmd_all_priority'],
    ]);

    it('cleans the live hook context after an all callback throws', function (Closure $scenario, string $probeFn, string $hookName) {
        $thrown = false;

        try {
            $scenario($hookName);
        } catch (RuntimeException $e) {
            $thrown = true;
            expect($e->getMessage())->toBe('boom');
        }

        expect($thrown)->toBeTrue()
            ->and($probeFn())->toBeFalse()
            ->and(doing_filter($hookName))->toBeFalse()
            ->and(doing_action($hookName))->toBeFalse()
            ->and($GLOBALS['wp_filter']['all']->current_priority())->toBeFalse();
    })->with([
        'apply_filters' => [
            function (string $hookName) {
                add_filter('all', fn () => throw new RuntimeException('boom'));
                apply_filters($hookName, 'x');
            },
            'current_filter',
            'bmd_all_throw_filter',
        ],
        'do_action' => [
            function (string $hookName) {
                add_action('all', fn () => throw new RuntimeException('boom'));
                do_action($hookName, 'x');
            },
            'current_action',
            'bmd_all_throw_action',
        ],
    ]);

});

describe('registration queries and deprecated wrappers', function () {

    it('reports registration for filters and actions', function (string $addFn, string $hasFn, bool $register, bool $expected) {
        $callback = fn ($v) => $v;

        if ($register) {
            $addFn('bmd_has', $callback);
        }

        expect($hasFn('bmd_has'))->toBe($expected);
    })->with([
        'filter registered'     => ['add_filter', 'has_filter', true, true],
        'filter not registered' => ['add_filter', 'has_filter', false, false],
        'action registered'     => ['add_action', 'has_action', true, true],
        'action not registered' => ['add_action', 'has_action', false, false],
    ]);

    it('tracks deprecated wrappers even without callbacks', function (Closure $scenario, $expected) {
        expect($scenario())->toBe($expected);
    })->with([
        'apply_filters_deprecated' => [function () {
            Filters\expectApplied('bmd_dep_f')->once()->with('in')->andReturn('out');

            return apply_filters_deprecated('bmd_dep_f', ['in'], '1.0.0');
        }, 'out'],
        'do_action_deprecated' => [function () {
            Actions\expectDone('bmd_dep_a')->once()->with('in');
            do_action_deprecated('bmd_dep_a', ['in'], '1.0.0');

            return did_action('bmd_dep_a');
        }, 1],
    ]);

});
