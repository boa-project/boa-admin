<?php

declare(strict_types=1);

namespace BoA\Tests\Http;

/**
 * Registry / GUI client endpoints (post-login).
 */
final class Http02RegistryGuiTest extends AbstractHttpEndpointTest
{
    public function test_get_get_xml_registry_full(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getActionWithRetry('get_xml_registry');
        HttpAssertions::assertNoUnhandledPhpFault($response);
        $this->assertNotSame('', $response['body'], 'get_xml_registry returned empty body');
        $this->assertStringContainsString('<registry', $response['body']);
        $this->assertStringContainsString('<actions>', $response['body']);
    }

    /** @depends test_get_get_xml_registry_full */
    public function test_get_get_xml_registry_user_xpaths(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('get_xml_registry', ['xPath' => 'user']);
        HttpAssertions::assertNoUnhandledPhpFault($response);
        $this->assertNotSame('', $response['body']);
    }

    /** @depends test_get_get_xml_registry_user_xpaths */
    public function test_get_get_i18n_messages_default(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('get_i18n_messages');
        HttpAssertions::assertNoUnhandledPhpFault($response);
        $this->assertNotSame('', $response['body']);
    }

    /** @depends test_get_get_i18n_messages_default */
    public function test_get_get_editors_registry(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('get_editors_registry');
        HttpAssertions::assertNoUnhandledPhpFault($response);
    }

    /** @depends test_get_get_editors_registry */
    public function test_get_get_template_login_form(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('get_template', [
            'template_name' => 'login_form.html',
            'pluginName' => 'gui.ajax',
            'encode' => 'false',
        ]);
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'not found',
            'Unable',
            'does not exist',
            'template',
        ]);
    }

    /** @depends test_get_get_template_login_form */
    public function test_get_display_doc_readme(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('display_doc', ['doc_file' => 'CREDITS']);
        HttpAssertions::assertNoUnhandledPhpFault($response, true);
    }

    /** @depends test_get_display_doc_readme */
    public function test_get_splash_screen(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('splash');
        HttpAssertions::assertNoUnhandledPhpFault($response, true);
    }

    /** @depends test_get_splash_screen */
    public function test_get_refresh_noop(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('refresh');
        HttpAssertions::assertNoUnhandledPhpFault($response, true);
    }
}
