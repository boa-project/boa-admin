<?php
// This file is part of BoA - https://github.com/boa-project
namespace BoA\Core\Utils;

defined('APP_EXEC') or die('Access not allowed');

/**
 * Minimal SMTP client for password-reset and system mail.
 * Dev: SMTP_HOST=mailpit SMTP_PORT=1025 (compose). Falls back to PHP mail().
 *
 * @package BoA
 * @subpackage Core
 */
class SmtpMailer
{
    /**
     * Send a simple text email. Prefer SMTP when SMTP_HOST is set.
     * Also tries mailer.phpmailer-lite plugin when available.
     *
     * @param string|array $to
     * @param string $subject
     * @param string $body
     * @param string|null $from
     * @return bool
     */
    public static function send($to, $subject, $body, $from = null)
    {
        $recipients = is_array($to) ? $to : array($to);
        $recipients = array_values(array_filter(array_map('trim', $recipients)));
        if (count($recipients) === 0) {
            return false;
        }
        if ($from === null || $from === '') {
            $from = self::defaultFrom();
        }

        // Docker / explicit SMTP wins over phpmailer-lite (PHP mail() often hangs in containers).
        $host = getenv('SMTP_HOST');
        if ($host !== false && $host !== '') {
            $port = getenv('SMTP_PORT');
            $port = ($port !== false && $port !== '') ? (int)$port : 1025;
            return self::sendViaSmtp($recipients, $subject, $body, $from, $host, $port);
        }

        if (class_exists('BoA\\Core\\Services\\PluginsService', false)
            || class_exists('BoA\\Core\\Services\\PluginsService')
        ) {
            try {
                $plugins = \BoA\Core\Services\PluginsService::getInstance();
                $mailerPlug = $plugins->getPluginById('mailer.phpmailer-lite');
                if ($mailerPlug !== null && method_exists($mailerPlug, 'sendMail')) {
                    $mailerPlug->sendMail($recipients, $subject, $body, array($from));
                    return true;
                }
            } catch (\Exception $e) {
                // Fall through to mail()
            }
        }

        $headers = 'From: ' . $from . "\r\n" .
            'Content-Type: text/plain; charset=UTF-8';
        $ok = true;
        foreach ($recipients as $addr) {
            $ok = @mail($addr, $subject, $body, $headers) && $ok;
        }
        return $ok;
    }

    /**
     * @return string
     */
    public static function defaultFrom()
    {
        $from = getenv('MAIL_FROM');
        if ($from !== false && $from !== '') {
            return $from;
        }
        return 'boa-admin@localhost';
    }

    /**
     * Minimal unauthenticated SMTP (Mailpit / local relays).
     * @param array $recipients
     * @param string $subject
     * @param string $body
     * @param string $from
     * @param string $host
     * @param int $port
     * @return bool
     */
    public static function sendViaSmtp(array $recipients, $subject, $body, $from, $host, $port)
    {
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, 10);
        if (!$fp) {
            return false;
        }
        stream_set_timeout($fp, 10);
        try {
            if (!self::smtpExpect($fp, 220)) {
                return false;
            }
            self::smtpWrite($fp, 'EHLO boa-admin');
            if (!self::smtpExpect($fp, 250)) {
                self::smtpWrite($fp, 'HELO boa-admin');
                if (!self::smtpExpect($fp, 250)) {
                    return false;
                }
            }
            self::smtpWrite($fp, 'MAIL FROM:<' . self::smtpAddr($from) . '>');
            if (!self::smtpExpect($fp, 250)) {
                return false;
            }
            foreach ($recipients as $addr) {
                self::smtpWrite($fp, 'RCPT TO:<' . self::smtpAddr($addr) . '>');
                if (!self::smtpExpect($fp, 250)) {
                    return false;
                }
            }
            self::smtpWrite($fp, 'DATA');
            if (!self::smtpExpect($fp, 354)) {
                return false;
            }
            $toHeader = implode(', ', $recipients);
            $payload = 'From: ' . $from . "\r\n" .
                'To: ' . $toHeader . "\r\n" .
                'Subject: ' . self::encodeHeader($subject) . "\r\n" .
                "MIME-Version: 1.0\r\n" .
                "Content-Type: text/plain; charset=UTF-8\r\n" .
                "\r\n" .
                str_replace(array("\r\n.", "\n."), array("\r\n..", "\n.."), $body) .
                "\r\n.";
            self::smtpWrite($fp, $payload);
            if (!self::smtpExpect($fp, 250)) {
                return false;
            }
            self::smtpWrite($fp, 'QUIT');
            return true;
        } finally {
            fclose($fp);
        }
    }

    private static function smtpAddr($email)
    {
        if (preg_match('/<([^>]+)>/', $email, $m)) {
            return $m[1];
        }
        return $email;
    }

    private static function encodeHeader($value)
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    private static function smtpWrite($fp, $line)
    {
        fwrite($fp, $line . "\r\n");
    }

    private static function smtpExpect($fp, $code)
    {
        $response = '';
        while (($line = fgets($fp, 512)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
            if (!isset($line[3]) || $line[3] !== '-') {
                break;
            }
        }
        return (strpos($response, (string)$code) === 0);
    }
}
