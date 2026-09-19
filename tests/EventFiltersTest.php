<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\EventFilters;

final class EventFiltersTest extends TestCase
{
    public function testIgnoreErrorsSubstring(): void
    {
        self::assertTrue(EventFilters::shouldDrop(
            "Can't find variable: _AutofillCallbackHandler",
            null,
            ['_AutofillCallbackHandler'],
            [],
        ));
        self::assertFalse(EventFilters::shouldDrop(
            'Checkout failed',
            null,
            ['_AutofillCallbackHandler'],
            [],
        ));
    }

    public function testIgnoreUrlsStackMatch(): void
    {
        self::assertTrue(EventFilters::shouldDrop(
            'boom',
            'at x (https://connect.facebook.net/sdk.js:1:1)',
            [],
            ['facebook.net'],
        ));
    }
}
