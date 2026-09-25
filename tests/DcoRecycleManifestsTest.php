<?php

declare(strict_types=1);

namespace BoA\Tests;

use BoA\Plugins\Access\Dco\DcoRecycleManifests;
use PHPUnit\Framework\TestCase;

final class DcoRecycleManifestsTest extends TestCase
{
    private string $tmpDir;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/src/boa/plugins/access.dco/DcoRecycleManifests.class.php';
    }

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/boa_recycle_manifests_' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpDir);
    }

    public function testNameHelpersRoundTrip(): void
    {
        $this->assertTrue(DcoRecycleManifests::isActiveManifestName('.manifest'));
        $this->assertTrue(DcoRecycleManifests::isActiveManifestName('.manifest.published'));
        $this->assertTrue(DcoRecycleManifests::isActiveManifestName('.foto.jpeg.manifest'));
        $this->assertTrue(DcoRecycleManifests::isActiveManifestName('.foto.jpeg.manifest.published'));
        $this->assertFalse(DcoRecycleManifests::isActiveManifestName('.deleted.manifest'));
        $this->assertFalse(DcoRecycleManifests::isActiveManifestName('deleted.manifest'));
        $this->assertFalse(DcoRecycleManifests::isActiveManifestName('content.bin'));

        $this->assertSame('.deleted.manifest', DcoRecycleManifests::toDeletedName('.manifest'));
        $this->assertSame('.deleted.manifest.published', DcoRecycleManifests::toDeletedName('.manifest.published'));
        $this->assertSame('.deleted.foto.jpeg.manifest', DcoRecycleManifests::toDeletedName('.foto.jpeg.manifest'));
        $this->assertNull(DcoRecycleManifests::toDeletedName('.deleted.manifest'));

        $this->assertSame('.manifest', DcoRecycleManifests::toActiveName('.deleted.manifest'));
        $this->assertSame('.manifest.published', DcoRecycleManifests::toActiveName('.deleted.manifest.published'));
        $this->assertSame('.manifest', DcoRecycleManifests::toActiveName('deleted.manifest')); // legacy
        $this->assertNull(DcoRecycleManifests::toActiveName('.manifest'));
    }

    public function testMarkDeletedAndRestoredOnPackageTree(): void
    {
        $object = $this->tmpDir . '/FD1160DD-E41D-4AEE-8C80-A6ECB4B376FD@dominio.por.defecto';
        mkdir($object . '/content', 0777, true);
        mkdir($object . '/src', 0777, true);
        file_put_contents($object . '/.manifest', '{"manifest":{"title":"pkg"}}');
        file_put_contents($object . '/.manifest.published', '{"manifest":{"title":"pkg","status":"published"}}');
        file_put_contents($object . '/content/ejemplo.jpeg', 'img');
        file_put_contents($object . '/content/.ejemplo.jpeg.manifest', '{"manifest":{"title":"img"}}');
        file_put_contents($object . '/content/.ejemplo.jpeg.manifest.published', '{"manifest":{"title":"img","status":"published"}}');
        file_put_contents($object . '/content/readme.txt', 'text');

        DcoRecycleManifests::markDeleted($object);

        $this->assertFileExists($object . '/.deleted.manifest');
        $this->assertFileExists($object . '/.deleted.manifest.published');
        $this->assertFileDoesNotExist($object . '/.manifest');
        $this->assertFileDoesNotExist($object . '/.manifest.published');
        $this->assertFileExists($object . '/content/.deleted.ejemplo.jpeg.manifest');
        $this->assertFileExists($object . '/content/.deleted.ejemplo.jpeg.manifest.published');
        $this->assertFileDoesNotExist($object . '/content/.ejemplo.jpeg.manifest');
        $this->assertFileExists($object . '/content/readme.txt');

        // Idempotent: already deleted names are left alone
        DcoRecycleManifests::markDeleted($object);
        $this->assertFileExists($object . '/.deleted.manifest');
        $this->assertFileDoesNotExist($object . '/.deleted.deleted.manifest');

        DcoRecycleManifests::markRestored($object);

        $this->assertFileExists($object . '/.manifest');
        $this->assertFileExists($object . '/.manifest.published');
        $this->assertFileDoesNotExist($object . '/.deleted.manifest');
        $this->assertFileExists($object . '/content/.ejemplo.jpeg.manifest');
        $this->assertFileExists($object . '/content/.ejemplo.jpeg.manifest.published');
        $this->assertFileDoesNotExist($object . '/content/.deleted.ejemplo.jpeg.manifest');
    }

    public function testMarkDeletedAndRestoredOnResourceFileSiblings(): void
    {
        $dir = $this->tmpDir . '/content';
        mkdir($dir, 0777, true);
        $file = $dir . '/recurso.pdf';
        file_put_contents($file, 'pdf');
        file_put_contents($dir . '/.recurso.pdf.manifest', '{}');
        file_put_contents($dir . '/.recurso.pdf.manifest.published', '{}');

        DcoRecycleManifests::markDeleted($file);

        $this->assertFileExists($dir . '/.deleted.recurso.pdf.manifest');
        $this->assertFileExists($dir . '/.deleted.recurso.pdf.manifest.published');
        $this->assertFileDoesNotExist($dir . '/.recurso.pdf.manifest');
        $this->assertFileExists($file);

        DcoRecycleManifests::markRestored($file);

        $this->assertFileExists($dir . '/.recurso.pdf.manifest');
        $this->assertFileExists($dir . '/.recurso.pdf.manifest.published');
        $this->assertFileDoesNotExist($dir . '/.deleted.recurso.pdf.manifest');
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path)) {
            unlink($path);
            return;
        }
        $entries = scandir($path) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }
}
