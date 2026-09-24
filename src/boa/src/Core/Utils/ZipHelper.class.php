<?php
// This file is part of BoA - https://github.com/boa-project
//
// BoA is free software: you can redistribute it and/or modify
// it under the terms of the GNU Affero General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// BoA is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU Affero General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with BoA.  If not, see <http://www.gnu.org/licenses/>.
//
// The latest code can be found at <https://github.com/boa-project/>.

/**
 * ZipArchive helper replacing PclZip for BoA first-party zip operations.
 *
 * @package    BoA
 * @category   Core
 * @copyright  2017 BoA Project
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU Affero GPL v3 or later
 */
namespace BoA\Core\Utils;

defined('APP_EXEC') or die('Access not allowed');

/**
 * Thin ZipArchive wrapper with PclZip-shaped list/extract/create helpers.
 */
class ZipHelper
{
    /** @var string */
    private static $lastError = '';

    public static function lastError()
    {
        return self::$lastError;
    }

    /**
     * List archive entries in a PclZip-compatible shape.
     *
     * @param string $zipPath
     * @return array
     * @throws \Exception
     */
    public static function listContent($zipPath)
    {
        $zip = self::openExisting($zipPath);
        $list = array();
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $name = str_replace('\\', '/', $stat['name']);
            $isDir = (substr($name, -1) === '/') || (($stat['size'] === 0) && self::indexLooksLikeDir($zip, $i, $name));
            $list[] = array(
                'filename' => $name,
                'stored_filename' => $name,
                'size' => (int) $stat['size'],
                'compressed_size' => (int) $stat['comp_size'],
                'mtime' => (int) $stat['mtime'],
                'folder' => $isDir ? 1 : 0,
                'index' => $i,
                'status' => 'ok',
                'crc' => isset($stat['crc']) ? $stat['crc'] : 0,
            );
        }
        $zip->close();
        return $list;
    }

    /**
     * Extract one or more named entries; optionally strip directories (REMOVE_ALL_PATH).
     *
     * @param string $zipPath
     * @param string|array $names
     * @param string $destDir
     * @param bool $removeAllPath
     * @return int number of extracted entries, or 0 on failure
     */
    public static function extractByName($zipPath, $names, $destDir, $removeAllPath = false)
    {
        return self::extractInternal($zipPath, $destDir, (array) $names, '', $removeAllPath);
    }

    /**
     * Extract matching entries into $destDir, stripping $removePath prefix from stored names.
     *
     * @param string $zipPath
     * @param string $destDir
     * @param string $removePath
     * @param string|array|null $byName null = all entries
     * @return int
     */
    public static function extract($zipPath, $destDir, $removePath = '', $byName = null)
    {
        return self::extractInternal($zipPath, $destDir, $byName === null ? null : (array) $byName, $removePath, false);
    }

    /**
     * Create a zip from file/dir entries.
     *
     * Each entry: array('filename' => absolute path, 'new_short_name' => optional basename override)
     *
     * @param string $dest
     * @param array $filePaths
     * @param string $removePath
     * @param callable|null $preAdd function($value, $header): bool — return false to skip
     * @param bool $noCompression
     * @return array|false list of added local names, or false on failure
     */
    public static function create($dest, array $filePaths, $removePath = '', $preAdd = null, $noCompression = true)
    {
        self::$lastError = '';
        if (!class_exists('\ZipArchive')) {
            self::$lastError = 'ZipArchive extension is not available';
            return false;
        }
        $zip = new \ZipArchive();
        $flags = \ZipArchive::CREATE | \ZipArchive::OVERWRITE;
        if ($zip->open($dest, $flags) !== true) {
            self::$lastError = 'Unable to create archive '.$dest;
            return false;
        }

        $removePath = self::normalizeFsPath($removePath);
        $added = array();
        try {
            foreach ($filePaths as $entry) {
                $realFile = self::normalizeFsPath($entry['filename']);
                if (!file_exists($realFile)) {
                    self::$lastError = 'Missing file '.$realFile;
                    $zip->close();
                    @unlink($dest);
                    return false;
                }
                $local = self::localNameFor($realFile, $removePath, isset($entry['new_short_name']) ? $entry['new_short_name'] : null);
                self::addPathRecursive($zip, $realFile, $local, $preAdd, $noCompression, $added);
            }
        } catch (\Exception $e) {
            self::$lastError = $e->getMessage();
            $zip->close();
            @unlink($dest);
            return false;
        }

        if (!$zip->close()) {
            self::$lastError = 'Failed to finalize archive '.$dest;
            return false;
        }
        return $added;
    }

    private static function extractInternal($zipPath, $destDir, $byNames, $removePath, $removeAllPath)
    {
        self::$lastError = '';
        try {
            $zip = self::openExisting($zipPath);
        } catch (\Exception $e) {
            self::$lastError = $e->getMessage();
            return 0;
        }

        $destDir = rtrim(str_replace('\\', '/', $destDir), '/');
        $removePath = self::normalizeZipPath($removePath);
        $count = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $stored = self::normalizeZipPath($stat['name']);
            if ($byNames !== null && !self::nameMatches($stored, $byNames)) {
                continue;
            }

            if ($removeAllPath) {
                $relative = basename(rtrim($stored, '/'));
                if (substr($stat['name'], -1) === '/') {
                    continue;
                }
            } else {
                $relative = $stored;
                if ($removePath !== '' && strpos($relative, $removePath) === 0) {
                    $relative = substr($relative, strlen($removePath));
                }
                $relative = ltrim($relative, '/');
            }

            if ($relative === '' || $relative === false) {
                continue;
            }

            $target = $destDir.'/'.$relative;
            $isDir = (substr($stat['name'], -1) === '/');
            if ($isDir) {
                if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
                    self::$lastError = 'Cannot create directory '.$target;
                    $zip->close();
                    return 0;
                }
                $count++;
                continue;
            }

            $parent = dirname($target);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
                self::$lastError = 'Cannot create directory '.$parent;
                $zip->close();
                return 0;
            }

            $stream = $zip->getStream($stat['name']);
            if ($stream === false) {
                self::$lastError = 'Cannot read entry '.$stat['name'];
                $zip->close();
                return 0;
            }
            $out = fopen($target, 'wb');
            if ($out === false) {
                fclose($stream);
                self::$lastError = 'Cannot write '.$target;
                $zip->close();
                return 0;
            }
            stream_copy_to_stream($stream, $out);
            fclose($out);
            fclose($stream);
            if (!empty($stat['mtime'])) {
                @touch($target, $stat['mtime']);
            }
            $count++;
        }

        $zip->close();
        if ($count === 0 && $byNames !== null) {
            self::$lastError = 'No matching entries extracted';
        }
        return $count;
    }

    private static function addPathRecursive(\ZipArchive $zip, $realPath, $localName, $preAdd, $noCompression, array &$added)
    {
        $header = array('filename' => $realPath);
        if (is_callable($preAdd) && !$preAdd(1, $header)) {
            return;
        }

        if (is_dir($realPath)) {
            $dirLocal = rtrim(str_replace('\\', '/', $localName), '/');
            if ($dirLocal !== '') {
                $zip->addEmptyDir($dirLocal.'/');
                $added[] = array('stored_filename' => $dirLocal.'/', 'filename' => $realPath, 'folder' => 1);
            }
            $entries = @scandir($realPath);
            if ($entries === false) {
                return;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $childReal = $realPath.DIRECTORY_SEPARATOR.$entry;
                $childLocal = ($dirLocal === '' ? $entry : $dirLocal.'/'.$entry);
                self::addPathRecursive($zip, $childReal, $childLocal, $preAdd, $noCompression, $added);
            }
            return;
        }

        $localName = ltrim(str_replace('\\', '/', $localName), '/');
        if (!$zip->addFile($realPath, $localName)) {
            throw new \Exception('Failed to add '.$realPath);
        }
        if ($noCompression && method_exists($zip, 'setCompressionName')) {
            $zip->setCompressionName($localName, \ZipArchive::CM_STORE);
        }
        $added[] = array('stored_filename' => $localName, 'filename' => $realPath, 'folder' => 0);
    }

    private static function localNameFor($realFile, $removePath, $shortName)
    {
        $stored = $realFile;
        if ($removePath !== '' && strpos($stored, $removePath) === 0) {
            $stored = substr($stored, strlen($removePath));
        }
        $stored = ltrim(str_replace('\\', '/', $stored), '/');
        if ($shortName !== null && $shortName !== '') {
            $dir = dirname($stored);
            $stored = ($dir === '.' ? $shortName : $dir.'/'.$shortName);
        }
        return $stored;
    }

    private static function openExisting($zipPath)
    {
        if (!class_exists('\ZipArchive')) {
            throw new \Exception('ZipArchive extension is not available');
        }
        if (!is_file($zipPath)) {
            throw new \Exception('Zip file not found: '.$zipPath);
        }
        $zip = new \ZipArchive();
        $ok = $zip->open($zipPath);
        if ($ok !== true) {
            throw new \Exception('Unable to open zip '.$zipPath.' (code '.$ok.')');
        }
        return $zip;
    }

    private static function nameMatches($stored, array $names)
    {
        $stored = self::normalizeZipPath($stored);
        $storedTrim = rtrim($stored, '/');
        foreach ($names as $want) {
            $want = self::normalizeZipPath($want);
            $wantTrim = rtrim($want, '/');
            if ($stored === $want || $storedTrim === $wantTrim || $stored === $wantTrim.'/') {
                return true;
            }
            if ($wantTrim !== '' && strpos($stored, $wantTrim.'/') === 0) {
                return true;
            }
        }
        return false;
    }

    private static function normalizeZipPath($path)
    {
        $path = str_replace('\\', '/', (string) $path);
        if ($path !== '' && $path[0] === '/') {
            $path = substr($path, 1);
        }
        return $path;
    }

    private static function normalizeFsPath($path)
    {
        $path = str_replace('\\', '/', (string) $path);
        return rtrim($path, '/');
    }

    private static function indexLooksLikeDir(\ZipArchive $zip, $index, $name)
    {
        // Heuristic unused when trailing slash is present; keep for incomplete archives.
        return false;
    }
}
