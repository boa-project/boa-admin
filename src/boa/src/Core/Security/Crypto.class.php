<?php
// This file is part of BoA - https://github.com/boa-project
namespace BoA\Core\Security;

defined('APP_EXEC') or die('Access not allowed');

/**
 * OpenSSL AES-256-GCM helper for reversible secrets.
 * Payload format: v1:<base64(iv || tag || ciphertext)>
 * No dual-read of legacy mcrypt/Rijndael ciphertext.
 *
 * @package BoA
 * @subpackage Core
 */
class Crypto
{
    const VERSION = 'v1';
    const CIPHER = 'aes-256-gcm';
    const IV_LENGTH = 12;
    const TAG_LENGTH = 16;
    const HKDF_INFO = 'boa-crypto-v1';

    /**
     * Whether OpenSSL AES-256-GCM is available.
     * @return bool
     */
    public static function isAvailable()
    {
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
            return false;
        }
        $methods = openssl_get_cipher_methods();
        return in_array(self::CIPHER, $methods) || in_array(strtoupper(self::CIPHER), $methods);
    }

    /**
     * Encrypt plaintext. Optional $context binds derived key / AAD (e.g. userId, publiclet hash).
     * @param string $plaintext
     * @param string $context
     * @return string Version-prefixed ciphertext
     * @throws \RuntimeException
     */
    public static function encrypt($plaintext, $context = '')
    {
        if (!self::isAvailable()) {
            throw new \RuntimeException('OpenSSL AES-256-GCM is not available');
        }
        $key = self::deriveKey($context);
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            (string)$context,
            self::TAG_LENGTH
        );
        if ($ciphertext === false || strlen($tag) !== self::TAG_LENGTH) {
            throw new \RuntimeException('openssl_encrypt failed');
        }
        return self::VERSION . ':' . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a version-prefixed payload. Returns false on failure / legacy / tamper.
     * @param string $payload
     * @param string $context
     * @return string|false
     */
    public static function decrypt($payload, $context = '')
    {
        if (!is_string($payload) || $payload === '') {
            return false;
        }
        $prefix = self::VERSION . ':';
        if (strpos($payload, $prefix) !== 0) {
            // Legacy mcrypt ciphertext or corrupt data — do not attempt dual-read.
            return false;
        }
        if (!self::isAvailable()) {
            return false;
        }
        $raw = base64_decode(substr($payload, strlen($prefix)), true);
        if ($raw === false || strlen($raw) < self::IV_LENGTH + self::TAG_LENGTH + 1) {
            return false;
        }
        $iv = substr($raw, 0, self::IV_LENGTH);
        $tag = substr($raw, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($raw, self::IV_LENGTH + self::TAG_LENGTH);
        $key = self::deriveKey($context);
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            (string)$context
        );
        return ($plaintext === false) ? false : $plaintext;
    }

    /**
     * Whether a stored value looks like a Crypto v1 payload.
     * @param string $payload
     * @return bool
     */
    public static function isV1Payload($payload)
    {
        return is_string($payload) && strpos($payload, self::VERSION . ':') === 0;
    }

    /**
     * Master secret from APP_SECRET_KEY constant, env, or legacy APP_SAFE_SECRET_KEY.
     * @return string
     */
    public static function getMasterKey()
    {
        if (defined('APP_SECRET_KEY') && APP_SECRET_KEY !== '') {
            return (string)APP_SECRET_KEY;
        }
        $env = getenv('APP_SECRET_KEY');
        if ($env !== false && $env !== '') {
            return (string)$env;
        }
        if (defined('APP_SAFE_SECRET_KEY') && APP_SAFE_SECRET_KEY !== '') {
            return (string)APP_SAFE_SECRET_KEY;
        }
        return "\1CDAFx¨op#";
    }

    /**
     * HKDF-SHA256 derived 32-byte key for AES-256-GCM.
     * @param string $context
     * @return string
     */
    public static function deriveKey($context = '')
    {
        $info = self::HKDF_INFO;
        if ($context !== '') {
            $info .= '|' . $context;
        }
        return hash_hkdf('sha256', self::getMasterKey(), 32, $info);
    }
}
