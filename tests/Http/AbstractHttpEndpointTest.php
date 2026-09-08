<?php

declare(strict_types=1);

namespace BoA\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * Base for sequential boa-admin HTTP endpoint scenarios.
 *
 * Method naming convention (required):
 *   test_{get|post}_{action}_{freetextid}
 *
 * Examples:
 *   test_get_get_plugin_manifest_meta_lom
 *   test_post_mkdco_create_dco_object
 */
abstract class AbstractHttpEndpointTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        HttpSharedState::bootstrap();
        $client = HttpSharedState::client();
        if (!$client->isReachable()) {
            self::markTestSkipped(
                'boa-admin is not reachable at ' . $client->baseUrl() . ' (run `make up` first)'
            );
        }
    }

    protected function skipUnlessLoggedIn(): void
    {
        if (!HttpSharedState::isLoggedIn()) {
            $this->markTestSkipped('Prerequisite failed: login has not completed yet');
        }
    }

    protected function skipUnlessFolder(): void
    {
        try {
            HttpSharedState::requireFolder();
        } catch (HttpPrerequisiteException $e) {
            $this->markTestSkipped('Prerequisite failed: ' . $e->getMessage());
        }
    }

    protected function skipUnlessFile(): void
    {
        try {
            HttpSharedState::requireFile();
        } catch (HttpPrerequisiteException $e) {
            $this->markTestSkipped('Prerequisite failed: ' . $e->getMessage());
        }
    }

    protected function skipUnlessDco(): void
    {
        try {
            HttpSharedState::requireDco();
        } catch (HttpPrerequisiteException $e) {
            $this->markTestSkipped('Prerequisite failed: ' . $e->getMessage());
        }
    }

    protected function client(): BoaHttpClient
    {
        return HttpSharedState::client();
    }

    /**
     * GET index.php?get_action=...&secure_token=...
     *
     * @param array<string, scalar|null> $query
     * @return array{http_code:int,body:string,content_type:string}
     */
    protected function getAction(string $action, array $query = [], bool $withToken = true): array
    {
        $query['get_action'] = $action;
        return $this->client()->get($query, $withToken);
    }

    /**
     * Retry once after refreshing the secure token when the first response body is empty.
     *
     * @param array<string, scalar|null> $query
     * @return array{http_code:int,body:string,content_type:string}
     */
    protected function getActionWithRetry(string $action, array $query = [], bool $withToken = true): array
    {
        $response = $this->getAction($action, $query, $withToken);
        if ($response['body'] === '' && $withToken) {
            $this->client()->getSecureToken();
            $response = $this->getAction($action, $query, $withToken);
        }
        return $response;
    }

    /**
     * POST with action (or get_action) in the form body — matches browser forms.
     *
     * @param array<string, scalar|null> $form
     * @param array<string, scalar|null> $query
     * @return array{http_code:int,body:string,content_type:string}
     */
    protected function postAction(
        string $action,
        array $form = [],
        array $query = [],
        bool $withToken = true,
        string $actionField = 'action'
    ): array {
        $form[$actionField] = $action;
        return $this->client()->post($query, $form, $withToken);
    }

    /**
     * @param array<string, scalar|null> $form
     * @param array<string, scalar|null> $query
     * @return array{http_code:int,body:string,content_type:string}
     */
    protected function postActionWithRetry(
        string $action,
        array $form = [],
        array $query = [],
        bool $withToken = true,
        string $actionField = 'get_action'
    ): array {
        $response = $this->postAction($action, $form, $query, $withToken, $actionField);
        if ($response['body'] === '' && $withToken) {
            $this->client()->getSecureToken();
            $response = $this->postAction($action, $form, $query, $withToken, $actionField);
        }
        return $response;
    }

    /**
     * @param array<string, scalar|null> $extra
     * @return array<string, scalar|null>
     */
    protected function dcoRepoQuery(array $extra = []): array
    {
        return array_merge(['tmp_repository_id' => HttpSharedState::dcoRepoId()], $extra);
    }

    /**
     * @param array<string, scalar|null> $extra
     * @return array<string, scalar|null>
     */
    protected function boaconfQuery(array $extra = []): array
    {
        return array_merge(['tmp_repository_id' => 'boaconf'], $extra);
    }

    /**
     * Parse filename/basename from a nodes_diff ADD tree node when present.
     */
    protected function extractAddedNodeBasename(string $body): ?string
    {
        if (preg_match('/<tree[^>]*filename="([^"]+)"/', $body, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_XML1);
        }
        if (preg_match('/<tree[^>]*text="([^"]+)"/', $body, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_XML1);
        }
        return null;
    }

    /**
     * Find a DCO id path in an ls/mkdco response by title or id attribute.
     */
    protected function findDcoPathByTitle(string $body, string $title): ?string
    {
        if (preg_match('/has been created with id\s+([A-Fa-f0-9\-]+@[^\s<]+)/', $body, $m)) {
            return '/' . $m[1];
        }
        if (preg_match('/<tree\b[^>]*title="' . preg_quote($title, '/') . '"[^>]*\bid="([^"]+)"/', $body, $m)) {
            return '/' . $m[1];
        }
        if (preg_match('/<tree\b[^>]*\bid="([^"]+)"[^>]*title="' . preg_quote($title, '/') . '"/', $body, $m)) {
            return '/' . $m[1];
        }
        if (preg_match_all('/<tree\b[^>]*>/', $body, $nodes)) {
            foreach ($nodes[0] as $node) {
                if (!str_contains($node, $title)) {
                    continue;
                }
                if (preg_match('/\bid="([^"]+@[^"]+)"/', $node, $m)) {
                    return '/' . $m[1];
                }
                if (preg_match('/filename="([^"]+@[^"]+)"/', $node, $m)) {
                    return '/' . $m[1];
                }
            }
        }
        if (preg_match('/\bid="([^"]+@[^"]+)"/', $body, $m)) {
            return '/' . $m[1];
        }
        return null;
    }
}
