<?php

declare(strict_types=1);

namespace BoA\Tests\Http;

/**
 * DCO workspace browse/stat endpoints (root lists DCO objects only).
 */
final class Http05DcoFsTest extends AbstractHttpEndpointTest
{
    public function test_get_switch_repository_dco(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('switch_repository', [
            'repository_id' => HttpSharedState::dcoRepoId(),
        ]);
        HttpAssertions::assertNoUnhandledPhpFault($response);
    }

    /** @depends test_get_switch_repository_dco */
    public function test_get_ls_dco_root(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getActionWithRetry('ls', $this->dcoRepoQuery([
            'options' => 'al',
            'dir' => '/',
        ]));
        HttpAssertions::assertNoUnhandledPhpFault($response);
        $this->assertNotSame('', $response['body'], 'ls dco root returned empty body');
        HttpAssertions::assertXmlRoot($response['body'], 'tree');
    }

    /** @depends test_get_ls_dco_root */
    public function test_get_stat_dco_root(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('stat', $this->dcoRepoQuery(['file' => '/']));
        HttpAssertions::assertNoUnhandledPhpFault($response);
    }

    /** @depends test_get_stat_dco_root */
    public function test_get_lsync_dco_root(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('lsync', $this->dcoRepoQuery([
            'dir' => '/',
        ]));
        HttpAssertions::assertNoUnhandledPhpFault($response);
    }

    /** @depends test_get_lsync_dco_root */
    public function test_get_up_dir_client_action(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('up_dir');
        HttpAssertions::assertNoUnhandledPhpFault($response);
    }
}
