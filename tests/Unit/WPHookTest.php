<?php

declare(strict_types=1);

use BrainMonkey\Hooks\WP_Hook;

describe('WP_Hook execution', function () {

    it('executes callbacks in priority order', function (array $priorities, array $expectedOrder) {
        $hook = new WP_Hook();
        $log  = [];

        foreach ($priorities as $priority) {
            $hook->add_filter('greeting', function ($value) use (&$log, $priority) {
                $log[] = $priority;

                return $value;
            }, $priority, 1);
        }

        $hook->apply_filters('seed', ['seed']);

        expect($log)->toBe($expectedOrder);
    })->with('priority-sets');

    it('threads the value through the filter chain', function (array $transforms, $input, $expected) {
        $hook = new WP_Hook();

        foreach ($transforms as $index => $transform) {
            $hook->add_filter('greeting', $transform, 10 + $index, 1);
        }

        expect($hook->apply_filters($input, [$input]))->toBe($expected);
    })->with([
        'no callbacks returns input' => [[], 'untouched', 'untouched'],
        'single callback'            => [[fn ($v) => "{$v}!"], 'hi', 'hi!'],
        'chain of three'             => [[fn ($v) => "{$v}a", fn ($v) => "{$v}b", fn ($v) => "{$v}c"], '', 'abc'],
    ]);

    it('returns the input unchanged when there are no callbacks', function ($value) {
        expect((new WP_Hook())->apply_filters($value, [$value]))->toBe($value);
    })->with('return-types');

    it('passes the configured number of args to a filter callback', function (int $accepted, array $provided, array $received) {
        $hook = new WP_Hook();
        $seen = null;

        $hook->add_filter('greeting', function (...$args) use (&$seen) {
            $seen = $args;

            return $args[0] ?? null;
        }, 10, $accepted);

        $hook->apply_filters($provided[0], $provided);

        expect($seen)->toBe($received);
    })->with('accepted-args');

    it('passes args to action callbacks without threading a return value', function (array $args, array $seen) {
        $hook     = new WP_Hook();
        $captured = [];

        $hook->add_filter('greeting', function (...$received) use (&$captured) {
            $captured = $received;

            return 'IGNORED';
        }, 10, count($args));

        $hook->do_action($args);

        expect($captured)->toBe($seen);
    })->with([
        'single arg'    => [['post-id'], ['post-id']],
        'multiple args' => [[1, 2, 3], [1, 2, 3]],
    ]);

    it('cleans its active iteration state after a filter callback throws', function () {
        $hook   = new WP_Hook();
        $thrown = false;

        $hook->add_filter('greeting', fn () => throw new RuntimeException('boom'), 10, 1);

        try {
            $hook->apply_filters('x', ['x']);
        } catch (RuntimeException $e) {
            $thrown = true;
            expect($e->getMessage())->toBe('boom');
        }

        expect($thrown)->toBeTrue()
            ->and($hook->current_priority())->toBeFalse();
    });

    it('cleans its action state after an action callback throws', function () {
        $hook   = new WP_Hook();
        $thrown = false;

        $hook->add_filter('greeting', fn () => throw new RuntimeException('boom'), 10, 1);

        try {
            $hook->do_action(['x']);
        } catch (RuntimeException $e) {
            $thrown = true;
            expect($e->getMessage())->toBe('boom');
        }

        expect($thrown)->toBeTrue()
            ->and($hook->current_priority())->toBeFalse();
    });

});

describe('WP_Hook registration queries', function () {

    it('answers has_filter for each query shape', function (Closure $query, $expected) {
        $hook     = new WP_Hook();
        $callback = fn ($v) => $v;
        $hook->add_filter('greeting', $callback, 20, 1);

        expect($query($hook, $callback))->toBe($expected);
    })->with([
        'no callback => has any'                  => [fn ($h) => $h->has_filter(), true],
        'callback, no priority => its priority'   => [fn ($h, $cb) => $h->has_filter('greeting', $cb), 20],
        'callback, matching priority => true'     => [fn ($h, $cb) => $h->has_filter('greeting', $cb, 20), true],
        'callback, wrong priority => false'       => [fn ($h, $cb) => $h->has_filter('greeting', $cb, 10), false],
        'unregistered callback => false'          => [fn ($h) => $h->has_filter('greeting', fn ($v) => $v), false],
        'unresolvable callback => false'          => [fn ($h) => $h->has_filter('greeting', [123, 'x']), false],
        'empty hook has no filters'               => [fn () => (new WP_Hook())->has_filters(), false],
    ]);

    it('reports current_priority during and outside a run', function (Closure $scenario, $expected) {
        expect($scenario())->toBe($expected);
    })->with([
        'outside a run is false' => [fn () => (new WP_Hook())->current_priority(), false],
        'inside a callback is the running priority' => [function () {
            $hook = new WP_Hook();
            $seen = null;
            $hook->add_filter('greeting', function ($v) use (&$hook, &$seen) {
                $seen = $hook->current_priority();

                return $v;
            }, 15, 1);
            $hook->apply_filters('x', ['x']);

            return $seen;
        }, 15],
    ]);

});

