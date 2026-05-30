<?php

declare(strict_types=1);

namespace BrainMonkey\Hooks;

use RuntimeException;

/**
 * Brain Monkey Hooks Module
 *
 * Provides real WordPress hook execution with Brain Monkey tracking integration.
 * Callbacks actually fire AND Brain Monkey expectations work.
 */
final class Module
{
    private static bool $functionsLoaded = false;

    /**
     * Initialize the hooks module.
     *
     * Registers global hook functions and initializes state.
     * Call BEFORE Brain\Monkey\setUp() to prevent Brain Monkey
     * from defining its own hook functions.
     */
    public static function setUp(): void
    {
        if (self::$functionsLoaded) {
            self::reset();
            return;
        }

        if (function_exists('add_filter')) {
            throw new RuntimeException(
                'BrainMonkey\\Doubles must be set up before Brain\\Monkey\\setUp().'
            );
        }

        require_once __DIR__ . '/functions.php';

        self::$functionsLoaded = true;
        self::reset();
    }

    /**
     * Clean up after tests.
     */
    public static function tearDown(): void
    {
        self::reset();
    }

    /**
     * Reset all hook state.
     *
     * Clears all registered hooks, action counts, and filter counts.
     * Call between tests to ensure clean state.
     */
    public static function reset(): void
    {
        $GLOBALS['wp_filter']         = [];
        $GLOBALS['wp_actions']        = [];
        $GLOBALS['wp_filters']        = [];
        $GLOBALS['wp_current_filter'] = [];
    }

    /**
     * Check if the module's global functions have been loaded.
     *
     * PHP cannot unload global functions, so this remains true after
     * tearDown(). The method name is kept for compatibility.
     */
    public static function isInitialized(): bool
    {
        return self::$functionsLoaded;
    }
}
