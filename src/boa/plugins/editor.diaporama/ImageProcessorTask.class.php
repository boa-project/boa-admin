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

namespace BoA\Plugins\Editor\Diaporama;

use BoA\Core\Http\HTMLWriter;
use BoA\Core\Plugins\Plugin;
use BoA\Core\Services\ConfService;
use BoA\Core\Utils\Utils;
use BoA\Core\Xml\ManifestNode;
use BoA\Plugins\Core\Log\Logger;
use BoA\Threading\ITask;

defined('APP_EXEC') or die( 'Access not allowed');

define("PNG", "png");
define("GIF", "gif");
define("JPG", "jpg");
define("JPEG", "jpeg");
/**
 * This is a one-line short description of the file/class.
 *
 * You can have a rather longer description of the file/class as well,
 * if you like, and it can span multiple lines.
 *
 * @package    [App Plugins]
 * @category   [Editor]
 * @copyright  2017 BoA Project
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU Affero GPL v3 or later
 */
class ImageProcessorTask implements ITask {

    private $_plugin;
    private $png;
    private $gif;
    private $jpg;
    private $jpeg;
    private $availableSizes;
    private $extensions;
    private $ffmpeg;

    function __construct(){
    }

    /**
    * Returns true if the task is currently running
    *
    */
    function isRunning() {
        return false;
    }

    /**
    * Start running this task
    */
    function start($plugin) {
        $this->_plugin = $plugin;
        //$this->ffmpeg = \FFMpeg\FFMpeg::create();
        $this->scan();
    }

    /**
    * Stop this task if already running
    */
    function stop() {

    }


    /**
    * scan all folders to see if there is any video that requires converting to alternate formats
    */
    function scan() {
        $repositories = ConfService::getRepositoriesList();
        $this->readConfiguration();

        $mimesNode = $this->_plugin->getManifestRawContent("/editor/@mimes", "xml");
        $this->extensions = "";
        if ($mimesNode != null && $mimesNode->length) {
            $this->extensions = $mimesNode[0]->value;
        }

        //Set png as default extension
        if (empty($this->extensions)) {
            $this->extensions = PNG;
        }

        $this->prepareExtensionsFilter();

        foreach($repositories as $key => $repo) {
            $this->scanRepository($repo);
        }
    }

    /**
    *
    */
    private function readConfiguration() {
        if (!isset($this->_plugin)) return;
        $config = $this->_plugin->getConfigs();

        $this->png = $config["FORMAT_PNG"];
        $this->gif = $config["FORMAT_GIF"];
        $this->jpg = $config["FORMAT_JPG"];
        $this->jpeg = $config["FORMAT_JPEG"];
        $this->generateThumbs = $config["GENERATE_THUMBNAIL"];
        $this->thumbnailQuality = $config["THUMBNAIL_QUALITY"];

        $entries = explode(",", $config["AVAILABLE_SIZES"]);
        $this->availableSizes = array();
        foreach ($entries as $entry) {
            list($key, $value) = explode(":", $entry);

            if (!preg_match("/^\d+[xX]\d+$/", trim($value))) {
                //ToDo: log the error
            }
            else {
                list($width, $height) = explode("x", strtolower($value));
                $this->availableSizes[$key] =  [$width, $height];
            }
        }
        //$this->generateThumbs = $config["MISC_THUMBNAILS"];
    }

    /**
    *
    */
    private function prepareExtensionsFilter() {
        $extensions = explode(",", $this->extensions);

        $f1 = function($ext) {
            $output = "";
            foreach (str_split($ext) as $char) {
                $output .= "[".strtoupper($char).strtolower($char)."]";
            }
            return $output;
        };

        $filter = "{" . implode(",", array_map($f1, $extensions)) . "}";
        $this->extensions = $filter;
    }

    /**
    * scan all folders in current repository
    */
    private function scanRepository($repository) {
        $accessType = $repository->getAccessType();

        if (!preg_match("/^(dco)$/", $accessType)) return; //Only dco repositories to process alternates

        $path = $repository->getOption("PATH");
        if (empty($path)) return;

        $alternatepath = $repository->getOption("ALTERNATE_PATH");

        if (empty($alternatepath)) {
            $alternatepath = $path . "/$$__ROOT/.alternate";
        }

        $repo = array("path" => $path, "altpath" => $alternatepath);

        $entries = glob_recursive($path . "/" . str_repeat('?', 36) . "@*/content/*." . $this->extensions, GLOB_NOSORT|GLOB_BRACE);

        foreach ($entries as $filename) {
            $this->makePreviewReady($filename, $repo);
        }
    }

    /**
    * scan all folders in current repository
    */
    private function makePreviewReady($filename, $repo) {

        $filename_parts = pathinfo($filename);
        $relpath = str_replace($repo["path"], "", $filename);
        $parts = explode("/", ltrim($relpath, "/"));
        $root = array_shift($parts);
        $relpath = implode("/", $parts);

        $alternatepath = str_replace("$$__ROOT", $root, $repo["altpath"]) . "/" . $relpath;// . $filename_parts["filename"];
        $ext = strtolower($filename_parts["extension"]);

        if (!file_exists($alternatepath)) {
            @mkdir($alternatepath, 0777, true);
        }

        if (!file_exists($alternatepath)) {
            throw new \Exception("Path -$alternatepath- don't exists and can't be created. " . __FUNCTION__ . " method");
        }

        $image_manager = new ImageManager($filename, $this->availableSizes);

        if ($this->png) {
            $image_manager->ensureFormat(PNG, $alternatepath);
        }

        if ($this->jpg) {
            $image_manager->ensureFormat(JPG, $alternatepath);
        }

        if ($this->gif) {
            $image_manager->ensureFormat(GIF, $alternatepath);
        }

        if ($this->jpeg) {
            $image_manager->ensureFormat(JPEG, $alternatepath);
        }

        if ($this->generateThumbs) {
            $image_manager->generateThumb($alternatepath, $this->thumbnailQuality);
        }
    }

}
