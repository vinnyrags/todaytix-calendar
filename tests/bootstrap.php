<?php

declare(strict_types=1);

/**
 * Test bootstrap.
 *
 * The engine is pure PHP with zero WordPress coupling, so the Composer autoloader
 * — which maps TodayTixCalendar\ -> src/ and TodayTixCalendar\Tests\ -> tests/ — is
 * all the unit suite needs. No WordPress is booted.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
