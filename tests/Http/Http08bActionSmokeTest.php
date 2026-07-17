<?php

declare(strict_types=1);

namespace BoA\Tests\Http;

/**
 * Smoke coverage for remaining registered actions that are safe to call with
 * minimal/no parameters after login. Each dataset name follows:
 *   get_{action}_{freetextid}
 *
 * These run before logout (phpunit.xml orders this file before SessionEnd).
 */
final class Http08bActionSmokeTest extends AbstractHttpEndpointTest
{
    /**
     * @return iterable<string, array{0:string,1:array<string,scalar|null>,2:list<string>}>
     */
    public static function smokeGetActionsProvider(): iterable
    {
        // name => [action, query extras, allowed error needles]
        $cases = [
            'get_back_client_nav' => ['back', [], []],
            'get_prepare_chunk_dl_missing' => ['prepare_chunk_dl', ['file' => '/missing'], ['file', 'required', 'Unable', 'not found', 'Invalid']],
            'get_download_chunk_missing' => ['download_chunk', ['file' => '/missing'], ['file', 'required', 'Unable', 'not found', 'Invalid']],
            'get_download_all_missing' => ['download_all', ['dir' => '/'], ['file', 'required', 'Unable', 'selection', 'empty']],
            'get_restore_without_recycle' => ['restore', ['file' => '/missing'], ['file', 'required', 'Unable', 'not found', 'recycle', 'Invalid']],
            'get_purge_without_selection' => ['purge', [], ['file', 'required', 'Unable', 'selection', 'empty', 'not allowed']],
            'get_chmod_without_selection' => ['chmod', ['chmod_value' => '755'], ['file', 'required', 'Unable', 'selection', 'empty', 'not allowed']],
            'get_move_without_selection' => ['move', ['dest' => '/'], ['file', 'required', 'Unable', 'selection', 'empty', 'not allowed']],
            'get_copyAsText_without_selection' => ['copyAsText', [], ['file', 'required', 'Unable', 'selection', 'empty', 'not allowed']],
            'get_copyUrl_without_selection' => ['copyUrl', [], ['file', 'required', 'Unable', 'selection', 'empty', 'not allowed']],
            'get_reset_counter_missing' => ['reset_counter', ['file' => '/missing'], ['file', 'required', 'Unable', 'not found', 'Invalid', 'share']],
            'get_reset_download_counter_missing' => ['reset_download_counter', ['file' => '/missing'], ['file', 'required', 'Unable', 'not found', 'Invalid', 'share']],
            'get_toggle_link_watch_missing' => ['toggle_link_watch', ['file' => '/missing'], ['file', 'required', 'Unable', 'not found', 'Invalid', 'share']],
            'get_delete_bookmark_missing' => ['delete_bookmark', ['bm_path' => '/missing'], ['bookmark', 'required', 'Unable', 'not found', 'Invalid']],
            'get_rename_bookmark_missing' => ['rename_bookmark', ['bm_path' => '/missing', 'bm_title' => 'x'], ['bookmark', 'required', 'Unable', 'not found', 'Invalid']],
            'get_user_delete_repository_missing' => ['user_delete_repository', ['repository_id' => 'missing-repo'], ['repository', 'required', 'Unable', 'not found', 'Invalid', 'not allowed']],
            'get_get_drop_bg' => ['get_drop_bg', [], []],
            'get_get_sess_id' => ['get_sess_id', [], []],
            'get_video_properties_missing' => ['video_properties', ['file' => '/missing.mp4'], ['file', 'required', 'Unable', 'not found', 'Invalid', 'video']],
            'get_read_video_data_missing' => ['read_video_data', ['file' => '/missing.mp4'], ['file', 'required', 'Unable', 'not found', 'Invalid', 'video']],
            'get_preview_data_proxy_missing' => ['preview_data_proxy', ['file' => '/missing'], ['file', 'required', 'Unable', 'not found', 'Invalid']],
            'get_slideshow_sel_missing' => ['slideshow_sel', [], ['selection', 'required', 'Unable', 'empty', 'not allowed']],
            'get_edit_user_meta_missing' => ['edit_user_meta', ['file' => '/missing'], ['file', 'required', 'Unable', 'not found', 'Invalid', 'selection']],
            'get_meta_source_edit_missing' => ['meta_source_edit', [], ['required', 'Unable', 'Invalid', 'plugin', 'empty']],
            'get_meta_source_add_missing' => ['meta_source_add', [], ['required', 'Unable', 'Invalid', 'plugin', 'empty']],
            'get_meta_source_delete_missing' => ['meta_source_delete', [], ['required', 'Unable', 'Invalid', 'plugin', 'empty']],
            'get_edit_repository_label_missing' => ['edit_repository_label', [], ['required', 'Unable', 'Invalid', 'repository', 'empty']],
            'get_user_update_right_missing' => ['user_update_right', [], ['required', 'Unable', 'Invalid', 'user', 'empty']],
            'get_user_update_role_missing' => ['user_update_role', [], ['required', 'Unable', 'Invalid', 'user', 'role', 'empty']],
            'get_user_update_group_missing' => ['user_update_group', [], ['required', 'Unable', 'Invalid', 'user', 'group', 'empty']],
            'get_save_user_preference_missing' => ['save_user_preference', [], ['required', 'Unable', 'Invalid', 'empty']],
            'get_edit_boaconf_missing' => ['edit', ['file' => '/plugins/meta.lom'], ['required', 'Unable', 'Invalid', 'not found', 'empty', 'file']],
            'get_create_fs_entry_menu' => ['create', ['dir' => '/'], ['required', 'Unable', 'Invalid', 'empty', 'not allowed']],
            'get_installer_skipped_if_installed' => ['installer', [], ['installed', 'Unable', 'not allowed', 'already', 'Invalid']],
            'get_load_installer_form' => ['load_installer_form', [], ['installed', 'Unable', 'not allowed', 'already', 'Invalid']],
        ];

        foreach ($cases as $name => [$action, $query, $allowed]) {
            yield $name => [$action, $query, $allowed];
        }
    }

    /**
     * @dataProvider smokeGetActionsProvider
     * @param array<string, scalar|null> $query
     * @param list<string> $allowedErrorSubstrings
     */
    public function test_get_action_smoke(string $action, array $query, array $allowedErrorSubstrings): void
    {
        $this->skipUnlessLoggedIn();

        // Prefer DCO repo for filesystem-ish actions; boaconf for admin-ish ones.
        $adminActions = [
            'meta_source_edit', 'meta_source_add', 'meta_source_delete',
            'edit_repository_label', 'user_update_right', 'user_update_role',
            'user_update_group', 'save_user_preference', 'edit', 'create_user',
            'list_all_users',
        ];
        if (in_array($action, $adminActions, true)) {
            $query = array_merge($this->boaconfQuery(), $query);
        } elseif (!isset($query['tmp_repository_id'])) {
            $query = array_merge($this->dcoRepoQuery(), $query);
        }

        $response = $this->getAction($action, $query);
        if ($allowedErrorSubstrings === []) {
            HttpAssertions::assertNoUnhandledPhpFault($response, true);
            return;
        }
        HttpAssertions::assertSuccessOrExpectedAppError($response, $allowedErrorSubstrings);
    }
}