describe('WP_Hook removal', function () {

    it('reports whether remove_filter removed anything', function (bool $register, bool $expected) {
        $hook     = new WP_Hook();
        $callback = fn ($v) => $v;

        if ($register) {
            $hook->add_filter('greeting', $callback, 10, 1);
        }

        expect($hook->remove_filter('greeting', $callback, 10))->toBe($expected);
    })->with([
        'registered => true'     => [true, true],
        'not registered => false' => [false, false],
    ]);

    it('leaves the right priorities after removal', function (Closure $scenario, array $remaining) {
        $hook = $scenario();

        expect(array_keys($hook->callbacks))->toBe($remaining);
    })->with([
        'remove the only callback empties the bucket' => [function () {
            $hook = new WP_Hook();
            $cb   = fn ($v) => $v;
            $hook->add_filter('greeting', $cb, 10, 1);
            $hook->remove_filter('greeting', $cb, 10);

            return $hook;
        }, []],
        'remove one of two keeps the other priority' => [function () {
            $hook = new WP_Hook();
            $a    = fn ($v) => $v;
            $b    = fn ($v) => $v;
            $hook->add_filter('greeting', $a, 10, 1);
            $hook->add_filter('greeting', $b, 20, 1);
            $hook->remove_filter('greeting', $a, 10);

            return $hook;
        }, [20]],
        'remove_all_filters(false) clears everything' => [function () {
            $hook = new WP_Hook();
            $hook->add_filter('greeting', fn ($v) => $v, 10, 1);
            $hook->add_filter('greeting', fn ($v) => $v, 20, 1);
            $hook->remove_all_filters();

            return $hook;
        }, []],
        'remove_all_filters(priority) clears one bucket' => [function () {
            $hook = new WP_Hook();
            $hook->add_filter('greeting', fn ($v) => $v, 10, 1);
            $hook->add_filter('greeting', fn ($v) => $v, 20, 1);
            $hook->remove_all_filters(10);

            return $hook;
        }, [20]],
        'remove_all_filters on an empty hook is safe' => [function () {
            $hook = new WP_Hook();
            $hook->remove_all_filters();

            return $hook;
        }, []],
    ]);

});

describe('WP_Hook mid-run mutation (resort_active_iterations)', function () {

    it('re-sorts the active run when callbacks change', function (Closure $scenario, array $expectedOrder) {
        expect($scenario())->toBe($expectedOrder);
    })->with([
        'adding a later priority mid-run runs it' => [function () {
            $hook = new WP_Hook();
            $log  = [];
            $hook->add_filter('greeting', function ($v) use (&$hook, &$log) {
                $log[] = 'first@10';
                $hook->add_filter('greeting', function ($v) use (&$log) {
                    $log[] = 'added@20';

                    return $v;
                }, 20, 1);

                return $v;
            }, 10, 1);
            $hook->apply_filters('x', ['x']);

            return $log;
        }, ['first@10', 'added@20']],
        'adding an earlier priority mid-run does not re-run it' => [function () {
            $hook = new WP_Hook();
            $log  = [];
            $hook->add_filter('greeting', function ($v) use (&$hook, &$log) {
                $log[] = 'first@10';
                $hook->add_filter('greeting', function ($v) use (&$log) {
                    $log[] = 'added@5';

                    return $v;
                }, 5, 1);

                return $v;
            }, 10, 1);
            $hook->apply_filters('x', ['x']);

            return $log;
        }, ['first@10']],
        'removing a not-yet-run callback mid-run skips it' => [function () {
            $hook  = new WP_Hook();
            $log   = [];
            $later = function ($v) use (&$log) {
                $log[] = 'later@20';

                return $v;
            };
            $hook->add_filter('greeting', function ($v) use (&$hook, &$log, $later) {
                $log[] = 'first@10';
                $hook->remove_filter('greeting', $later, 20);

                return $v;
            }, 10, 1);
            $hook->add_filter('greeting', $later, 20, 1);
            $hook->apply_filters('x', ['x']);

            return $log;
        }, ['first@10']],
        'removing the current priority mid-run still continues to later priorities' => [function () {
            $hook = new WP_Hook();
            $log  = [];

            $current = function ($v) use (&$hook, &$log, &$current) {
                $log[] = 'first@10';
                $hook->remove_filter('greeting', $current, 10);

                return $v;
            };

            $hook->add_filter('greeting', $current, 10, 1);
            $hook->add_filter('greeting', function ($v) use (&$log) {
                $log[] = 'later@20';

                return $v;
            }, 20, 1);
            $hook->apply_filters('x', ['x']);

            return $log;
        }, ['first@10', 'later@20']],
        'removing every callback mid-run stops the chain' => [function () {
            $hook = new WP_Hook();
            $log  = [];
            $hook->add_filter('greeting', function ($v) use (&$hook, &$log) {
                $log[] = 'first@10';
                $hook->remove_all_filters();

                return $v;
            }, 10, 1);
            $hook->add_filter('greeting', function ($v) use (&$log) {
                $log[] = 'never@20';

                return $v;
            }, 20, 1);
            $hook->apply_filters('x', ['x']);

            return $log;
        }, ['first@10']],
        'adding several priorities mid-run runs the later ones in order' => [function () {
            $hook = new WP_Hook();
            $log  = [];
            $hook->add_filter('greeting', function ($v) use (&$hook, &$log) {
                $log[] = 'first@10';
                foreach ([30, 15, 5] as $priority) {
                    $hook->add_filter('greeting', function ($v) use (&$log, $priority) {
                        $log[] = "added@{$priority}";

                        return $v;
                    }, $priority, 1);
                }

                return $v;
            }, 10, 1);
            $hook->apply_filters('x', ['x']);

            return $log;
        }, ['first@10', 'added@15', 'added@30']],
        'adding at the current priority mid-run does not re-run that bucket' => [function () {
            $hook = new WP_Hook();
            $log  = [];
            $hook->add_filter('greeting', function ($v) use (&$hook, &$log) {
                $log[] = 'first@10';
                $hook->add_filter('greeting', function ($v) use (&$log) {
                    $log[] = 'same@10';

                    return $v;
                }, 10, 1);

                return $v;
            }, 10, 1);
            $hook->apply_filters('x', ['x']);

            return $log;
        }, ['first@10']],
        'nested run re-sorts each active iteration' => [function () {
            $hook  = new WP_Hook();
            $log   = [];
            $depth = 0;
            $hook->add_filter('greeting', function ($v) use (&$hook, &$log, &$depth) {
                $depth++;
                $mine    = $depth;
                $log[]   = "outer@10#{$mine}";
                if ($mine === 1) {
                    $hook->add_filter('greeting', function ($v) use (&$log) {
                        $log[] = 'added@20';

                        return $v;
                    }, 20, 1);
                    $hook->apply_filters('x', ['x']);
                }

                return $v;
            }, 10, 1);
            $hook->apply_filters('x', ['x']);

            return $log;
        }, ['outer@10#1', 'outer@10#2', 'added@20', 'added@20']],
    ]);

});

