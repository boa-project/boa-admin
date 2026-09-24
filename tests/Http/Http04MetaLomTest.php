<?php

declare(strict_types=1);

namespace BoA\Tests\Http;

/**
 * meta.lom plugin endpoints (specs list / by id / edit form hooks).
 */
final class Http04MetaLomTest extends AbstractHttpEndpointTest
{
    public function test_get_get_specs_list_meta_lom(): void
    {
        $this->skipUnlessLoggedIn();
        HttpSharedState::ensureMinimalLomSpec();
        $response = $this->getActionWithRetry('get_specs_list', $this->dcoRepoQuery());
        HttpAssertions::assertNoUnhandledPhpFault($response);
        // Specs list may be JSON, XML, or a choices payload — only reject faults / hard empties after retry.
        $this->assertTrue(
            $response['body'] !== '' || $response['http_code'] === 200,
            'get_specs_list returned an unexpected empty failure'
        );
    }

    /** @depends test_get_get_specs_list_meta_lom */
    public function test_get_get_spec_by_id_lom_rd(): void
    {
        $this->skipUnlessLoggedIn();
        HttpSharedState::ensureMinimalLomSpec();
        $response = $this->getAction('get_spec_by_id', $this->dcoRepoQuery([
            'spec_id' => 'lom-rd',
        ]));
        HttpAssertions::assertNoUnhandledPhpFault($response);
        $this->assertTrue(
            str_contains($response['body'], 'lom-rd')
            || str_contains($response['body'], '<spec')
            || str_contains($response['body'], 'LOM'),
            'Expected lom-rd spec payload: ' . substr($response['body'], 0, 300)
        );
    }

    /** @depends test_get_get_spec_by_id_lom_rd */
    public function test_get_get_spec_by_id_digital_resource_object(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('get_spec_by_id', $this->dcoRepoQuery([
            'spec_id' => 'DIGITAL_RESOURCE_OBJECT',
        ]));
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'not found',
            'Unable',
            'unknown',
            'spec',
            'missing',
        ]);
    }

    /** @depends test_get_get_spec_by_id_digital_resource_object */
    public function test_get_get_meta_specs_dco_repo(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->getAction('get_meta_specs', $this->dcoRepoQuery());
        HttpAssertions::assertNoUnhandledPhpFault($response);
    }

    /** @depends test_get_get_meta_specs_dco_repo */
    public function test_post_edit_lom_meta_without_selection_expected_error(): void
    {
        $this->skipUnlessLoggedIn();
        $response = $this->postAction('edit_lom_meta', [], $this->dcoRepoQuery());
        HttpAssertions::assertSuccessOrExpectedAppError($response, [
            'selection',
            'file',
            'required',
            'not allowed',
            'empty',
            'You are not allowed',
        ]);
    }
}
