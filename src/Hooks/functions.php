<?php

/**
 * WordPress Hook Functions — Hybrid Implementation
 *
 * Real hook execution + Brain Monkey tracking.
 * These functions are in the global namespace to match WordPress.
 */

use Brain\Monkey;
use Brain\Monkey\Expectation\ExpectationTarget;
use BrainMonkey\Hooks\WP_Hook;

// ============================================================================
// Brain Monkey Integration
// ============================================================================

/**
 * Track hook activity with Brain Monkey.
 *
 * Applied filters use _bmd_apply_filter_expectation() because filter
 * expectations can return values.
 *
 * @internal This is implementation glue for the hook doubles and is not part
 *           of the public API.
 *
 * @param string $type   HookStorage::ACTIONS or HookStorage::FILTERS
 * @param string $method 'add', 'do', or 'remove'
 */
function _bmd_track( string $type, string $method, string $hook_name, array $args ): void
{
	$container = Monkey\Container::instance();
	$storage  = $container->hookStorage();
	$executor = $container->hookExpectationExecutor();

	match ( $method ) {
		'add' => ( function () use ( $storage, $executor, $type, $hook_name, $args ): void {
			$storage->pushToAdded( $type, $hook_name, $args );
			if ( $type === Monkey\Hook\HookStorage::ACTIONS ) {
				$executor->executeAddAction( $hook_name, $args );
			} else {
				$executor->executeAddFilter( $hook_name, $args );
			}
		} )(),

		'do' => ( function () use ( $storage, $executor, $type, $hook_name, $args ): void {
			$storage->pushToDone( $type, $hook_name, $args );
			$executor->executeDoAction( $hook_name, $args );
		} )(),

		'remove' => ( function () use ( $storage, $executor, $type, $hook_name, $args ): void {
			$storage->removeFromAdded( $type, $hook_name, $args );
			if ( $type === Monkey\Hook\HookStorage::ACTIONS ) {
				$executor->executeRemoveAction( $hook_name, $args );
			} else {
				$executor->executeRemoveFilter( $hook_name, $args );
			}
		} )(),
	};
}

// ============================================================================
// Internal Helpers
// ============================================================================

/**
 * Track and execute Brain Monkey expectations for an applied filter.
 *
 * The return flag lets apply_filters() preserve normal WP_Hook execution unless
 * a test explicitly configured a Brain Monkey filter return expectation.
 *
 * @internal This is implementation glue for the hook doubles and is not part
 *           of the public API.
 */
function _bmd_apply_filter_expectation( string $hook_name, array $args, bool &$has_return_expectation = false ): mixed
{
	$has_return_expectation = false;
	$container               = Monkey\Container::instance();

	$container->hookStorage()->pushToDone( Monkey\Hook\HookStorage::FILTERS, $hook_name, $args );

	$target                 = new ExpectationTarget( ExpectationTarget::TYPE_FILTER_APPLIED, $hook_name );
	$has_return_expectation = $container->expectationFactory()->hasReturnExpectationFor( $target );

	return $container->hookExpectationExecutor()->executeApplyFilters( $hook_name, $args );
}

/**
 * Remove a hook callback without Brain Monkey tracking.
 *
 * Used internally by remove_filter() and remove_action() to avoid double-tracking
 * when remove_action() would otherwise delegate to remove_filter().
 *
 * @internal This is implementation glue for the hook doubles and is not part
 *           of the public API.
 */
function _bmd_remove_hook( $hook_name, $callback, $priority = 10 ) {
	global $wp_filter;

	$r = false;

	if ( isset( $wp_filter[ $hook_name ] ) ) {
		$r = $wp_filter[ $hook_name ]->remove_filter( $hook_name, $callback, $priority );

		if ( ! $wp_filter[ $hook_name ]->callbacks ) {
			unset( $wp_filter[ $hook_name ] );
		}
	}

	return $r;
}

/**
 * Forget Brain Monkey's added-hook records for callbacks removed by WordPress.
 *
 * @internal This is implementation glue for the hook doubles and is not part
 *           of the public API.
 */
function _bmd_forget_added_hooks( string $hook_name, $priority = false ): void
{
	global $wp_filter;

	$storage = Monkey\Container::instance()->hookStorage();

	if ( false === $priority ) {
		$storage->removeFromAdded( Monkey\Hook\HookStorage::ACTIONS, $hook_name, [] );
		$storage->removeFromAdded( Monkey\Hook\HookStorage::FILTERS, $hook_name, [] );
		return;
	}

	if ( ! isset( $wp_filter[ $hook_name ]->callbacks[$priority] ) ) {
		return;
	}

	foreach ( $wp_filter[ $hook_name ]->callbacks[$priority] as $callback ) {
		$args = [ $callback['function'], $priority ];
		$storage->removeFromAdded( Monkey\Hook\HookStorage::ACTIONS, $hook_name, $args );
		$storage->removeFromAdded( Monkey\Hook\HookStorage::FILTERS, $hook_name, $args );
	}
}

