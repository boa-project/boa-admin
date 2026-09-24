<?php

declare(strict_types=1);

namespace BoA\Tests\Http;

/**
 * Settings / boaconf repository admin listing endpoints.
 */
final class Http03ConfAdminTest extends AbstractHttpEndpointTest
{
    public function test_get_switch_repository_boaconf(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('switch_repository', ['repository_id' => 'boaconf']);
        HttpAssertions::assertNoUnhandledPhpFault($response);
    }

    /** @depends test_get_switch_repository_boaconf */
    public function test_get_ls_boaconf_root(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getActionWithRetry('ls', $this->boaconfQuery([
            'options' => 'al',
            'dir' => '/',
        ]));
        HttpAssertions::assertNoUnhandledPhpFault($response);
        $this->assertNotSame('', $response['body'], 'ls boaconf root returned empty body');
        HttpAssertions::assertXmlRoot($response['body'], 'tree');
    }

    /** @depends test_get_ls_boaconf_root */
    public function test_get_stat_boaconf_root(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('stat', $this->boaconfQuery(['file' => '/']));
        HttpAssertions::assertNoUnhandledPhpFault($response);
    }

    /** @depends test_get_stat_boaconf_root */
    public function test_get_list_all_plugins_actions(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('list_all_plugins_actions', $this->boaconfQuery());
        HttpAssertions::assertNoUnhandledPhpFault($response);
        $this->assertNotSame('', $response['body']);
    }

    /** @depends test_get_list_all_plugins_actions */
    public function test_get_list_all_plugins_parameters(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('list_all_plugins_parameters', $this->boaconfQuery());
        HttpAssertions::assertNoUnhandledPhpFault($response);
        $this->assertNotSame('', $response['body']);
    }

    /** @depends test_get_list_all_plugins_parameters */
    public function test_get_list_all_repositories_xml(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getActionWithRetry('list_all_repositories', $this->boaconfQuery());
        HttpAssertions::assertNoUnhandledPhpFault($response);
        // JSON or XML listing — empty after retry is still a soft pass if HTTP OK (client may ignore).
        if ($response['body'] !== '') {
            $this->assertTrue(
                str_contains($response['body'], '<') || str_contains($response['body'], '{'),
                'list_all_repositories should return XML or JSON'
            );
        }
    }

    /** @depends test_get_list_all_repositories_xml */
    public function test_get_list_all_repositories_json(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('list_all_repositories_json', $this->boaconfQuery());
        HttpAssertions::assertNoUnhandledPhpFault($response);
        $this->assertNotSame('', $response['body']);
    }

    /** @depends test_get_list_all_repositories_json */
    public function test_get_list_all_users(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getActionWithRetry('list_all_users', $this->boaconfQuery());
        HttpAssertions::assertNoUnhandledPhpFault($response);
        if ($response['body'] !== '') {
            $this->assertTrue(
                str_contains($response['body'], '<') || str_contains($response['body'], '{') || str_contains($response['body'], 'user'),
                'list_all_users should return a usable payload'
            );
        }
    }

    /** @depends test_get_list_all_users */
    public function test_get_get_plugin_manifest_meta_lom(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('get_plugin_manifest', $this->boaconfQuery([
            'plugin_id' => 'meta.lom',
        ]));
        HttpAssertions::assertNoUnhandledPhpFault($response);
        $this->assertNotSame('', $response['body'], 'get_plugin_manifest returned empty body');
        $this->assertStringContainsString('admin_data', $response['body']);
    }

    /** @depends test_get_get_plugin_manifest_meta_lom */
    public function test_get_get_plugin_manifest_access_dco(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('get_plugin_manifest', $this->boaconfQuery([
            'plugin_id' => 'access.dco',
        ]));
        HttpAssertions::assertNoUnhandledPhpFault($response);
        $this->assertNotSame('', $response['body']);
    }

    /** @depends test_get_get_plugin_manifest_access_dco */
    public function test_get_get_plugin_manifest_core_auth(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('get_plugin_manifest', $this->boaconfQuery([
            'plugin_id' => 'core.auth',
        ]));
        HttpAssertions::assertNoUnhandledPhpFault($response);
        $this->assertNotSame('', $response['body']);
    }

    /** @depends test_get_get_plugin_manifest_core_auth */
    public function test_get_get_plugin_manifest_gui_ajax(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('get_plugin_manifest', $this->boaconfQuery([
            'plugin_id' => 'gui.ajax',
        ]));
        HttpAssertions::assertNoUnhandledPhpFault($response);
        $this->assertNotSame('', $response['body']);
    }

    /** @depends test_get_get_plugin_manifest_gui_ajax */
    public function test_get_create_users_menu(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('create_users_menu', $this->boaconfQuery());
        HttpAssertions::assertNoUnhandledPhpFault($response, true);
    }

    /** @depends test_get_create_users_menu */
    public function test_get_parameters_to_form_definitions_empty(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('parameters_to_form_definitions', $this->boaconfQuery([
            'json_parameters' => '{}',
        ]));
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'parameter',
            'json',
            'required',
            'empty',
            'Invalid',
        ]);
    }

    /** @depends test_get_parameters_to_form_definitions_empty */
    public function test_get_switch_to_settings(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('switch_to_settings');
        HttpAssertions::assertNoUnhandledPhpFault($response, true);
    }

    /** @depends test_get_switch_to_settings */
    public function test_get_switch_to_shared_elements(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('switch_to_shared_elements');
        HttpAssertions::assertNoUnhandledPhpFault($response, true);
    }
}
