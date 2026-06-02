<?php

declare(strict_types=1);

namespace BrainMonkey\Functions;

use Brain\Monkey\Functions;
use Brain\Monkey\Expectation\Exception\MissedPatchworkReplace;
use Brain\Monkey\Expectation\Expectation;
use Brain\Monkey\Expectation\ExpectationTarget;

/**
 * Set (or override) a function's baseline behavior for the current scope.
 *
 * Thin pass-through to Brain Monkey's when(). Re-callable: a later layer
 * (e.g. a file's beforeEach) overrides an earlier one (a global beforeEach),
 * because Patchwork re-redefines the function and the last definition wins.
 *
 * @return \Brain\Monkey\Expectation\FunctionStub
 */
function stub( string $name )
{
	return Functions\when( $name );
}

/**
 * Set (or override) a function's baseline behavior using Brain Monkey's name.
 *
 * Alias of stub(), kept re-callable while matching the native Brain Monkey API.
 *
 * @return \Brain\Monkey\Expectation\FunctionStub
 */
function when( string $name )
{
	return stub( $name );
}

/**
 * Stub WordPress translation functions using Brain Monkey's defaults.
 */
function stubTranslationFunctions(): void
{
	Functions\stubTranslationFunctions();
}

/**
 * Stub WordPress escape functions using Brain Monkey's defaults.
 */
function stubEscapeFunctions(): void
{
	Functions\stubEscapeFunctions();
}

/**
 * expect() that still fires when a when()/stub baseline already exists.
 *
 * Brain Monkey's own expect() refuses to re-route a function once a stub is
 * registered for it, so the Mockery expectation never gets called and the
 * assertion silently fails at teardown. This forces the re-route to the mock
 * (the few lines expect() skips), then returns the Mockery expectation for
 * normal chaining: ->once()->with(...)->andReturn(...).
 *
 * Note: once a function is asserted this way it is fully owned by the mock for
 * the rest of the test — the when() baseline no longer answers other args.
 */
function expect( string $name ): Expectation
{
	$exp    = Functions\expect( $name );
	$mock   = $exp->mockeryExpectation()->getMock();
	$method = ( new ExpectationTarget( ExpectationTarget::TYPE_FUNCTION, $name ) )->mockMethodName();

	$function = ltrim( $name, '\\' );

	\Patchwork\redefine( $function, static function ( ...$args ) use ( $mock, $method ) {
		return $mock->{$method}( ...$args );
	} );

	if ( \Patchwork\hasMissed( $function ) ) {
		throw MissedPatchworkReplace::forFunction( $function );
	}

	return $exp;
}