/**
 * Remove all callbacks from a hook without Brain Monkey tracking.
 *
 * @internal This is implementation glue for the hook doubles and is not part
 *           of the public API.
 */
function _bmd_remove_all_hooks( $hook_name, $priority = false ): void
{
	global $wp_filter;

	if ( isset( $wp_filter[ $hook_name ] ) ) {
		$wp_filter[ $hook_name ]->remove_all_filters( $priority );

		if ( ! $wp_filter[ $hook_name ]->has_filters() ) {
			unset( $wp_filter[ $hook_name ] );
		}
	}
}

if ( ! function_exists( '_wp_call_all_hook' ) ) {
	function _wp_call_all_hook( $args ) {
		global $wp_filter;

		$wp_filter['all']->do_all_hook( $args );
	}
}

// ============================================================================
// Filter Functions
// ============================================================================

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		global $wp_filter;

		_bmd_track(
			Monkey\Hook\HookStorage::FILTERS,
			'add',
			$hook_name,
			[ $callback, $priority, $accepted_args ]
		);

		if ( ! isset( $wp_filter[ $hook_name ] ) ) {
			$wp_filter[ $hook_name ] = new WP_Hook();
		}

		$wp_filter[ $hook_name ]->add_filter( $hook_name, $callback, $priority, $accepted_args );

		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook_name, $value, ...$args ) {
		global $wp_filter, $wp_filters, $wp_current_filter;

		$wp_filters[ $hook_name ] = ( $wp_filters[ $hook_name ] ?? 0 ) + 1;

		$wp_current_filter[] = $hook_name;

		try {
			if ( isset( $wp_filter['all'] ) ) {
				$all_args = func_get_args();
				_wp_call_all_hook( $all_args );
			}

			$has_return_expectation = false;
			$expected = _bmd_apply_filter_expectation( $hook_name, [ $value, ...$args ], $has_return_expectation );

			if ( $has_return_expectation ) {
				return $expected;
			}

			if ( ! isset( $wp_filter[ $hook_name ] ) ) {
				return $value;
			}

			array_unshift( $args, $value );

			return $wp_filter[ $hook_name ]->apply_filters( $value, $args );
		}
		finally {
			array_pop( $wp_current_filter );
		}
	}
}

if ( ! function_exists( 'apply_filters_ref_array' ) ) {
	function apply_filters_ref_array( $hook_name, $args ) {
		global $wp_filter, $wp_filters, $wp_current_filter;

		$wp_filters[ $hook_name ] = ( $wp_filters[ $hook_name ] ?? 0 ) + 1;

		$wp_current_filter[] = $hook_name;

		try {
			if ( isset( $wp_filter['all'] ) ) {
				$all_args = func_get_args();
				_wp_call_all_hook( $all_args );
			}

			$has_return_expectation = false;
			$expected = _bmd_apply_filter_expectation( $hook_name, $args, $has_return_expectation );

			if ( $has_return_expectation ) {
				return $expected;
			}

			if ( ! isset( $wp_filter[ $hook_name ] ) ) {
				return $args[0];
			}

			return $wp_filter[ $hook_name ]->apply_filters( $args[0], $args );
		}
		finally {
			array_pop( $wp_current_filter );
		}
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $hook_name, $callback = false, $priority = false ) {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook_name ] ) ) {
			return false;
		}

		return $wp_filter[ $hook_name ]->has_filter( $hook_name, $callback, $priority );
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( $hook_name, $callback, $priority = 10 ) {
		_bmd_track(
			Monkey\Hook\HookStorage::FILTERS,
			'remove',
			$hook_name,
			[ $callback, $priority ]
		);

		return _bmd_remove_hook( $hook_name, $callback, $priority );
	}
}

if ( ! function_exists( 'remove_all_filters' ) ) {
	function remove_all_filters( $hook_name, $priority = false ) {
		_bmd_forget_added_hooks( $hook_name, $priority );
		_bmd_remove_all_hooks( $hook_name, $priority );

		return true;
	}
}

if ( ! function_exists( 'current_filter' ) ) {
	function current_filter() {
		global $wp_current_filter;
		return end( $wp_current_filter );
	}
}

