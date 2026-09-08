<?php
// This file is part of BoA - https://github.com/boa-project
namespace BoA\Core\Security;

defined('APP_EXEC') or die('Access not allowed');

/**
 * Opaque password-reset tokens: random token, store only a hash + expiry.
 * Pure helpers are unit-testable without full BoA bootstrap wiring.
 *
 * @package BoA
 * @subpackage Core
 */
class PasswordReset
{
    const TEMP_KEY = 'pass_reset';
    const TOKEN_TTL = 3600;
    const TOKEN_BYTES = 32;

    /**
     * Generate an opaque reset token (show to user / put in email once).
     * @return string
     */
    public static function generateToken()
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    /**
     * One-way hash of the opaque token for durable storage.
     * @param string $token
     * @return string
     */
    public static function hashToken($token)
    {
        return hash_hmac('sha256', $token, Crypto::getMasterKey());
    }

    /**
     * Build storage payload for user temporary data / prefs.
     * @param string $token Opaque token
     * @param int|null $now Unix time (tests)
     * @param int|null $ttl Seconds
     * @return array{hash:string,expires:int}
     */
    public static function createStoredPayload($token, $now = null, $ttl = null)
    {
        $now = $now === null ? time() : (int)$now;
        $ttl = $ttl === null ? self::TOKEN_TTL : (int)$ttl;
        return array(
            'hash' => self::hashToken($token),
            'expires' => $now + $ttl,
        );
    }

    /**
     * Validate opaque token against stored payload.
     * @param string $token
     * @param array|null $stored
     * @param int|null $now
     * @return bool
     */
    public static function validateToken($token, $stored, $now = null)
    {
        if (!is_array($stored) || empty($stored['hash']) || empty($stored['expires'])) {
            return false;
        }
        $now = $now === null ? time() : (int)$now;
        if ((int)$stored['expires'] < $now) {
            return false;
        }
        $expected = self::hashToken($token);
        return hash_equals($expected, $stored['hash']);
    }

    /**
     * Whether stored payload is expired.
     * @param array|null $stored
     * @param int|null $now
     * @return bool
     */
    public static function isExpired($stored, $now = null)
    {
        if (!is_array($stored) || empty($stored['expires'])) {
            return true;
        }
        $now = $now === null ? time() : (int)$now;
        return (int)$stored['expires'] < $now;
    }
}
