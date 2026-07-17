<?php

declare(strict_types=1);

namespace BoA\Tests;

use BoA\Core\Security\Crypto;
use PHPUnit\Framework\TestCase;

/**
 * AES-256-GCM Crypto helper (OpenSSL, `v1:` ciphertext prefix).
 *
 * Implementation: `src/boa/src/Core/Security/Crypto.class.php`
 * decrypt() returns false on tamper / wrong key / legacy (no dual-read).
 */
final class CryptoTest extends TestCase
{
    private string $secret = 'phpunit-admin-test-secret-key-32b!';

    protected function setUp(): void
    {
        if (!class_exists(Crypto::class)) {
            $this->markTestSkipped(
                'Waiting for BoA\\Core\\Security\\Crypto at '
                . 'src/boa/src/Core/Security/Crypto.class.php'
            );
        }
        $this->applySecret($this->secret);
    }

    protected function tearDown(): void
    {
        $this->applySecret($this->secret);
    }

    private function applySecret(string $secret): void
    {
        putenv('APP_SECRET_KEY=' . $secret);
        $_ENV['APP_SECRET_KEY'] = $secret;
    }

    public function testEncryptProducesV1PrefixedCiphertext(): void
    {
        $cipher = Crypto::encrypt('hello-boa');
        $this->assertIsString($cipher);
        $this->assertStringStartsWith('v1:', $cipher);
        $this->assertTrue(Crypto::isV1Payload($cipher));
        $this->assertNotSame('hello-boa', $cipher);
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $plain = "secrets\0with\nbinary";
        $this->assertSame($plain, Crypto::decrypt(Crypto::encrypt($plain)));
    }

    public function testEncryptDecryptRoundTripWithContext(): void
    {
        $plain = 'bound-secret';
        $ctx = 'user:admin';
        $cipher = Crypto::encrypt($plain, $ctx);
        $this->assertSame($plain, Crypto::decrypt($cipher, $ctx));
        $this->assertFalse(Crypto::decrypt($cipher, 'user:other'));
    }

    public function testDecryptRejectsTamperedCiphertext(): void
    {
        $cipher = Crypto::encrypt('payload');
        $tampered = substr($cipher, 0, -1) . (substr($cipher, -1) === 'A' ? 'B' : 'A');
        $this->assertFalse(Crypto::decrypt($tampered));
    }

    public function testDecryptFailsWithWrongKey(): void
    {
        $cipher = Crypto::encrypt('payload');
        $this->applySecret('totally-different-secret-key-32b!!');
        $this->assertFalse(Crypto::decrypt($cipher));
    }

    public function testDecryptRejectsLegacyUnprefixedPayload(): void
    {
        $this->assertFalse(Crypto::decrypt('not-a-v1-payload'));
    }
}
