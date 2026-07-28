<?php

declare(strict_types=1);

namespace BoA\Tests\Http;

/**
 * Digital Content Object lifecycle + nested fs ops under the DCO src/ tree.
 */
final class Http06DcoObjectTest extends AbstractHttpEndpointTest
{
    public function test_post_mkdco_create_dco_object(): void
    {
        $this->skipUnlessLoggedIn();
        HttpSharedState::ensureMinimalLomSpec();

        $form = [
            'plugin_id' => 'access.dco',
            'dir' => '/',
            'file' => '',
            'DCO_dcoid' => '',
            'DCO_dcoid_apptype' => 'hidden',
            'DCO_author' => '',
            'DCO_author_apptype' => 'hidden',
            'DCO_version' => '',
            'DCO_version_apptype' => 'hidden',
            'DCO_dcotitle' => HttpSharedState::dcoTitle(),
            'DCO_dcotitle_apptype' => 'string',
            'DCO_customicon' => '',
            'DCO_customicon_apptype' => 'binary',
            'DCO_customicon_original_binary' => '',
            'DCO_customicon_original_binary_apptype' => 'string',
            'DCO_status' => 'inprogress',
            'DCO_dcotype' => 'lom-rd',
            'DCO_dcocontype_apptype' => 'text/json',
            'DCO_dcocontype' => '{"local_dummy":"","group_switch_value":"local"}',
            '_method' => 'put',
        ];

        $response = $this->postActionWithRetry('mkdco', $form, $this->dcoRepoQuery());
        HttpAssertions::assertNoUnhandledPhpFault($response);
        $this->assertNotSame(
            '',
            $response['body'],
            'mkdco returned empty body (http=' . $response['http_code'] . ', ct=' . $response['content_type'] . ')'
        );
        HttpAssertions::assertXmlRoot($response['body'], 'tree');
        $this->assertNull(
            HttpAssertions::extractErrorMessage($response['body']),
            'mkdco failed: ' . (HttpAssertions::extractErrorMessage($response['body']) ?? '')
        );

        $path = $this->findDcoPathByTitle($response['body'], HttpSharedState::dcoTitle());
        if ($path === null) {
            $ls = $this->getActionWithRetry('ls', $this->dcoRepoQuery(['options' => 'al', 'dir' => '/']));
            HttpAssertions::assertNoUnhandledPhpFault($ls);
            $path = $this->findDcoPathByTitle($ls['body'], HttpSharedState::dcoTitle());
        }
        $this->assertNotNull($path, 'Could not resolve created DCO path from mkdco/ls response');
        HttpSharedState::setDcoPath($path);
    }

    /** @depends test_post_mkdco_create_dco_object */
    public function test_get_ls_dco_shows_created_object(): void
    {
        $this->skipUnlessDco();
        $response = $this->getActionWithRetry('ls', $this->dcoRepoQuery([
            'options' => 'al',
            'dir' => '/',
        ]));
        HttpAssertions::assertNoUnhandledPhpFault($response);
        $this->assertTrue(
            str_contains($response['body'], HttpSharedState::dcoId())
            || str_contains($response['body'], HttpSharedState::dcoTitle()),
            'ls should list the created DCO'
        );
    }

    /** @depends test_get_ls_dco_shows_created_object */
    public function test_get_ls_dco_object_children(): void
    {
        $this->skipUnlessDco();
        $response = $this->getActionWithRetry('ls', $this->dcoRepoQuery([
            'options' => 'al',
            'dir' => HttpSharedState::dcoPath(),
        ]));
        HttpAssertions::assertNoUnhandledPhpFault($response);
        HttpAssertions::assertXmlRoot($response['body'], 'tree');
        $this->assertTrue(
            str_contains($response['body'], 'src') || str_contains($response['body'], 'content'),
            'DCO children should include src/content'
        );
    }

