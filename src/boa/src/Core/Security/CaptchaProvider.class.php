<?php
// This file is part of BoA - https://github.com/boa-project
//
// BoA is free software: you can redistribute it and/or modify
// it under the terms of the GNU Affero General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// BoA is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU Affero General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with BoA.  If not, see <http://www.gnu.org/licenses/>.
//
// The latest code can be found at <https://github.com/boa-project/>.

/**
 * Simple GD CAPTCHA for brute-force login protection (PHP 8.3, no securimage).
 *
 * @package    BoA
 * @category   Core
 * @copyright  2017 BoA Project
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU Affero GPL v3 or later
 */
namespace BoA\Core\Security;

defined('APP_EXEC') or die('Access not allowed');

/**
 * Generate and verify a CAPTCHA image for brute-force login attempts.
 * @package BoA
 * @subpackage Core
 */
class CaptchaProvider
{
    private const SESSION_CODE = 'boa_captcha_code';
    private const SESSION_TIME = 'boa_captcha_ctime';
    private const EXPIRY_SECONDS = 900;
    private const CHARSET = 'ABCDEFGHKLMNPRSTUVWXYZ23456789';

    /**
     * Print out a Captcha image
     * @static
     * @return void
     */
    public static function sendCaptcha()
    {
        self::ensureSession();

        if (!function_exists('imagecreatetruecolor')) {
            header('HTTP/1.1 500 Internal Server Error');
            echo 'GD extension required for captcha';
            exit;
        }

        $code = self::randomCode(5);
        $_SESSION[self::SESSION_CODE] = strtolower($code);
        $_SESSION[self::SESSION_TIME] = time();

        $width = 170;
        $height = 80;
        $im = imagecreatetruecolor($width, $height);
        $bg = imagecolorallocate($im, 246, 246, 246);
        imagefilledrectangle($im, 0, 0, $width, $height, $bg);

        for ($i = 0; $i < 5; $i++) {
            $line = imagecolorallocate($im, 220 + rand(0, 20), 220 + rand(0, 20), 220 + rand(0, 20));
            imageline($im, rand(0, $width), rand(0, $height), rand(0, $width), rand(0, $height), $line);
        }

        $colors = array(
            imagecolorallocate($im, 51, 153, 255),
            imagecolorallocate($im, 51, 0, 204),
            imagecolorallocate($im, 51, 51, 204),
            imagecolorallocate($im, 102, 102, 255),
            imagecolorallocate($im, 153, 204, 204),
        );

        $len = strlen($code);
        $slot = (int) ($width / ($len + 1));
        for ($i = 0; $i < $len; $i++) {
            $color = $colors[array_rand($colors)];
            $size = 5;
            $x = $slot * ($i + 1) - 8 + rand(-2, 2);
            $y = (int) ($height / 2) - 8 + rand(-6, 6);
            imagestring($im, $size, $x, $y, $code[$i], $color);
        }

        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
        header('Last-Modified: '.gmdate('D, d M Y H:i:s').' GMT');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Content-Type: image/png');
        imagepng($im);
        imagedestroy($im);
        exit;
    }

    /**
     * Verify the code against the current image.
     * @static
     * @param string $code
     * @return bool
     */
    public static function checkCaptchaResult($code)
    {
        self::ensureSession();

        if (!isset($_SESSION[self::SESSION_CODE]) || trim((string) $_SESSION[self::SESSION_CODE]) === '') {
            return false;
        }
        if (!isset($_SESSION[self::SESSION_TIME]) || self::isExpired((int) $_SESSION[self::SESSION_TIME])) {
            unset($_SESSION[self::SESSION_CODE], $_SESSION[self::SESSION_TIME]);
            return false;
        }

        $ok = hash_equals((string) $_SESSION[self::SESSION_CODE], strtolower(trim((string) $code)));
        unset($_SESSION[self::SESSION_CODE], $_SESSION[self::SESSION_TIME]);
        return $ok;
    }

    private static function ensureSession()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    private static function isExpired($ctime)
    {
        return (time() - $ctime) >= self::EXPIRY_SECONDS;
    }

    private static function randomCode($length)
    {
        $out = '';
        $max = strlen(self::CHARSET) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= self::CHARSET[random_int(0, $max)];
        }
        return $out;
    }
}
