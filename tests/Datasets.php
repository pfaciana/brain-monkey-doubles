<?php

declare(strict_types=1);

/**
 * Shared datasets for the test suite.
 *
 * Every test in this project is data-driven (`->with(...)`). The reusable
 * datasets live here; module-specific ones stay next to their tests.
 */

// Callback shapes for _wp_filter_build_unique_id() and has_filter().
// Identity-based expectations are computed against the same instance the row carries.
$bmd_obj       = new class { public function m() {} };
$bmd_closure   = function () {};
$bmd_invokable = new class { public function __invoke() {} };

dataset('callback-shapes', [
    'string function'      => ['strtolower', 'strtolower'],
    'static method array'  => [['Acme\\Plugin', 'boot'], 'Acme\\Plugin::boot'],
    'object method array'  => [[$bmd_obj, 'm'], spl_object_hash($bmd_obj) . 'm'],
    'closure'              => [$bmd_closure, spl_object_hash($bmd_closure) . ''],
    'invokable object'     => [$bmd_invokable, spl_object_hash($bmd_invokable) . ''],
    'invalid first member' => [[123, 'x'], null],
]);

// Priority orderings: input priorities -> expected execution order.
dataset('priority-sets', [
    'single default'          => [[10], [10]],
    'already ascending'       => [[5, 10, 20], [5, 10, 20]],
    'added descending'        => [[20, 10, 5], [5, 10, 20]],
    'duplicate priority FIFO' => [[10, 10], [10, 10]],
    'negative and zero'       => [[-5, 0, 10], [-5, 0, 10]],
    'int extremes'            => [[PHP_INT_MAX, 0, PHP_INT_MIN], [PHP_INT_MIN, 0, PHP_INT_MAX]],
]);

// accepted_args slicing: (accepted_args, provided args, args the callback should receive).
dataset('accepted-args', [
    'zero args'   => [0, ['a', 'b', 'c'], []],
    'fewer'       => [2, ['a', 'b', 'c', 'd'], ['a', 'b']],
    'equal'       => [3, ['a', 'b', 'c'], ['a', 'b', 'c']],
    'more wanted' => [5, ['a', 'b'], ['a', 'b']],
]);

// Value types a filter must pass through unchanged (assert with toBe).
dataset('return-types', [
    'null'         => [null],
    'empty string' => [''],
    'zero'         => [0],
    'false'        => [false],
    'array'        => [['x' => 1]],
    'object'       => [(object) ['a' => 1]],
]);

// The four hook-state globals Hooks\Module::reset() must clear.
dataset('hook-globals', [
    'wp_filter'         => ['wp_filter'],
    'wp_actions'        => ['wp_actions'],
    'wp_filters'        => ['wp_filters'],
    'wp_current_filter' => ['wp_current_filter'],
]);
