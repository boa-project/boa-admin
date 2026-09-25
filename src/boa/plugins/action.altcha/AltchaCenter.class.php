<?php
// This file is part of BoA - https://github.com/boa-project
namespace BoA\Plugins\Action\Altcha;

use BoA\Core\Http\HTMLWriter;
use BoA\Core\Plugins\Plugin;
use BoA\Core\Services\ConfService;
use BoA\Core\Services\PluginsService;

defined('APP_EXEC') or die('Access not allowed');

/**
 * ALTCHA proof-of-work captcha for login and password recovery.
 * @package Plugins
 * @subpackage Action
 */
class AltchaCenter extends Plugin
{
    const FIELD_NAME = 'boa_altcha';
    const SESSION_KEYS = 'boa_altcha_keys';

    /**
     * @return bool
     */
    public static function isProtectionActive()
    {
        if (getenv('BOA_ALTCHA_DISABLED') === '1') {
            return false;
        }
        $plug = self::getInstanceIfAvailable();
        if ($plug === null) {
            return false;
        }
        $enabled = $plug->getConfigs();
        $flag = $enabled['ENABLED'] ?? true;
        return ($flag === true || $flag === 'true' || $flag === 1 || $flag === '1');
    }

    /**
     * @return AltchaCenter|null
     */
    public static function getInstanceIfAvailable()
    {
        try {
            $plug = PluginsService::getInstance()->getPluginById('action.altcha');
        } catch (\Exception $e) {
            return null;
        }
        if ($plug === null || !($plug instanceof AltchaCenter)) {
            return null;
        }
        if (!$plug->isEnabled()) {
            return null;
        }
        return $plug;
    }

    /**
     * Verify payload for a target. Returns true when protection is inactive.
     * @param string $target
     * @param array $httpVars
     * @return bool
     */
    public static function verify($target, $httpVars)
    {
        if (!self::isProtectionActive()) {
            return true;
        }
        $plug = self::getInstanceIfAvailable();
        if ($plug === null) {
            return true;
        }
        return $plug->verifySolution($target, $httpVars[self::FIELD_NAME] ?? '');
    }

    /**
     * @param string $action
     * @param array $httpVars
     * @param array $fileVars
     * @return void
     */
    public function switchAction($action, $httpVars, $fileVars)
    {
        if ($action !== 'get_altcha_challenge') {
            return;
        }
        $target = isSet($httpVars['target']) ? preg_replace('/[^a-z0-9_]/i', '', $httpVars['target']) : 'login';
        if ($target === '') {
            $target = 'login';
        }
        HTMLWriter::charsetHeader('application/json');
        if (!self::isProtectionActive()) {
            print json_encode(array('ok' => false, 'disabled' => true));
            return;
        }
        print json_encode($this->buildWidgetParams($target));
    }

    /**
     * @param string $target
     * @return array
     */
    public function buildWidgetParams($target)
    {
        $this->ensureAutoload();
        $hmacKey = $this->sessionKeyFor($target);
        $altcha = new \AltchaOrg\Altcha\Altcha($hmacKey);
        $configs = $this->getConfigs();
        $maxNumber = intval($configs['LEVEL'] ?? 100000);
        if ($maxNumber < 1) {
            $maxNumber = 100000;
        }
        $validTime = $configs['VALID_TIME'] ?? '2M';
        $expires = (new \DateTimeImmutable())->add(new \DateInterval('PT' . strtoupper($validTime)));
        $options = new \AltchaOrg\Altcha\ChallengeOptions(
            maxNumber: $maxNumber,
            expires: $expires,
        );
        $challenge = $altcha->createChallenge($options);
        $messages = ConfService::getMessages();
        $plugMess = $this->loadPluginMessages();
        $strings = array(
            'label' => $plugMess[1] ?? 'I am not a robot',
            'verified' => $plugMess[2] ?? 'Verified',
            'verifying' => $plugMess[3] ?? 'Verifying...',
            'error' => $plugMess[4] ?? (isSet($messages[493]) ? $messages[493] : 'Verification error.'),
            'expired' => $plugMess[5] ?? 'Verification expired.',
            'footer' => $plugMess[6] ?? 'Protected by ALTCHA',
            'ariaLinkLabel' => $plugMess[7] ?? 'Visit Altcha.org',
            'waitAlert' => $plugMess[8] ?? 'Verifying... please wait.',
        );
        return array(
            'ok' => true,
            'name' => self::FIELD_NAME,
            'maxnumber' => $maxNumber,
            'challengejson' => json_encode($challenge),
            'strings' => json_encode($strings),
        );
    }

    /**
     * @return array
     */
    private function loadPluginMessages()
    {
        $lang = ConfService::getLanguage();
        $base = dirname(__FILE__) . '/i18n/';
        $file = $base . $lang . '.php';
        if (!is_file($file)) {
            $file = $base . 'en.php';
        }
        $mess = array();
        if (is_file($file)) {
            include $file;
        }
        return is_array($mess) ? $mess : array();
    }

    /**
     * @param string $target
     * @param string $payloadBase64
     * @return bool
     */
    public function verifySolution($target, $payloadBase64)
    {
        if ($payloadBase64 === null || $payloadBase64 === '') {
            return false;
        }
        if (!isSet($_SESSION[self::SESSION_KEYS]) || !isSet($_SESSION[self::SESSION_KEYS][$target])) {
            return false;
        }
        $this->ensureAutoload();
        $payload = (array)@json_decode(base64_decode($payloadBase64, true) ?: '', true);
        if (!is_array($payload) || empty($payload)) {
            return false;
        }
        $altcha = new \AltchaOrg\Altcha\Altcha($_SESSION[self::SESSION_KEYS][$target]);
        $ok = $altcha->verifySolution($payload, true);
        if ($ok) {
            // One-time use: rotate key for this target.
            unset($_SESSION[self::SESSION_KEYS][$target]);
        }
        return $ok;
    }

    /**
     * @param string $target
     * @return string
     */
    private function sessionKeyFor($target)
    {
        if (!isSet($_SESSION[self::SESSION_KEYS]) || !is_array($_SESSION[self::SESSION_KEYS])) {
            $_SESSION[self::SESSION_KEYS] = array();
        }
        if (!isSet($_SESSION[self::SESSION_KEYS][$target])) {
            $_SESSION[self::SESSION_KEYS][$target] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEYS][$target];
    }

    private function ensureAutoload()
    {
        $autoload = dirname(__FILE__) . '/thirdparty/altcha/vendor/autoload.php';
        if (is_readable($autoload)) {
            require_once $autoload;
        }
    }
}
