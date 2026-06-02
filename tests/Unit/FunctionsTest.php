<?php

declare( strict_types=1 );

use BrainMonkey\Functions;

describe( 'Brain Monkey function doubles', function () {

	/*
	 * 1. stub() exposes the whole when() API.
	 *
	 * Each row pairs a when()-method configuration closure with a call closure.
	 * The call closure returns whatever should be asserted: the function's return
	 * value for justReturn/returnArg/alias, or the captured echoed output for the
	 * justEcho/echoArg rows (those redefine the function to echo and return null,
	 * so we buffer the output and assert the printed text instead).
	 */
	it( 'exposes the whole when() API through stub()', function (
		string $name,
		Closure $configure,
		Closure $call,
		$expected
	) {
		$stub = Functions\stub( $name );

		// Sanity: stub() is a pass-through to when(), so it hands back a FunctionStub.
		expect( $stub )->toBeInstanceOf( \Brain\Monkey\Expectation\FunctionStub::class );

		$configure( $stub );

		expect( $call() )->toBe( $expected );
	} )->with( [
		'justReturn returns a fixed value' => [
			'bmd_fn_justreturn',
			fn( $s ) => $s->justReturn( 'fixed-value' ),
			fn() => bmd_fn_justreturn( 'ignored' ),
			'fixed-value',
		],
		'returnArg returns the first argument by default' => [
			'bmd_fn_returnarg',
			fn( $s ) => $s->returnArg(),
			fn() => bmd_fn_returnarg( 'first', 'second' ),
			'first',
		],
		'returnArg returns the nth argument when asked' => [
			'bmd_fn_returnarg_n',
			fn( $s ) => $s->returnArg( 2 ),
			fn() => bmd_fn_returnarg_n( 'first', 'second' ),
			'second',
		],
		'alias runs an arbitrary callback' => [
			'bmd_fn_alias',
			fn( $s ) => $s->alias( fn( string $value ): string => "aliased-{$value}" ),
			fn() => bmd_fn_alias( 'value' ),
			'aliased-value',
		],
		'justEcho prints a fixed value (returns null, echoes text)' => [
			'bmd_fn_justecho',
			fn( $s ) => $s->justEcho( 'echoed-text' ),
			function () {
				ob_start();
				$return = bmd_fn_justecho( 'ignored' );
				$output = ob_get_clean();

				// justEcho echoes and returns null: the echoed text is what matters.
				expect( $return )->toBeNull();

				return $output;
			},
			'echoed-text',
		],
		'echoArg prints the first argument (returns null, echoes text)' => [
			'bmd_fn_echoarg',
			fn( $s ) => $s->echoArg(),
			function () {
				ob_start();
				$return = bmd_fn_echoarg( 'printed-arg' );
				$output = ob_get_clean();

				expect( $return )->toBeNull();

				return $output;
			},
			'printed-arg',
		],
		'echoArg prints the nth argument when asked' => [
			'bmd_fn_echoarg_n',
			fn( $s ) => $s->echoArg( 2 ),
			function () {
				ob_start();
				bmd_fn_echoarg_n( 'first', 'second' );

				return ob_get_clean();
			},
			'second',
		],
	] );

	/*
	 * 1b. when() is an undocumented alias of stub().
	 *
	 * It keeps Brain Monkey's helper name available while preserving this
	 * package's re-callable baseline behavior.
	 */
	it( 'supports when() as an alias of stub()', function (
		string $name,
		array $layers,
		Closure $call,
		$expected
	) {
		foreach ( $layers as $layer ) {
			$layer();
		}

		expect( $call() )->toBe( $expected );
	} )->with( [
		'when() returns a FunctionStub with the when API' => [
			'bmd_when_alias',
			[
				function () {
					$stub = Functions\when( 'bmd_when_alias' );

					expect( $stub )->toBeInstanceOf( \Brain\Monkey\Expectation\FunctionStub::class );

					$stub->justReturn( 'via-when' );
				},
			],
			fn() => bmd_when_alias(),
			'via-when',
		],
		'stub() can override when()' => [
			'bmd_when_then_stub',
			[
				fn() => Functions\when( 'bmd_when_then_stub' )->justReturn( 'when' ),
				fn() => Functions\stub( 'bmd_when_then_stub' )->justReturn( 'stub' ),
			],
			fn() => bmd_when_then_stub(),
			'stub',
		],
		'when() can override stub()' => [
			'bmd_stub_then_when',
			[
				fn() => Functions\stub( 'bmd_stub_then_when' )->justReturn( 'stub' ),
				fn() => Functions\when( 'bmd_stub_then_when' )->justReturn( 'when' ),
			],
			fn() => bmd_stub_then_when(),
			'when',
		],
	] );

	/*
	 * 1c. Brain Monkey's bulk WordPress function helpers are available here too.
	 *
	 * These aliases let test files use BrainMonkey\Functions for both defaults
	 * and expectations, while Brain Monkey still owns the default behavior.
	 */
	it( 'aliases Brain Monkey bulk stub helpers and lets expect() take over later', function (
		Closure $install,
		Closure $baselineCall,
		$baselineExpected,
		string $name,
		Closure $configureExpectation,
		Closure $expectedCall
	) {
		$install();

		expect( $baselineCall() )->toBe( $baselineExpected );

		$configureExpectation( Functions\expect( $name ) );

		$expectedCall();
	} )->with( [
		'escape functions' => [
			fn() => Functions\stubEscapeFunctions(),
			fn() => esc_html( '<b>Hi</b>' ),
			'&lt;b&gt;Hi&lt;/b&gt;',
			'esc_html',
			fn( $e ) => $e->once()->with( '<b>Hi</b>' )->andReturn( 'mocked-escape' ),
			function () {
				expect( esc_html( '<b>Hi</b>' ) )->toBe( 'mocked-escape' );
			},
		],
		'translation functions' => [
			fn() => Functions\stubTranslationFunctions(),
			fn() => __( 'Original', 'domain' ),
			'Original',
			'__',
			fn( $e ) => $e->once()->with( 'Original', 'domain' )->andReturn( 'mocked-translation' ),
			function () {
				expect( __( 'Original', 'domain' ) )->toBe( 'mocked-translation' );
			},
		],
	] );

	/*
	 * 2. stub() is re-callable; the last definition wins.
	 *
	 * Each row carries the (single) function name, an ordered list of layering
	 * closures (each redefines the same name via a fresh stub()), and the value
	 * the function should return once the dust settles.
	 */
	it( 'is re-callable so the last stub layer wins', function (
		string $name,
		array $layers,
		Closure $call,
		$expected
	) {
		foreach ( $layers as $layer ) {
			$layer( Functions\stub( $name ) );
		}

		expect( $call() )->toBe( $expected );
	} )->with( [
		'single justReturn definition' => [
			'bmd_layer_single',
			[
				fn( $s ) => $s->justReturn( 'only' ),
			],
			fn() => bmd_layer_single(),
			'only',
		],
		'justReturn then justReturn keeps the later value' => [
			'bmd_layer_override',
			[
				fn( $s ) => $s->justReturn( 'a' ),
				fn( $s ) => $s->justReturn( 'b' ),
			],
			fn() => bmd_layer_override(),
			'b',
		],
		'three justReturn layers keep the last value' => [
			'bmd_layer_override_twice',
			[
				fn( $s ) => $s->justReturn( 'a' ),
				fn( $s ) => $s->justReturn( 'b' ),
				fn( $s ) => $s->justReturn( 'c' ),
			],
			fn() => bmd_layer_override_twice(),
			'c',
		],
		'alias then justReturn switches to the fixed value' => [
			'bmd_layer_alias_then_return',
			[
				fn( $s ) => $s->alias( fn( string $value ): string => "aliased-{$value}" ),
				fn( $s ) => $s->justReturn( 'final' ),
			],
			fn() => bmd_layer_alias_then_return( 'value' ),
			'final',
		],
		'justReturn then alias switches to the callback' => [
			'bmd_layer_return_then_alias',
			[
				fn( $s ) => $s->justReturn( 'fixed' ),
				fn( $s ) => $s->alias( fn( string $value ): string => "aliased-{$value}" ),
			],
			fn() => bmd_layer_return_then_alias( 'value' ),
			'aliased-value',
		],
	] );

	/*
	 * 3. expect() fires over an existing stub() baseline.
	 *
	 * This is the whole reason Functions\expect() exists: Brain Monkey's own
	 * expect() refuses to re-route a function once a when()/stub baseline is
	 * registered, so the Mockery expectation would never be called and the
	 * assertion would silently fail at teardown. Functions\expect() forces the
	 * Patchwork re-route, so the expectation fires.
	 *
	 * Every row first installs a stub() baseline, then configures an expectation
	 * on the same name, then runs the call closure. The "expectation fired" part
	 * is verified by Brain Monkey at teardown (Monkey\tearDown() in Pest.php);
	 * rows that use andReturn additionally assert the returned value here.
	 */
	it( 'fires expect() over an existing stub() baseline', function (
		string $name,
		Closure $expectation,
		Closure $call
	) {
		Functions\stub( $name )->justReturn( 'baseline' );

		$expectation( Functions\expect( $name ) );

		$call();
	} )->with( [
		'plain once() just asserts the call happened' => [
			'bmd_expect_once',
			fn( $e ) => $e->once(),
			function () {
				// No andReturn: the mock now owns the function and answers null
				// (Mockery's default), and Brain Monkey verifies the single call
				// at teardown. The null assertion also keeps this row non-risky.
				expect( bmd_expect_once() )->toBeNull();
			},
		],
		'with()->andReturn() overrides the baseline return' => [
			'bmd_expect_with_return',
			fn( $e ) => $e->once()->with( 'input' )->andReturn( 'expected' ),
			function () {
				expect( bmd_expect_with_return( 'input' ) )->toBe( 'expected' );
			},
		],
		'andReturn() with no args constraint still wins over the baseline' => [
			'bmd_expect_return_only',
			fn( $e ) => $e->once()->andReturn( 'mocked' ),
			function () {
				expect( bmd_expect_return_only() )->toBe( 'mocked' );
			},
		],
		'times(2) requires exactly two calls' => [
			'bmd_expect_times_two',
			fn( $e ) => $e->times( 2 )->with( 'input' )->andReturn( 'twice' ),
			function () {
				expect( bmd_expect_times_two( 'input' ) )->toBe( 'twice' );
				expect( bmd_expect_times_two( 'input' ) )->toBe( 'twice' );
			},
		],
	] );

	/*
	 * 3b. expect() returns a chainable Expectation.
	 *
	 * Pins the return type and the fluent chain ->once()->with()->andReturn().
	 * Single-row dataset to honor the dataset-only convention.
	 */
	it( 'returns a chainable Expectation from expect()', function (
		string $name,
		Closure $chain,
		Closure $call
	) {
		Functions\stub( $name )->justReturn( 'baseline' );

		$expectation = Functions\expect( $name );

		expect( $expectation )->toBeInstanceOf( \Brain\Monkey\Expectation\Expectation::class );

		// The fluent chain must return the same Expectation at every step.
		expect( $chain( $expectation ) )->toBe( $expectation );

		$call();
	} )->with( [
		'once()->with()->andReturn() chain is fluent' => [
			'bmd_expect_chain',
			fn( $e ) => $e->once()->with( 'input' )->andReturn( 'chained' ),
			function () {
				expect( bmd_expect_chain( 'input' ) )->toBe( 'chained' );
			},
		],
	] );

} );
