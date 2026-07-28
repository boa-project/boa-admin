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

use BoA\Core\Http\Controller;
use BoA\Core\Plugins\Plugin;
use BoA\Core\Services\ConfService;
use BoA\Core\Utils\CacheManager;
use BoA\Core\Utils\Utils;
use BoA\Core\Xml\ManifestNode;
use BoA\Plugins\Core\Log\Logger;
use BoA\Threading\ITaskProviderFactory;
use BoA\Plugins\Editor\Diaporama\ImageTasks;

defined('APP_EXEC') or die( 'Access not allowed');

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
class ImagePreviewer extends Plugin implements ITaskProviderFactory {

    private $currentDimension;

	public function switchAction($action, $httpVars, $filesVars){
		
		if(!isSet($this->actions[$action])) return false;
    	
		$repository = ConfService::getRepository();
		if(!$repository->detectStreamWrapper(true)){
			return false;
		}
		if(!isSet($this->pluginConf)){
			$this->pluginConf = array("GENERATE_THUMBNAIL"=>false);
		}
		
		
		$streamData = $repository->streamData;
		$this->streamData = $streamData;
    	$destStreamURL = $streamData["protocol"]."://".$repository->getId();
		    	
		if($action == "preview_data_proxy"){
			$file = Utils::decodeSecureMagic(Utils::arrayGet($httpVars, "file"));
            if(!file_exists($destStreamURL.$file)) return;
			
			if(isSet($httpVars["get_thumb"]) && $this->pluginConf["GENERATE_THUMBNAIL"]){
                $dimension = 200;
                if(isSet($httpVars["dimension"]) && is_numeric($httpVars["dimension"])) $dimension = $httpVars["dimension"];
				$cacheItem = CacheManager::getItem("diaporama_".$dimension, $destStreamURL.$file, array($this, "generateThumbnail"));
                $this->currentDimension = $dimension;
				$data = $cacheItem->getData();
				$cId = $cacheItem->getId();
				
				header("Content-Type: ".Utils::getImageMimeType(basename($cId))."; name=\"".basename($cId)."\"");
				header("Content-Length: ".strlen($data));
				header('Cache-Control: public');
                header("Pragma:");
                header("Last-Modified: " . gmdate("D, d M Y H:i:s", time()-10000) . " GMT");
                header("Expires: " . gmdate("D, d M Y H:i:s", time()+5*24*3600) . " GMT");
				print($data);

			}else{
	 			//$filesize = filesize($destStreamURL.$file);
                $node = new ManifestNode($destStreamURL.$file);

                $fp = fopen($destStreamURL.$file, "r");
                $stat = fstat($fp);
                $filesize = $stat["size"];
				header("Content-Type: ".Utils::getImageMimeType(basename($file))."; name=\"".basename($file)."\"");
				header("Content-Length: ".$filesize);
				header('Cache-Control: public');
                header("Pragma:");
                header("Last-Modified: " . gmdate("D, d M Y H:i:s", time()-10000) . " GMT");
                header("Expires: " . gmdate("D, d M Y H:i:s", time()+5*24*3600) . " GMT");

				$class = $streamData["classname"];
				$stream = fopen("php://output", "a");
				call_user_func(array($streamData["classname"], "copyFileInStream"), $destStreamURL.$file, $stream);
				fflush($stream);
				fclose($stream);
                Controller::applyHook("node.read", array($node));
			}
		}
	}
	
	/**
	 * 
	 * @param ManifestNode $oldFile
	 * @param ManifestNode $newFile
	 * @param Boolean $copy
	 */
	public function removeThumbnail($oldFile, $newFile = null, $copy = false){
		if($oldFile == null) return ;
		if(!$this->handleMime($oldFile->getUrl())) return;
		if($newFile == null || $copy == false){
			$diapoFolders = glob((defined('APP_SHARED_CACHE_DIR')?APP_SHARED_CACHE_DIR:CacheManager_DIR)."/diaporama_*",GLOB_ONLYDIR);
            if($diapoFolders !== false && is_array($diapoFolders)){
                foreach($diapoFolders as $f) {
                    $f = basename($f);
                    Logger::debug("GLOB ".$f);
                    CacheManager::clearItem($f, $oldFile->getUrl());
                }
            }
		}
	}

	public function generateThumbnail($masterFile, $targetFile){
        $size = $this->currentDimension;
		require_once(APP_PLUGINS_FOLDER."/editor.diaporama/PThumb.lib.php");
		$pThumb = new \PThumb($this->pluginConf["THUMBNAIL_QUALITY"]);
		if(!$pThumb->isError()){
			$pThumb->remote_wrapper = $this->streamData["classname"];
            //Logger::debug("Will fit thumbnail");
			$sizes = $pThumb->fit_thumbnail($masterFile, $size, -1, 1, true);
            //Logger::debug("Will print thumbnail");
			$pThumb->print_thumbnail($masterFile,$sizes[0],$sizes[1],false, false, $targetFile);
            //Logger::debug("Done");
			if($pThumb->isError()){
				print_r($pThumb->error_array);
				Logger::logAction("error", $pThumb->error_array);
				return false;
			}			
		}else{
			print_r($pThumb->error_array);
			Logger::logAction("error", $pThumb->error_array);			
			return false;		
		}		
	}
	
	//public function extractImageMetadata($currentNode, &$metadata, $wrapperClassName, &$realFile){
	/**
	 * Enrich node metadata
	 * @param ManifestNode $node
	 */
	public function extractImageMetadata(&$node){
		$currentPath = $node->getUrl();
		$wrapperClassName = $node->wrapperClassName;
		$isImage = Utils::is_image($currentPath);
		$node->is_image = $isImage;
		if(!$isImage) return;
		$setRemote = false;
		$remoteWrappers = $this->pluginConf["META_EXTRACTION_REMOTEWRAPPERS"];
        if(is_string($remoteWrappers)){
            $remoteWrappers = explode(",",$remoteWrappers);
        }
		$remoteThreshold = $this->pluginConf["META_EXTRACTION_THRESHOLD"];		
		if(in_array($wrapperClassName, $remoteWrappers)){
			if($remoteThreshold != 0 && isSet($node->bytesize)){
				$setRemote = ($node->bytesize > $remoteThreshold);
			}else{
				$setRemote = true;
			}
		}
		if($isImage)
		{
			if($setRemote){
				$node->image_type = "N/A";
				$node->image_width = "N/A";
				$node->image_height = "N/A";
				$node->readable_dimension = "";
			}else{
				$realFile = $node->getRealFile();
				list($width, $height, $type, $attr) = @getimagesize($realFile);
				$node->image_type = image_type_to_mime_type($type);
				$node->image_width = $width;
				$node->image_height = $height;
				$node->readable_dimension = $width."px X ".$height."px";
			}
		}
		//Logger::debug("CURRENT NODE IN EXTRACT IMAGE METADATA ", $node);
	}
	
	protected function handleMime($filename){
		$mimesAtt = explode(",", $this->xPath->query("@mimes")->item(0)->nodeValue);
		$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		return in_array($ext, $mimesAtt);
	}	

    /**
     * 
     */
    public function getTaskProvider() {
        return isset($this->taskProvider) ? $this->taskProvider : ($this->taskProvider = new ImageTasks());
    }
}
?>