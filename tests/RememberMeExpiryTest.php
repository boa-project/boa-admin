<?php

declare(strict_types=1);

namespace BoA\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Remember-me expiry: renew window vs absolute max (mirrors AuthService formula).
 */
final class RememberMeExpiryTest extends TestCase
{
    private static function expiresAt(int $issuedAt, int $renewedAt, int $renewDays, int $maxDays): int
    {
        $renewUntil = $renewedAt + $renewDays * 86400;
        $maxUntil = $issuedAt + $maxDays * 86400;
        return min($renewUntil, $maxUntil);
    }

    public function testRenewWindowAppliesWhenUnderMax(): void
    {
        $issued = 1_000_000;
        $renewed = 1_000_000;
        $exp = self::expiresAt($issued, $renewed, 5, 60);
        $this->assertSame($renewed + 5 * 86400, $exp);
    }

    public function testMaxCapsRenewWhenRenewExceedsMax(): void
    {
        $issued = 1_000_000;
        // Last renew near the end of the absolute window
        $renewed = $issued + 58 * 86400;
        $exp = self::expiresAt($issued, $renewed, 5, 60);
        $this->assertSame($issued + 60 * 86400, $exp);
    }

    public function testCookieExpiredAfterRenewDaysWithoutVisit(): void
    {
        $issued = 1_000_000;
        $renewed = 1_000_000;
        $exp = self::expiresAt($issued, $renewed, 5, 60);
        $this->assertTrue(1_000_000 + 5 * 86400 + 1 > $exp);
        $this->assertFalse(1_000_000 + 4 * 86400 > $exp);
    }

    public function testCookieExpiredAtMaxEvenWithRecentRenew(): void
    {
        $issued = 1_000_000;
        $renewed = $issued + 59 * 86400;
        $exp = self::expiresAt($issued, $renewed, 5, 60);
        $now = $issued + 60 * 86400 + 1;
        $this->assertTrue($now > $exp);
    }
}