    /** @depends test_get_ls_dco_object_children */
    public function test_get_mkdir_inside_dco_src(): void
    {
        $this->skipUnlessDco();
        $parent = HttpSharedState::dcoPath() . '/src';
        $response = $this->getActionWithRetry('mkdir', $this->dcoRepoQuery([
            'dir' => $parent,
            'dirname' => HttpSharedState::folderName(),
        ]));
        HttpAssertions::assertNoUnhandledPhpFault($response);
        HttpAssertions::assertXmlRoot($response['body'], 'tree');
        $this->assertNull(
            HttpAssertions::extractErrorMessage($response['body']),
            'mkdir inside DCO src failed: ' . (HttpAssertions::extractErrorMessage($response['body']) ?? '')
        );
    }

    /** @depends test_get_mkdir_inside_dco_src */
    public function test_get_ls_dco_src_shows_workspace_folder(): void
    {
        $this->skipUnlessFolder();
        $response = $this->getActionWithRetry('ls', $this->dcoRepoQuery([
            'options' => 'al',
            'dir' => HttpSharedState::dcoPath() . '/src',
        ]));
        HttpAssertions::assertNoUnhandledPhpFault($response);
        $this->assertStringContainsString(
            HttpSharedState::folderName(),
            $response['body'],
            'ls of DCO/src should include the workspace folder'
        );
    }

    /** @depends test_get_ls_dco_src_shows_workspace_folder */
    public function test_get_mkfile_text_note_inside_folder(): void
    {
        $this->skipUnlessFolder();
        $response = $this->getActionWithRetry('mkfile', $this->dcoRepoQuery([
            'dir' => HttpSharedState::folderPath(),
            'filename' => HttpSharedState::fileName(),
        ]));
        HttpAssertions::assertNoUnhandledPhpFault($response);
        HttpAssertions::assertSuccessNoError($response);
    }

    /** @depends test_get_mkfile_text_note_inside_folder */
    public function test_post_put_content_write_note(): void
    {
        $this->skipUnlessFile();
        $response = $this->postActionWithRetry(
            'put_content',
            [
                'file' => HttpSharedState::filePath(),
                'content' => "hello from http suite\nrun=" . HttpSharedState::runId(),
            ],
            $this->dcoRepoQuery()
        );
        HttpAssertions::assertNoUnhandledPhpFault($response);
        HttpAssertions::assertSuccessOrExpectedAppError($response, ['readonly', 'not allowed']);
    }

    /** @depends test_post_put_content_write_note */
    public function test_get_get_content_written_note(): void
    {
        $this->skipUnlessFile();
        $response = $this->getActionWithRetry('get_content', $this->dcoRepoQuery([
            'file' => HttpSharedState::filePath(),
        ]));
        HttpAssertions::assertNoUnhandledPhpFault($response);
        if ($response['body'] !== '' && !str_contains($response['body'], 'type="ERROR"')) {
            $this->assertStringContainsString('hello from http suite', $response['body']);
        }
    }

    /** @depends test_get_get_content_written_note */
    public function test_get_rename_note_file(): void
    {
        $this->skipUnlessFile();
        $response = $this->getActionWithRetry('rename', $this->dcoRepoQuery([
            'file' => HttpSharedState::filePath(),
            'filename_new' => basename(HttpSharedState::filePathRenamed()),
        ]));
        HttpAssertions::assertNoUnhandledPhpFault($response);
        if (HttpAssertions::extractErrorMessage($response['body']) === null) {
            HttpSharedState::setFilePath(HttpSharedState::filePathRenamed());
        }
    }