if ( ! function_exists( 'doing_filter' ) ) {
	function doing_filter( $hook_name = null ) {
		global $wp_current_filter;

		if ( null === $hook_name ) {
			return ! empty( $wp_current_filter );
		}

		return in_array( $hook_name, $wp_current_filter, true );
	}
}

if ( ! function_exists( 'did_filter' ) ) {
	function did_filter( $hook_name ) {
		global $wp_filters;
		return $wp_filters[ $hook_name ] ?? 0;
	}
}

// ============================================================================
// Action Functions
// ============================================================================

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		global $wp_filter;

		_bmd_track(
			Monkey\Hook\HookStorage::ACTIONS,
			'add',
			$hook_name,
			[ $callback, $priority, $accepted_args ]
		);

		if ( ! isset( $wp_filter[ $hook_name ] ) ) {
			$wp_filter[ $hook_name ] = new WP_Hook();
		}

		$wp_filter[ $hook_name ]->add_filter( $hook_name, $callback, $priority, $accepted_args );

		return true;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook_name, ...$arg ) {
		global $wp_filter, $wp_actions, $wp_current_filter;

		$wp_actions[ $hook_name ] = ( $wp_actions[ $hook_name ] ?? 0 ) + 1;

		$wp_current_filter[] = $hook_name;

		try {
			if ( isset( $wp_filter['all'] ) ) {
				$all_args = func_get_args();
				_wp_call_all_hook( $all_args );
			}

			_bmd_track( Monkey\Hook\HookStorage::ACTIONS, 'do', $hook_name, $arg );

			if ( ! isset( $wp_filter[ $hook_name ] ) ) {
				return;
			}

			if ( empty( $arg ) ) {
				$arg[] = '';
			}

			$wp_filter[ $hook_name ]->do_action( $arg );
		}
		finally {
			array_pop( $wp_current_filter );
		}
	}
}

if ( ! function_exists( 'do_action_ref_array' ) ) {
	function do_action_ref_array( $hook_name, $args ) {
		global $wp_filter, $wp_actions, $wp_current_filter;

		$wp_actions[ $hook_name ] = ( $wp_actions[ $hook_name ] ?? 0 ) + 1;

		$wp_current_filter[] = $hook_name;

		try {
			if ( isset( $wp_filter['all'] ) ) {
				$all_args = func_get_args();
				_wp_call_all_hook( $all_args );
			}

			_bmd_track( Monkey\Hook\HookStorage::ACTIONS, 'do', $hook_name, $args );

			if ( ! isset( $wp_filter[ $hook_name ] ) ) {
				return;
			}

			$wp_filter[ $hook_name ]->do_action( $args );
		}
		finally {
			array_pop( $wp_current_filter );
		}
	}
}

if ( ! function_exists( 'has_action' ) ) {
	function has_action( $hook_name, $callback = false, $priority = false ) {
		return has_filter( $hook_name, $callback, $priority );
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( $hook_name, $callback, $priority = 10 ) {
		_bmd_track(
			Monkey\Hook\HookStorage::ACTIONS,
			'remove',
			$hook_name,
			[ $callback, $priority ]
		);

		return _bmd_remove_hook( $hook_name, $callback, $priority );
	}
}

if ( ! function_exists( 'remove_all_actions' ) ) {
	function remove_all_actions( $hook_name, $priority = false ) {
		_bmd_forget_added_hooks( $hook_name, $priority );
		_bmd_remove_all_hooks( $hook_name, $priority );

		return true;
	}
}

if ( ! function_exists( 'current_action' ) ) {
	function current_action() {
		return current_filter();
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( $hook_name = null ) {
		return doing_filter( $hook_name );
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook_name ) {
		global $wp_actions;
		return $wp_actions[ $hook_name ] ?? 0;
	}
}

// ============================================================================
// Deprecated Functions
// ============================================================================

if ( ! function_exists( 'apply_filters_deprecated' ) ) {
	function apply_filters_deprecated( $hook_name, $args, $version, $replacement = '', $message = '' ) {
		return apply_filters_ref_array( $hook_name, $args );
	}
}

if ( ! function_exists( 'do_action_deprecated' ) ) {
	function do_action_deprecated( $hook_name, $args, $version, $replacement = '', $message = '' ) {
		do_action_ref_array( $hook_name, $args );
	}
}

// ============================================================================
// Utility
// ============================================================================

if ( ! function_exists( '_wp_filter_build_unique_id' ) ) {
	function _wp_filter_build_unique_id( $hook_name, $callback, $priority ) {
		return WP_Hook::build_unique_id( $hook_name, $callback, $priority );
	}
}
