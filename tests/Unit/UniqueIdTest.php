<?php

declare(strict_types=1);

use BrainMonkey\Hooks\WP_Hook;

describe('_wp_filter_build_unique_id', function () {

    it('builds a unique id for every callback shape', function (mixed $callback, ?string $expected) {
        expect(_wp_filter_build_unique_id('greeting', $callback, 10))->toBe($expected);
    })->with('callback-shapes');

    it('ignores the priority argument when building the id', function (int $priority) {
        $callback = fn ($v) => $v;

        expect(_wp_filter_build_unique_id('greeting', $callback, $priority))
            ->toBe(_wp_filter_build_unique_id('greeting', $callback, 0));
    })->with([
        'default'  => [10],
        'zero'     => [0],
        'negative' => [-5],
        'high'     => [999],
    ]);

    it('delegates to the self-contained WP_Hook builder', function (mixed $callback) {
        expect(_wp_filter_build_unique_id('greeting', $callback, 10))
            ->toBe(WP_Hook::build_unique_id('greeting', $callback, 10));
    })->with('callback-shapes');

});
