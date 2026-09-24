<?php

declare(strict_types=1);

namespace BoA\Tests\Http;

/**
 * Share / compress / admin create* endpoints exercised safely (often expected app errors).
 */
final class Http08ShareMiscTest extends AbstractHttpEndpointTest
{
    public function test_get_share_without_file_expected_error(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('share', $this->dcoRepoQuery());
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'selection',
            'file',
            'required',
            'not allowed',
            'Unable',
            'empty',
        ]);
    }

    /** @depends test_get_share_without_file_expected_error */
    public function test_get_load_shared_element_data_missing(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('load_shared_element_data', [
            'file' => '/does-not-exist-' . HttpSharedState::runId(),
        ]);
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'not found',
            'Unable',
            'required',
            'Invalid',
            'empty',
            'does not exist',
            'No such',
        ]);
    }

    /** @depends test_get_load_shared_element_data_missing */
    public function test_get_unshare_missing_expected(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('unshare', $this->dcoRepoQuery([
            'file' => '/does-not-exist-' . HttpSharedState::runId(),
        ]));
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'not found',
            'Unable',
            'required',
            'Invalid',
            'empty',
            'does not exist',
        ]);
    }

    /** @depends test_get_unshare_missing_expected */
    public function test_get_compress_without_selection_expected(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('compress', $this->dcoRepoQuery([
            'dir' => HttpSharedState::folderPath() ?: '/',
        ]));
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'selection',
            'file',
            'required',
            'not allowed',
            'Unable',
            'empty',
            'zip',
        ]);
    }

    /** @depends test_get_compress_without_selection_expected */
    public function test_get_empty_recycle_safe(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('empty_recycle', $this->dcoRepoQuery());
        HttpAssertions::assertNoUnhandledPhpFault($response);
    }

    /** @depends test_get_empty_recycle_safe */
    public function test_get_clear_expired_shares(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('clear_expired');
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'not allowed',
            'Unable',
            'required',
            'repository',
            'shared',
        ]);
    }

    /** @depends test_get_clear_expired_shares */
    public function test_post_create_user_missing_fields_expected(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->postAction('create_user', [], $this->boaconfQuery());
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'required',
            'Unable',
            'empty',
            'Invalid',
            'user',
            'password',
        ]);
    }

    /** @depends test_post_create_user_missing_fields_expected */
    public function test_post_create_role_missing_fields_expected(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->postAction('create_role', [], $this->boaconfQuery());
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'required',
            'Unable',
            'empty',
            'Invalid',
            'role',
        ]);
    }

    /** @depends test_post_create_role_missing_fields_expected */
    public function test_post_create_group_missing_fields_expected(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->postAction('create_group', [], $this->boaconfQuery());
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'required',
            'Unable',
            'empty',
            'Invalid',
            'group',
        ]);
    }

    /** @depends test_post_create_group_missing_fields_expected */
    public function test_post_create_repository_missing_fields_expected(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->postAction('create_repository', [], $this->boaconfQuery());
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'required',
            'Unable',
            'empty',
            'Invalid',
            'repository',
            'driver',
        ]);
    }

    /** @depends test_post_create_repository_missing_fields_expected */
    public function test_get_run_plugin_action_missing_expected(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('run_plugin_action', $this->boaconfQuery([
            'plugin_id' => 'meta.lom',
            'method' => 'doesNotExist',
        ]));
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'required',
            'Unable',
            'not found',
            'Invalid',
            'method',
            'Call to',
        ]);
        // Note: "Call to" would normally look like a PHP fault; assertSuccessOrExpectedAppError
        // already ran assertNoUnhandledPhpFault — if the app surfaces a typed ApplicationException
        // without those PHP patterns, we're good. If it leaks a raw TypeError string, the fault
        // assertion fails intentionally so we can harden the call site.
    }

    /** @depends test_get_run_plugin_action_missing_expected */
    public function test_get_ext_select_client_action(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('ext_select');
        HttpAssertions::assertNoUnhandledPhpFault($response, true);
    }

    /** @depends test_get_ext_select_client_action */
    public function test_get_open_with_without_file_expected(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('open_with', $this->dcoRepoQuery());
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'selection',
            'file',
            'required',
            'not allowed',
            'Unable',
            'empty',
        ]);
    }

    /** @depends test_get_open_with_without_file_expected */
    public function test_get_link_without_file_expected(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('link', $this->dcoRepoQuery());
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'selection',
            'file',
            'required',
            'not allowed',
            'Unable',
            'empty',
        ]);
    }

    /** @depends test_get_link_without_file_expected */
    public function test_post_convert_to_digital_resource_missing_expected(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->postAction('convert_to_digital_resource', [], $this->dcoRepoQuery());
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'required',
            'Unable',
            'Invalid',
            'file',
            'selection',
            'empty',
            'spec',
        ]);
    }

    /** @depends test_post_convert_to_digital_resource_missing_expected */
    public function test_post_convert_to_dro_missing_expected(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->postAction('convert_to_dro', [], $this->dcoRepoQuery());
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'required',
            'Unable',
            'Invalid',
            'file',
            'selection',
            'empty',
            'spec',
        ]);
    }
}
