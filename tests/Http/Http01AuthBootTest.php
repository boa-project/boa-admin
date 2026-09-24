<?php

declare(strict_types=1);

namespace BoA\Tests\Http;

/**
 * Auth + boot endpoints. First phase of the sequential HTTP battery.
 */
final class Http01AuthBootTest extends AbstractHttpEndpointTest
{
    public function test_get_get_secure_token_anonymous(): void
    {
        $token = $this->client()->getSecureToken();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
    }

    /** @depends test_get_get_secure_token_anonymous */
    public function test_get_get_boot_conf_anonymous(): void
    {
        $response = $this->getAction('get_boot_conf', [], false);
        HttpAssertions::assertNoUnhandledPhpFault($response);
        $json = HttpAssertions::assertJsonObject($response['body']);
        $this->assertArrayHasKey('usersEnabled', $json);
    }

    /** @depends test_get_get_boot_conf_anonymous */
    public function test_get_get_seed_anonymous(): void
    {
        $response = $this->getAction('get_seed', [], false);
        // Seed may be empty string "-1" style or blank depending on auth plugin config.
        HttpAssertions::assertNoUnhandledPhpFault($response, true);
    }

    /** @depends test_get_get_seed_anonymous */
    public function test_get_get_boot_gui_anonymous(): void
    {
        $response = $this->getAction('get_boot_gui', [], false);
        HttpAssertions::assertNoUnhandledPhpFault($response);
        $this->assertNotSame('', $response['body']);
    }

    /** @depends test_get_get_boot_gui_anonymous */
    public function test_get_get_captcha_anonymous(): void
    {
        $response = $this->getAction('get_captcha', [], false);
        // Captcha may be binary/image or disabled — only reject PHP faults.
        HttpAssertions::assertNoUnhandledPhpFault($response, true);
    }

    /** @depends test_get_get_captcha_anonymous */
    public function test_get_ls_unauthenticated_requires_auth(): void
    {
        $anon = new BoaHttpClient($this->client()->baseUrl());
        $anon->getSecureToken();
        $response = $anon->get([
            'get_action' => 'ls',
            'options' => 'al',
            'dir' => '/',
        ]);
        HttpAssertions::assertNoUnhandledPhpFault($response);
        $this->assertTrue(
            str_contains($response['body'], 'require_auth')
            || str_contains($response['body'], 'You are not allowed')
            || str_contains($response['body'], 'type="ERROR"'),
            'Unauthenticated ls should require auth or return an application error'
        );
    }

    /** @depends test_get_ls_unauthenticated_requires_auth */
    public function test_post_login_admin_success(): void
    {
        $user = getenv('BOA_TEST_USER') ?: 'admin';
        $password = getenv('BOA_TEST_PASSWORD') ?: 'admin';

        $attempt = function () use ($user, $password): array {
            return $this->postAction(
                'login',
                [
                    'userid' => $user,
                    'password' => $password,
                    'login_seed' => '-1',
                    'secure_token' => $this->client()->secureToken() ?? $this->client()->getSecureToken(),
                ],
                [],
                false,
                'get_action'
            );
        };

        $response = $attempt();
        if ($response['body'] === '') {
            $this->client()->getSecureToken();
            $response = $attempt();
        }
        HttpAssertions::assertNoUnhandledPhpFault($response);
        $this->assertNotSame('', $response['body'], 'login returned empty body');
        HttpAssertions::assertXmlRoot($response['body'], 'tree');
        $this->assertMatchesRegularExpression(
            '/logging_result[^>]*value="1"/',
            $response['body'],
            'Login failed: ' . substr($response['body'], 0, 400)
        );

        if (preg_match('/secure_token="([a-f0-9]{32})"/', $response['body'], $m)) {
            $this->client()->setSecureToken($m[1]);
        } else {
            $this->client()->getSecureToken();
        }
        HttpSharedState::markLoggedIn(true);
    }

    /** @depends test_post_login_admin_success */
    public function test_get_get_secure_token_authenticated(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('get_secure_token');
        HttpAssertions::assertNoUnhandledPhpFault($response);
        $this->assertMatchesRegularExpression('/[a-f0-9]{32}/', $response['body']);
    }

    /** @depends test_get_get_secure_token_authenticated */
    public function test_post_forgot_password_unknown_user_expected_app_response(): void
    {
        $this->skipUnlessLoggedIn();
        // Still logged in; endpoint should not throw PHP faults even for unknown users.
        $response = $this->postAction(
            'forgot_password',
            ['userid' => 'http_test_unknown_user_' . HttpSharedState::runId()],
            [],
            true,
            'get_action'
        );
        HttpAssertions::assertNoUnhandledPhpFault($response);
    }
}