    /** @depends test_get_rename_note_file */
    public function test_get_copy_note_file(): void
    {
        $this->skipUnlessFile();
        $response = $this->getActionWithRetry('copy', $this->dcoRepoQuery([
            'file' => HttpSharedState::filePath(),
            'dest' => HttpSharedState::folderPath(),
            'dest_node' => basename(HttpSharedState::filePathCopy()),
        ]));
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'dest', 'exists', 'not allowed', 'required', 'Invalid',
        ]);
    }

    /** @depends test_get_copy_note_file */
    public function test_get_apply_check_hook_noop(): void
    {
        $this->skipUnlessFile();
        $response = $this->getAction('apply_check_hook', $this->dcoRepoQuery([
            'file' => HttpSharedState::filePath(),
            'hook_name' => 'before_change',
            'hook_arg' => '',
        ]));
        HttpAssertions::assertNoUnhandledPhpFault($response);
    }

    /** @depends test_get_apply_check_hook_noop */
    public function test_get_stat_dco_object(): void
    {
        $this->skipUnlessDco();
        $response = $this->getAction('stat', $this->dcoRepoQuery([
            'file' => HttpSharedState::dcoPath(),
        ]));
        HttpAssertions::assertNoUnhandledPhpFault($response);
    }

    /** @depends test_get_stat_dco_object */
    public function test_post_editdco_reload_manifest(): void
    {
        $this->skipUnlessDco();
        $response = $this->postActionWithRetry(
            'editdco',
            [
                'plugin_id' => 'access.dco',
                'dir' => '/',
                'file' => HttpSharedState::dcoPath(),
                'DCO_dcoid' => HttpSharedState::dcoId(),
                'DCO_dcoid_apptype' => 'hidden',
                'DCO_author' => '',
                'DCO_author_apptype' => 'hidden',
                'DCO_version' => '1.0',
                'DCO_version_apptype' => 'hidden',
                'DCO_dcotitle' => HttpSharedState::dcoTitle() . ' edited',
                'DCO_dcotitle_apptype' => 'string',
                'DCO_customicon' => '',
                'DCO_customicon_apptype' => 'binary',
                'DCO_customicon_original_binary' => '',
                'DCO_customicon_original_binary_apptype' => 'string',
                'DCO_status' => 'inprogress',
                'DCO_dcotype' => 'lom-rd',
                'DCO_dcocontype_apptype' => 'text/json',
                'DCO_dcocontype' => '{"local_dummy":"","group_switch_value":"local"}',
                '_method' => 'put',
            ],
            $this->dcoRepoQuery()
        );
        HttpAssertions::assertNoUnhandledPhpFault($response);
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'not found', 'Unable', 'required',
        ]);
    }

    /** @depends test_post_editdco_reload_manifest */
    public function test_get_dcometa_open_editor_hook(): void
    {
        $this->skipUnlessDco();
        $response = $this->getAction('dcometa', $this->dcoRepoQuery([
            'file' => HttpSharedState::dcoPath(),
        ]));
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'selection', 'not allowed', 'required', 'Unable',
        ]);
    }

    /** @depends test_get_dcometa_open_editor_hook */
    public function test_get_get_custom_dco_icon_missing_expected(): void
    {
        $this->skipUnlessDco();
        $response = $this->getAction('get_custom_dco_icon', $this->dcoRepoQuery([
            'file' => HttpSharedState::dcoPath(),
            'binary_id' => 'missing-icon',
        ]));
        HttpAssertions::assertNoUnhandledPhpFault($response);
    }

    /** @depends test_get_get_custom_dco_icon_missing_expected */
    public function test_post_save_dcometa_minimal_payload(): void
    {
        $this->skipUnlessDco();
        $response = $this->postActionWithRetry(
            'save_dcometa',
            [
                'file' => HttpSharedState::dcoPath() . '/.manifest',
                'metadata' => '{}',
                'spec_id' => 'lom-rd',
                'mode' => 'single',
            ],
            $this->dcoRepoQuery()
        );
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'required', 'Unable', 'Invalid', 'not found', 'metadata', 'spec', 'json',
        ]);
    }

    /**
     * Mirrors the browser form for lom-rd metadata save, including *_replication keys
     * that previously triggered Undefined array key in Utils::parseStandardFormParameters.
     *
     * @depends test_post_save_dcometa_minimal_payload
     */
    public function test_post_save_dcometa_lom_rd_with_replication_fields(): void
    {
        $this->skipUnlessDco();
        HttpSharedState::ensureMinimalLomSpec();

        $response = $this->postActionWithRetry(
            'save_dcometa',
            [
                'plugin_id' => 'meta.lom',
                'dir' => '/',
                'spec_id' => 'lom-rd',
                'mode' => 'single',
                'file' => HttpSharedState::dcoPath(),
                '_method' => 'put',
                'DCO__translateto_lang' => 'none',
                'DCO_meta.fields.general.title' => '{"none":"Test Object DCO"}',
                'DCO_meta.fields.general.title_apptype' => 'string',
                'DCO_meta.fields.general.description' => '{"none":""}',
                'DCO_meta.fields.general.description_apptype' => 'textarea',
                'DCO_meta.fields.general.keywords' => '{"none":""}',
                'DCO_meta.fields.general.keywords_apptype' => 'keywords',
                'DCO_meta.fields.general.language' => ['es'],
                'DCO_meta.fields.technical.location' => '',
                'DCO_meta.fields.technical.location_apptype' => 'string',
                'DCO_meta.fields.technical.format' => '',
                'DCO_meta.fields.rights.description' => '{"none":""}',
                'DCO_meta.fields.rights.description_apptype' => 'textarea',
                'DCO_meta.fields.rights.cost' => 'no',
                'DCO_meta.fields.rights.copyright' => 'cc by-nc-sa 4.0',
                'DCO_meta.fields.lifecycle.contribution.rol' => '',
                'DCO_meta.fields.lifecycle.contribution.rol_replication' => 'replicable_meta.fields.lifecycle.contribution',
                'DCO_meta.fields.clasification.taxon_path.source' => 'general',
                'DCO_meta.fields.clasification.taxon_path.source_apptype' => 'string',
                'DCO_meta.fields.clasification.taxon_path.source_replication' => 'replicable_meta.fields.clasification.taxon_path',
                'DCO_meta.fields.clasification.taxon_path.id_replication' => 'replicable_meta.fields.clasification.taxon_path',
            ],
            $this->dcoRepoQuery()
        );
        HttpAssertions::assertNoUnhandledPhpFault($response);
        $this->assertNotSame('', $response['body'], 'save_dcometa returned empty body');
        $this->assertNull(
            HttpAssertions::extractErrorMessage($response['body']),
            'save_dcometa failed: ' . (HttpAssertions::extractErrorMessage($response['body']) ?? substr($response['body'], 0, 400))
        );
        // Success is JSON metadata/manifest (or a tree without ERROR).
        $this->assertTrue(
            str_contains($response['body'], 'metadata')
            || str_contains($response['body'], 'manifest')
            || str_contains($response['body'], '{'),
            'Expected JSON metadata payload, got: ' . substr($response['body'], 0, 300)
        );
    }

    /** @depends test_post_save_dcometa_lom_rd_with_replication_fields */
    public function test_post_publish_metadata_without_ready_state_expected(): void
    {
        $this->skipUnlessDco();
        $response = $this->postActionWithRetry(
            'publish_metadata',
            ['file' => HttpSharedState::dcoPath()],
            $this->dcoRepoQuery()
        );
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'required', 'Unable', 'Invalid', 'not found', 'status', 'publish', 'metadata',
        ]);
    }

    /** @depends test_post_publish_metadata_without_ready_state_expected */
    public function test_get_download_dco_folder_prepare(): void
    {
        $this->skipUnlessDco();
        $response = $this->getAction('download', $this->dcoRepoQuery([
            'file' => HttpSharedState::dcoPath(),
        ]));
        HttpAssertions::assertNoUnhandledPhpFault($response);
    }

    /** @depends test_get_download_dco_folder_prepare */
    public function test_get_set_entry_point_dco(): void
    {
        $this->skipUnlessDco();
        $response = $this->getAction('set_entry_point', $this->dcoRepoQuery([
            'file' => HttpSharedState::dcoPath(),
        ]));
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'required', 'Unable', 'not allowed', 'entry', 'Invalid',
        ]);
    }

    /** @depends test_get_set_entry_point_dco */
    public function test_get_delete_note_copy_if_present(): void
    {
        $this->skipUnlessFolder();
        $response = $this->getAction('delete', $this->dcoRepoQuery([
            'file' => HttpSharedState::filePathCopy(),
        ]));
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'not found', 'does not exist', 'No such', 'Unable', 'required',
        ]);
    }
}
