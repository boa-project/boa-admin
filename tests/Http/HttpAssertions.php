<?php

declare(strict_types=1);

namespace BoA\Tests\Http;

use PHPUnit\Framework\Assert;

/**
 * Shared assertions for boa-admin HTTP endpoint responses.
 */
final class HttpAssertions
{
    /** Patterns that indicate PHP 8 / runtime faults we are migrating away from. */
    private const UNHANDLED_ERROR_PATTERNS = [
        '/Undefined array key/i',
        '/Undefined variable/i',
        '/Undefined property/i',
        '/Trying to access array offset/i',
        '/mkdir\(\):\s*Permission denied/i',
        '/Failed to open stream/i',
        '/Only variables should be passed by reference/i',
        '/A non-numeric value encountered/i',
        '/Uncaught (Error|Exception|ErrorException)/i',
        '/Fatal error/i',
        '/must not be accessed before initialization/i',
        '/Too few arguments to function/i',
        '/ArgumentCountError/i',
        '/TypeError/i',
        '/Call to undefined (function|method)/i',
        '/Cannot use .* as .* because .* already in use/i',
    ];

    /**
     * @param array{http_code:int,body:string,content_type:string} $response
     */
    public static function assertHttpOk(array $response): void
    {
        Assert::assertGreaterThan(0, $response['http_code'], 'HTTP request failed to connect: ' . $response['body']);
        Assert::assertLessThan(500, $response['http_code'], 'Unexpected HTTP 5xx: ' . substr($response['body'], 0, 400));
    }

    /**
     * Response must not surface an unhandled PHP fault via BoA's XML error toast.
     * Empty 2xx bodies are treated as OK (many client-only / silent actions return nothing).
     *
     * @param array{http_code:int,body:string,content_type:string} $response
     */
    public static function assertNoUnhandledPhpFault(array $response, bool $allowEmptyBody = true): void
    {
        self::assertHttpOk($response);
        $body = $response['body'];
        if ($body === '') {
            if ($allowEmptyBody) {
                return;
            }
            Assert::fail('Empty response body');
        }

        foreach (self::UNHANDLED_ERROR_PATTERNS as $pattern) {
            if (preg_match($pattern, $body)) {
                $message = self::extractErrorMessage($body) ?? substr($body, 0, 500);
                Assert::fail("Unhandled PHP fault in response: {$message}");
            }
        }
    }

    /**
     * Success path: no PHP fault and no application ERROR message.
     *
     * @param array{http_code:int,body:string,content_type:string} $response
     */
    public static function assertSuccessNoError(array $response, bool $allowEmptyBody = false): void
    {
        self::assertNoUnhandledPhpFault($response, $allowEmptyBody);
        $message = self::extractErrorMessage($response['body']);
        Assert::assertNull(
            $message,
            'Unexpected application ERROR: ' . ($message ?? '')
        );
    }

    /**
     * Allow an application-level ERROR (e.g. require_auth) but still reject PHP faults.
     *
     * @param array{http_code:int,body:string,content_type:string} $response
     * @param list<string> $allowedMessageSubstrings
     */
    public static function assertExpectedAppError(array $response, array $allowedMessageSubstrings): void
    {
        self::assertNoUnhandledPhpFault($response);
        $message = self::extractErrorMessage($response['body']);
        Assert::assertNotNull($message, 'Expected an application ERROR message');
        foreach ($allowedMessageSubstrings as $needle) {
            if (stripos($message, $needle) !== false) {
                return;
            }
        }
        Assert::fail('ERROR message was not one of the expected application errors: ' . $message);
    }

    /**
     * Either success (no ERROR) or an ERROR whose text matches one of the allowed needles.
     *
     * @param array{http_code:int,body:string,content_type:string} $response
     * @param list<string> $allowedErrorSubstrings
     */
    public static function assertSuccessOrExpectedAppError(array $response, array $allowedErrorSubstrings): void
    {
        self::assertNoUnhandledPhpFault($response);
        $message = self::extractErrorMessage($response['body']);
        if ($message === null) {
            return;
        }
        foreach ($allowedErrorSubstrings as $needle) {
            if (stripos($message, $needle) !== false) {
                return;
            }
        }
        Assert::fail('ERROR was not success and not an expected application error: ' . $message);
    }

    public static function extractErrorMessage(string $body): ?string
    {
        if (preg_match('/type="ERROR"[^>]*>([^<]+)/', $body, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_XML1);
        }
        return null;
    }

    public static function assertXmlRoot(string $body, string $rootName): void
    {
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($body);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        Assert::assertNotFalse($doc, 'Response is not valid XML: ' . substr($body, 0, 300));
        Assert::assertSame($rootName, $doc->getName(), 'Unexpected XML root element');
    }

    public static function assertJsonObject(string $body): array
    {
        $json = json_decode($body, true);
        Assert::assertIsArray($json, 'Expected JSON object/array, got: ' . substr($body, 0, 300));
        return $json;
    }
}
