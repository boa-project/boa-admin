<?php

declare(strict_types=1);

namespace BoA\Tests;

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\ChallengeOptions;
use PHPUnit\Framework\TestCase;

/**
 * ALTCHA create/solve/verify using the vendored library (same as action.altcha).
 */
final class AltchaChallengeTest extends TestCase
{
    private static function autoload(): void
    {
        $path = dirname(__DIR__) . '/src/boa/plugins/action.altcha/thirdparty/altcha/vendor/autoload.php';
        self::assertFileIsReadable($path);
        require_once $path;
    }

    public function testCreateAndVerifySolution(): void
    {
        self::autoload();
        $key = bin2hex(random_bytes(16));
        $altcha = new Altcha($key);
        $options = new ChallengeOptions(
            maxNumber: 500,
            expires: (new \DateTimeImmutable())->add(new \DateInterval('PT5M')),
        );
        $challenge = $altcha->createChallenge($options);
        $payload = self::solve($challenge->algorithm, $challenge->challenge, $challenge->salt, $challenge->signature, $challenge->maxNumber);
        $this->assertNotNull($payload);
        $this->assertTrue($altcha->verifySolution($payload, true));
    }

    public function testEmptyPayloadFails(): void
    {
        self::autoload();
        $altcha = new Altcha(bin2hex(random_bytes(16)));
        $this->assertFalse($altcha->verifySolution('', true));
        $this->assertFalse($altcha->verifySolution([], true));
    }

    public function testWrongKeyFails(): void
    {
        self::autoload();
        $altcha = new Altcha(bin2hex(random_bytes(16)));
        $options = new ChallengeOptions(
            maxNumber: 200,
            expires: (new \DateTimeImmutable())->add(new \DateInterval('PT5M')),
        );
        $challenge = $altcha->createChallenge($options);
        $payload = self::solve($challenge->algorithm, $challenge->challenge, $challenge->salt, $challenge->signature, $challenge->maxNumber);
        $this->assertNotNull($payload);
        $other = new Altcha(bin2hex(random_bytes(16)));
        $this->assertFalse($other->verifySolution($payload, true));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function solve(string $algorithm, string $challenge, string $salt, string $signature, int $maxNumber): ?array
    {
        for ($n = 0; $n <= $maxNumber; $n++) {
            $hash = match ($algorithm) {
                'SHA-1' => hash('sha1', $salt . $n),
                'SHA-512' => hash('sha512', $salt . $n),
                default => hash('sha256', $salt . $n),
            };
            if (hash_equals($challenge, $hash)) {
                return array(
                    'algorithm' => $algorithm,
                    'challenge' => $challenge,
                    'number' => $n,
                    'salt' => $salt,
                    'signature' => $signature,
                );
            }
        }
        return null;
    }
}