describe('WP_Hook ArrayAccess and Iterator', function () {

    it('supports ArrayAccess operations', function (Closure $scenario, $expected) {
        $hook = new WP_Hook();
        $hook->add_filter('greeting', fn ($v) => $v, 10, 1);

        expect($scenario($hook))->toBe($expected);
    })->with([
        'offsetExists hit'            => [fn ($h) => isset($h[10]), true],
        'offsetExists miss'          => [fn ($h) => isset($h[99]), false],
        'offsetGet hit is an array'   => [fn ($h) => is_array($h[10]), true],
        'offsetGet miss is null'      => [fn ($h) => $h[99], null],
        'offsetSet by key'            => [fn ($h) => (function ($h) { $h[30] = ['noop']; return isset($h[30]); })($h), true],
        'offsetSet null offset appends' => [fn ($h) => (function ($h) { $before = count($h->callbacks); $h[] = ['noop']; return count($h->callbacks) === $before + 1; })($h), true],
        'offsetUnset removes'         => [fn ($h) => (function ($h) { unset($h[10]); return isset($h[10]); })($h), false],
    ]);

    it('iterates registered priorities in order', function (array $priorities, array $expectedKeys) {
        $hook = new WP_Hook();

        foreach ($priorities as $priority) {
            $hook->add_filter('greeting', fn ($v) => $v, $priority, 1);
        }

        $keys = [];
        foreach ($hook as $key => $value) {
            $keys[] = $key;
        }

        expect($keys)->toBe($expectedKeys);
    })->with([
        'empty'          => [[], []],
        'one'            => [[10], [10]],
        'several sorted' => [[20, 5, 10], [5, 10, 20]],
    ]);

});

describe('WP_Hook::build_preinitialized_hooks', function () {

    it('normalizes specs and passes through existing instances', function (Closure $input, int $bucketCount) {
        $normalized = WP_Hook::build_preinitialized_hooks($input());

        expect($normalized['greeting'])->toBeInstanceOf(WP_Hook::class)
            ->and(count($normalized['greeting']->callbacks))->toBe($bucketCount);
    })->with([
        'array spec is normalized' => [fn () => [
            'greeting' => [10 => [['function' => fn ($v) => $v, 'accepted_args' => 1]]],
        ], 1],
        'existing WP_Hook passes through' => [function () {
            $hook = new WP_Hook();
            $hook->add_filter('greeting', fn ($v) => $v, 10, 1);

            return ['greeting' => $hook];
        }, 1],
    ]);

});
