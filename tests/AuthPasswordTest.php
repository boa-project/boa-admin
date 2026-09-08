<?php

declare(strict_types=1);

namespace BoA\Tests;

use BoA\Core\Services\AuthService;
use PHPUnit\Framework\TestCase;

/**
 * Login password storage: password_hash / password_verify via AuthService.
 *
 * Legacy bare MD5 is accepted only to allow one-time upgrade to password_hash.
 * Reset-token flow is covered by PasswordResetTest.
 */
final class AuthPasswordTest extends TestCase
{
    public function testEncodePasswordUsesPasswordHash(): void
    {
        $encoded = AuthService::encodePassword('unit-test-pass');
        $this->assertIsString($encoded);
        $this->assertDoesNotMatchRegularExpression('/^[a-f0-9]{32}$/i', $encoded);
        $this->assertTrue(AuthService::verifyPassword('unit-test-pass', $encoded));
        $this->assertFalse(AuthService::verifyPassword('wrong', $encoded));
    }

    public function testVerifyPasswordAcceptsLegacyMd5ForMigration(): void
    {
        $plain = 'legacy-pass';
        $md5 = md5($plain);
        $this->assertTrue(AuthService::isLegacyMd5Hash($md5));
        $this->assertTrue(
            AuthService::verifyPassword($plain, $md5),
            'Correct clear password must match legacy MD5 for migration login'
        );
        $this->assertFalse(AuthService::verifyPassword('wrong', $md5));
    }

    public function testPasswordHashAndVerifyContract(): void
    {
        $plain = 'migrated-secret-pass';
        $hash = password_hash($plain, PASSWORD_DEFAULT);
        $this->assertTrue(password_verify($plain, $hash));
        $this->assertTrue(AuthService::verifyPassword($plain, $hash));
    }
}
