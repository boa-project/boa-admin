<?php

declare(strict_types=1);

namespace BoA\Tests\Http;

/**
 * User preference / bookmark / language / template endpoints.
 */
final class Http07UserPrefsTest extends AbstractHttpEndpointTest
{
    public function test_get_get_bookmarks(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('get_bookmarks');
        HttpAssertions::assertNoUnhandledPhpFault($response, true);
    }

    /** @depends test_get_get_bookmarks */
    public function test_get_bookmark_current_folder(): void
    {
        $this->skipUnlessFolder();
        $response = $this->getAction('bookmark', $this->dcoRepoQuery([
            'bm_path' => HttpSharedState::folderPath(),
            'bm_title' => 'Http WS ' . HttpSharedState::runId(),
        ]));
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'required',
            'Unable',
            'not allowed',
            'bookmark',
            'exists',
        ]);
    }

    /** @depends test_get_bookmark_current_folder */
    public function test_get_user_list_authorized_users(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('user_list_authorized_users', [
            'value' => 'a',
        ]);
        HttpAssertions::assertNoUnhandledPhpFault($response, true);
    }

    /** @depends test_get_user_list_authorized_users */
    public function test_get_get_user_templates_definition(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('get_user_templates_definition');
        HttpAssertions::assertNoUnhandledPhpFault($response, true);
    }

    /** @depends test_get_get_user_templates_definition */
    public function test_get_get_user_template_logo_missing(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('get_user_template_logo', [
            'template_id' => '0',
            'icon_format' => 'small',
        ]);
        HttpAssertions::assertNoUnhandledPhpFault($response, true);
    }

    /** @depends test_get_get_user_template_logo_missing */
    public function test_post_save_user_pref_pending_folder(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->postAction(
            'save_user_pref',
            [
                'pref_name_0' => 'pending_folder',
                'pref_value_0' => HttpSharedState::folderPath() ?: '/',
            ],
            $this->dcoRepoQuery()
        );
        HttpAssertions::assertNoUnhandledPhpFault($response);
    }

    /** @depends test_post_save_user_pref_pending_folder */
    public function test_get_switch_language_en(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('switch_language', ['lang' => 'en']);
        HttpAssertions::assertNoUnhandledPhpFault($response, true);
    }

    /** @depends test_get_switch_language_en */
    public function test_get_webdav_preferences(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('webdav_preferences');
        HttpAssertions::assertNoUnhandledPhpFault($response, true);
    }

    /** @depends test_get_webdav_preferences */
    public function test_post_custom_data_edit_noop(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->postAction('custom_data_edit', [], []);
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'required',
            'Unable',
            'not allowed',
            'Invalid',
            'empty',
        ]);
    }

    /** @depends test_post_custom_data_edit_noop */
    public function test_get_get_binary_param_missing(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('get_binary_param', [
            'binary_id' => 'missing-binary-' . HttpSharedState::runId(),
        ], false);
        HttpAssertions::assertNoUnhandledPhpFault($response, true);
    }

    /** @depends test_get_get_binary_param_missing */
    public function test_get_get_global_binary_param_missing(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('get_global_binary_param', [
            'binary_id' => 'missing-global-binary-' . HttpSharedState::runId(),
        ], false);
        HttpAssertions::assertNoUnhandledPhpFault($response, true);
    }
}
