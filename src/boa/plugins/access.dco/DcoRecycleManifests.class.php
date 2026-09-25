<?php
// This file is part of BoA - https://github.com/boa-project
namespace BoA\Plugins\Access\Dco;

defined('APP_EXEC') or die('Access not allowed');

/**
 * Rename catalog/publication manifests when a DCO (or resource) enters or leaves the recycle bin.
 *
 * Active names start with a dot (hidden). Deleted names prepend ".deleted" so they remain
 * hidden and are not confused with published objects by external searches.
 *
 * Examples:
 *   .manifest              -> .deleted.manifest
 *   .manifest.published    -> .deleted.manifest.published
 *   .file.ext.manifest     -> .deleted.file.ext.manifest
 */
class DcoRecycleManifests
{
    public const PREFIX = '.deleted';

    /**
     * True for package/resource catalog manifests still in active form.
     * e.g. .manifest, .manifest.published, .file.ext.manifest, .file.ext.manifest.published
     * Excludes already-deleted names (.deleted.manifest, ...).
     */
    public static function isActiveManifestName(string $name): bool
    {
        if (strncmp($name, self::PREFIX, strlen(self::PREFIX)) === 0) {
            return false;
        }
        return (bool) preg_match('/^\.(?:.*\.)?manifest(?:\.published)?$/', $name);
    }

    /**
     * Active name with PREFIX prepended, or null if $name is not an active manifest.
     */
    public static function toDeletedName(string $name): ?string
    {
        if (!self::isActiveManifestName($name)) {
            return null;
        }
        return self::PREFIX . $name;
    }

    /**
     * Strip PREFIX when $name is a deleted-form active manifest, or null.
     * Also accepts the legacy "deleted" prefix (without leading dot).
     */
    public static function toActiveName(string $name): ?string
    {
        foreach (array(self::PREFIX, 'deleted') as $prefix) {
            $prefixLen = strlen($prefix);
            if (strncmp($name, $prefix, $prefixLen) !== 0) {
                continue;
            }
            $rest = substr($name, $prefixLen);
            if (!self::isActiveManifestName($rest)) {
                continue;
            }
            return $rest;
        }
        return null;
    }

    /**
     * After a successful move into the recycle bin: mark all manifests under $path as deleted.
     * $path may be a DCO directory or a single resource file.
     */
    public static function markDeleted(string $path): void
    {
        self::apply($path, true);
    }

    /**
     * Restore active manifest names under $path (call before moving a file out of recycle so
     * sibling manifests are renamed back and travel with myRename; for directories, may also
     * be called after the move).
     */
    public static function markRestored(string $path): void
    {
        self::apply($path, false);
    }

    private static function apply(string $path, bool $toDeleted): void
    {
        if (is_dir($path)) {
            self::processTree($path, $toDeleted);
            return;
        }
        if (is_file($path)) {
            self::processFileSiblings($path, $toDeleted);
        }
    }

    private static function processTree(string $dir, bool $toDeleted): void
    {
        $handle = @opendir($dir);
        if ($handle === false) {
            return;
        }
        $entries = array();
        while (($file = readdir($handle)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $entries[] = $file;
        }
        closedir($handle);

        foreach ($entries as $file) {
            $full = $dir . '/' . $file;
            if (is_dir($full)) {
                self::processTree($full, $toDeleted);
                continue;
            }
            self::renameIfNeeded($dir, $file, $toDeleted);
        }
    }

    /**
     * Resource manifests live beside the file: .{basename}.manifest[.published]
     * or .deleted.{basename}.manifest[.published] while in recycle.
     */
    private static function processFileSiblings(string $filePath, bool $toDeleted): void
    {
        $dir = dirname($filePath);
        $base = basename($filePath);
        $candidates = array(
            '.' . $base . '.manifest',
            '.' . $base . '.manifest.published',
            self::PREFIX . '.' . $base . '.manifest',
            self::PREFIX . '.' . $base . '.manifest.published',
            // Legacy prefix without leading dot
            'deleted.' . $base . '.manifest',
            'deleted.' . $base . '.manifest.published',
        );
        foreach ($candidates as $name) {
            if (file_exists($dir . '/' . $name)) {
                self::renameIfNeeded($dir, $name, $toDeleted);
            }
        }
    }

    private static function renameIfNeeded(string $dir, string $file, bool $toDeleted): void
    {
        $newName = $toDeleted ? self::toDeletedName($file) : self::toActiveName($file);
        if ($newName === null || $newName === $file) {
            return;
        }
        $from = $dir . '/' . $file;
        $to = $dir . '/' . $newName;
        if (!file_exists($from) || file_exists($to)) {
            return;
        }
        @rename($from, $to);
    }
}
