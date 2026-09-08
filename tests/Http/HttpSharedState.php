<?php

declare(strict_types=1);

namespace BoA\Tests\Http;

/**
 * Cross-class fixture bag for the sequential HTTP endpoint suite.
 *
 * Tests populate state as they advance (login → folder → file → DCO) so later
 * scenarios can depend on earlier ones without a single mega-class.
 */
final class HttpSharedState
{
    private static ?BoaHttpClient $client = null;

    private static string $runId = '';

    private static bool $loggedIn = false;

    private static ?string $dcoRepoId = null;

    private static string $folderName = '';

    private static string $folderPath = '';

    private static string $fileName = '';

    private static string $filePath = '';

    private static string $filePathRenamed = '';

    private static string $filePathCopy = '';

    private static string $dcoTitle = '';

    private static string $dcoPath = '';

    private static string $dcoId = '';

    public static function bootstrap(): void
    {
        if (self::$client !== null) {
            return;
        }

        $baseUrl = getenv('BOA_ADMIN_URL') ?: 'http://127.0.0.1';
        self::$client = new BoaHttpClient($baseUrl);
        self::$runId = date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
        self::$folderName = 'http_ws_' . self::$runId;
        self::$folderPath = '/' . self::$folderName;
        self::$fileName = 'note_' . self::$runId . '.txt';
        self::$filePath = self::$folderPath . '/' . self::$fileName;
        self::$filePathRenamed = self::$folderPath . '/note_renamed_' . self::$runId . '.txt';
        self::$filePathCopy = self::$folderPath . '/note_copy_' . self::$runId . '.txt';
        self::$dcoTitle = 'Http DCO ' . self::$runId;
    }

    public static function client(): BoaHttpClient
    {
        self::bootstrap();
        if (self::$client === null) {
            throw new \RuntimeException('HTTP client not initialized');
        }
        return self::$client;
    }

    public static function runId(): string
    {
        self::bootstrap();
        return self::$runId;
    }

    public static function markLoggedIn(bool $value = true): void
    {
        self::$loggedIn = $value;
    }

    public static function isLoggedIn(): bool
    {
        return self::$loggedIn;
    }

    public static function requireLoggedIn(): void
    {
        if (!self::$loggedIn) {
            throw new HttpPrerequisiteException('login has not completed yet');
        }
    }

    public static function requireFolder(): void
    {
        self::requireDco();
        if (self::$folderPath === '' || !str_starts_with(self::$folderPath, self::$dcoPath . '/')) {
            throw new HttpPrerequisiteException('workspace folder not created yet');
        }
    }

    public static function requireFile(): void
    {
        self::requireFolder();
        if (self::$filePath === '') {
            throw new HttpPrerequisiteException('test file not created yet');
        }
    }

    public static function requireDco(): void
    {
        self::requireLoggedIn();
        if (self::$dcoPath === '') {
            throw new HttpPrerequisiteException('DCO object not created yet');
        }
    }

    public static function setDcoRepoId(string $id): void
    {
        self::$dcoRepoId = $id;
    }

    public static function dcoRepoId(): string
    {
        if (self::$dcoRepoId !== null && self::$dcoRepoId !== '') {
            return self::$dcoRepoId;
        }

        $fromEnv = getenv('BOA_TEST_DCO_REPO_ID');
        if (is_string($fromEnv) && $fromEnv !== '') {
            self::$dcoRepoId = $fromEnv;
            return self::$dcoRepoId;
        }

        $repoFile = dirname(__DIR__, 2) . '/src/data/plugins/conf.serial/repo.ser';
        if (is_readable($repoFile)) {
            $repos = json_decode((string) file_get_contents($repoFile), true);
            if (is_array($repos)) {
                foreach ($repos as $id => $repo) {
                    if (($repo['accessType'] ?? '') === 'dco') {
                        self::$dcoRepoId = (string) $id;
                        return self::$dcoRepoId;
                    }
                }
            }
        }

        throw new \RuntimeException('No DCO repository found. Set BOA_TEST_DCO_REPO_ID or create a DCO workspace.');
    }

    public static function folderName(): string
    {
        self::bootstrap();
        return self::$folderName;
    }

    public static function folderPath(): string
    {
        self::bootstrap();
        return self::$folderPath;
    }

    public static function fileName(): string
    {
        self::bootstrap();
        return self::$fileName;
    }

    public static function filePath(): string
    {
        self::bootstrap();
        return self::$filePath;
    }

    public static function setFilePath(string $path): void
    {
        self::$filePath = $path;
    }

    public static function filePathRenamed(): string
    {
        self::bootstrap();
        return self::$filePathRenamed;
    }

    public static function filePathCopy(): string
    {
        self::bootstrap();
        return self::$filePathCopy;
    }

    public static function dcoTitle(): string
    {
        self::bootstrap();
        return self::$dcoTitle;
    }

    public static function setDcoPath(string $path): void
    {
        self::$dcoPath = $path;
        self::$dcoId = basename($path);
        // Plain folders are not listed at DCO repo root — nest fixtures under the DCO src/ tree.
        self::$folderName = 'http_ws_' . self::$runId;
        self::$folderPath = rtrim($path, '/') . '/src/' . self::$folderName;
        self::$fileName = 'note_' . self::$runId . '.txt';
        self::$filePath = self::$folderPath . '/' . self::$fileName;
        self::$filePathRenamed = self::$folderPath . '/note_renamed_' . self::$runId . '.txt';
        self::$filePathCopy = self::$folderPath . '/note_copy_' . self::$runId . '.txt';
    }

    public static function dcoPath(): string
    {
        return self::$dcoPath;
    }

    public static function dcoId(): string
    {
        return self::$dcoId;
    }

    /**
     * Ensure a minimal LOM spec exists so mkdco can resolve DCO_dcotype=lom-rd.
     */
    public static function ensureMinimalLomSpec(): void
    {
        $specsDir = dirname(__DIR__, 2) . '/src/data/plugins/meta.lom/specs';
        if (!is_dir($specsDir)) {
            @mkdir($specsDir, 0755, true);
        }
        $specFile = $specsDir . '/lom-rd.xml';
        if (is_file($specFile)) {
            return;
        }
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<spec>
  <id>lom-rd</id>
  <name>LOM Resource (test)</name>
  <fields>
    <general type="category">
      <title type="text"/>
      <description type="longtext"/>
    </general>
  </fields>
</spec>
XML;
        file_put_contents($specFile, $xml);
    }
}
