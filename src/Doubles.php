<?php

declare(strict_types=1);

namespace BrainMonkey;

use BrainMonkey\Hooks\Module as HooksModule;

/**
 * Brain Monkey Doubles
 *
 * Main entry point for enabling WordPress test doubles
 * that integrate with Brain Monkey tracking.
 *
 * Usage:
 *   use BrainMonkey\Doubles;
 *
 *   Doubles::setUp();       // Before Brain Monkey
 *   Monkey\setUp();
 *
 *   // In teardown:
 *   Monkey\tearDown();
 *   Doubles::tearDown();
 */
final class Doubles
{
    private static array $enabled = [];

    /**
     * Initialize test doubles.
     *
     * @param array $doubles Doubles to enable. Default: ['hooks']
     */
    public static function setUp(array $doubles = ['hooks']): void
    {
        // Validate before mutating: hook doubles define global functions that PHP cannot unload.
        foreach ($doubles as $double) {
            match ($double) {
                'hooks' => null,
                default => throw new \InvalidArgumentException("Unknown double: {$double}"),
            };
        }

        foreach ($doubles as $double) {
            match ($double) {
                'hooks' => HooksModule::setUp(),
            };

            self::$enabled[$double] = true;
        }
    }

    /**
     * Clean up all enabled doubles.
     */
    public static function tearDown(): void
    {
        foreach (array_keys(self::$enabled) as $double) {
            match ($double) {
                'hooks' => HooksModule::tearDown(),
                default => throw new \InvalidArgumentException("Unknown double: {$double}"),
            };
        }

        self::$enabled = [];
    }

    /**
     * Reset all enabled doubles.
     */
    public static function reset(): void
    {
        foreach (array_keys(self::$enabled) as $double) {
            match ($double) {
                'hooks' => HooksModule::reset(),
                default => throw new \InvalidArgumentException("Unknown double: {$double}"),
            };
        }
    }

    /**
     * Check if a double is enabled.
     */
    public static function isEnabled(string $double): bool
    {
        return self::$enabled[$double] ?? false;
    }
}
