<?php

declare(strict_types=1);

use Brain\Monkey;
use BrainMonkey\Doubles;

require_once __DIR__ . '/Datasets.php';

pest()->beforeEach(function () {
    Doubles::setUp();
    Monkey\setUp();
})->afterEach(function () {
    Monkey\tearDown();
    Doubles::tearDown();
})->in('Unit');

