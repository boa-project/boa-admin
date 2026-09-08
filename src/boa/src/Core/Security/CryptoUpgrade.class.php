<?php
// This file is part of BoA - https://github.com/boa-project
namespace BoA\Core\Security;

use BoA\Core\Services\AuthService;
use BoA\Core\Services\ConfService;

defined('APP_EXEC') or die('Access not allowed');

/**
 * One-shot upgrade: invalidate legacy secrets, flag forced password reset,
 * delete mcrypt publiclets.
 *
 * @package BoA
 * @subpackage Core
 */
class CryptoUpgrade
{
    const FLAG_FILE = 'openssl_crypto_migrated';

    /**
     * Run migration once per install (flag file under APP_CACHE_DIR).
     * @return bool True if migration ran
     */
    public static function runIfNeeded()
    {
        if (!defined('APP_CACHE_DIR') || !is_dir(APP_CACHE_DIR)) {
            return false;
        }
        $flag = APP_CACHE_DIR . '/' . self::FLAG_FILE;
        if (is_file($flag)) {
            return false;
        }
        self::run();
        @file_put_contents($flag, date('c'));
        return true;
    }

    /**
     * Force migration regardless of flag (tests / ops).
     * @return void
     */
    public static function run()
    {
        self::invalidatePubliclets();
        self::flagUsersAndClearSecrets();
    }

    /**
     * Delete publiclet scripts that still embed mcrypt decrypt bootstrap.
     * @return int Number of files removed
     */
    public static function invalidatePubliclets()
    {
        $folder = ConfService::getCoreConf('PUBLIC_DOWNLOAD_FOLDER');
        if (empty($folder) || !is_dir($folder)) {
            return 0;
        }
        $removed = 0;
        foreach (glob($folder . '/*.php') as $file) {
            $base = basename($file);
            if ($base === 'index.php' || $base === '.htaccess') {
                continue;
            }
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }
            if (strpos($content, 'mcrypt_') !== false) {
                // Legacy mcrypt publiclets — remove so shares are regenerated with OpenSSL.
                if (@unlink($file)) {
                    $removed++;
                    $hash = preg_replace('/\.php$/', '', $base);
                    if (class_exists('BoA\\Plugins\\Action\\Share\\PublicletCounter', false)
                        || class_exists('BoA\\Plugins\\Action\\Share\\PublicletCounter')
                    ) {
                        try {
                            \BoA\Plugins\Action\Share\PublicletCounter::delete($hash);
                        } catch (\Exception $e) {
                            // ignore
                        }
                    }
                }
            }
        }
        return $removed;
    }

    /**
     * Flag all accounts for password reset; clear WebDAV PASS and wallet secrets
     * that are not Crypto v1 payloads.
     * @return void
     */
    public static function flagUsersAndClearSecrets()
    {
        if (!AuthService::usersEnabled()) {
            return;
        }
        try {
            $auth = ConfService::getAuthDriverImpl();
            $conf = ConfService::getConfStorageImpl();
        } catch (\Exception $e) {
            return;
        }
        if ($auth === null || $conf === null || !method_exists($auth, 'listUsers')) {
            return;
        }
        $users = $auth->listUsers('/');
        if (!is_array($users)) {
            return;
        }
        foreach (array_keys($users) as $userId) {
            if (AuthService::isReservedUserId($userId)) {
                continue;
            }
            try {
                $userObject = $conf->createUserObject($userId);
            } catch (\Exception $e) {
                continue;
            }
            if (AuthService::changePasswordEnabled()) {
                $userObject->setLock('pass_change');
            }
            $dav = $userObject->getPref('APP_WEBDAV_DATA');
            if (is_array($dav) && isset($dav['PASS']) && !Crypto::isV1Payload($dav['PASS'])) {
                unset($dav['PASS']);
                $userObject->setPref('APP_WEBDAV_DATA', $dav);
            }
            $wallet = $userObject->getPref('APP_WALLET');
            if (is_array($wallet)) {
                $changed = false;
                foreach ($wallet as $repoId => $cred) {
                    if (is_array($cred) && isset($cred['PASS']) && !Crypto::isV1Payload($cred['PASS'])) {
                        unset($wallet[$repoId]['PASS']);
                        $changed = true;
                    }
                    if (is_array($cred) && isset($cred['FTP_PASS']) && !Crypto::isV1Payload($cred['FTP_PASS'])) {
                        unset($wallet[$repoId]['FTP_PASS']);
                        $changed = true;
                    }
                }
                if ($changed) {
                    $userObject->setPref('APP_WALLET', $wallet);
                }
            }
            $userObject->save('superuser');
        }
    }
}
