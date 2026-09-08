<?php

declare(strict_types=1);

namespace BoA\Tests\Http;

/**
 * Final session teardown — logout last so earlier classes keep a live session.
 */
final class Http09SessionEndTest extends AbstractHttpEndpointTest
{
    public function test_get_delete_workspace_note_cleanup(): void
    {
        $this->skipUnlessLoggedIn();
        if (HttpSharedState::filePath() === '' || HttpSharedState::dcoPath() === '') {
            $this->markTestSkipped('No file fixture to delete');
        }
        $response = $this->getAction('delete', $this->dcoRepoQuery([
            'file' => HttpSharedState::filePath(),
        ]));
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'not found',
            'does not exist',
            'No such',
            'Unable',
            'required',
        ]);
    }

    /** @depends test_get_delete_workspace_note_cleanup */
    public function test_post_logout_admin(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->postAction('logout', [], [], true, 'get_action');
        HttpAssertions::assertNoUnhandledPhpFault($response);
        HttpSharedState::markLoggedIn(false);
    }

    /** @depends test_post_logout_admin */
    public function test_get_ls_after_logout_requires_auth(): void
    {
        // Fresh anonymous client — shared jar may still hold cookies depending on logout implementation.
        $anon = new BoaHttpClient($this->client()->baseUrl());
        $anon->getSecureToken();
        $response = $anon->get([
            'get_action' => 'ls',
            'options' => 'al',
            'dir' => '/',
            'tmp_repository_id' => HttpSharedState::dcoRepoId(),
        ]);
        HttpAssertions::assertNoUnhandledPhpFault($response);
        $this->assertTrue(
            str_contains($response['body'], 'require_auth')
            || str_contains($response['body'], 'You are not allowed')
            || str_contains($response['body'], 'type="ERROR"')
            || str_contains($response['body'], 'logging_result')
            || $response['body'] === '',
            'Anonymous ls after logout should not return a normal authenticated listing'
        );
        if ($response['body'] !== '' && str_contains($response['body'], '<tree') && !str_contains($response['body'], 'ERROR') && !str_contains($response['body'], 'require_auth')) {
            // If we somehow still got a tree, ensure it is not a full files listing with client_configs.
            $this->assertFalse(
                str_contains($response['body'], 'client_configs'),
                'Anonymous ls unexpectedly returned a full workspace listing'
            );
        }
    }
}
