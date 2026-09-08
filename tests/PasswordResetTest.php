<?php
namespace BoA\Tests;

use BoA\Core\Security\PasswordReset;
use PHPUnit\Framework\TestCase;

class PasswordResetTest extends TestCase
{
    public function testCreateConsumeHappyPath(): void
    {
        $token = PasswordReset::generateToken();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $stored = PasswordReset::createStoredPayload($token, 1_000_000, 3600);
        $this->assertTrue(PasswordReset::validateToken($token, $stored, 1_000_100));
        $this->assertFalse(PasswordReset::isExpired($stored, 1_000_100));
    }

    public function testWrongTokenRejected(): void
    {
        $token = PasswordReset::generateToken();
        $stored = PasswordReset::createStoredPayload($token, 1_000_000, 3600);
        $other = PasswordReset::generateToken();
        $this->assertFalse(PasswordReset::validateToken($other, $stored, 1_000_100));
    }

    public function testExpiredTokenRejected(): void
    {
        $token = PasswordReset::generateToken();
        $stored = PasswordReset::createStoredPayload($token, 1_000_000, 60);
        $this->assertTrue(PasswordReset::isExpired($stored, 1_000_061));
        $this->assertFalse(PasswordReset::validateToken($token, $stored, 1_000_061));
    }

    public function testEmptyStoredRejected(): void
    {
        $this->assertFalse(PasswordReset::validateToken('abc', null));
        $this->assertFalse(PasswordReset::validateToken('abc', array()));
        $this->assertTrue(PasswordReset::isExpired(null));
    }
}
