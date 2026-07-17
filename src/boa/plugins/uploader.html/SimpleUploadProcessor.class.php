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
 * This is a one-line short description of the file/class.
 *
 * You can have a rather longer description of the file/class as well,
 * if you like, and it can span multiple lines.
 *
 * @package    [PACKAGE]
 * @category   [CATEGORY]
 * @copyright  2017 BoA Project
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU Affero GPL v3 or later
 */
namespace BoA\Plugins\Uploader\Html;

use BoA\Core\Http\XMLWriter;
use BoA\Core\Plugins\Plugin;
use BoA\Core\Services\ConfService;
use BoA\Core\Services\PluginsService;
use BoA\Core\Utils\Utils;
use BoA\Plugins\Core\Log\Logger;

defined('APP_EXEC') or die( 'Access not allowed');

/**
 * Processor for standard POST upload
 * @package APP_Plugins
 * @subpackage Uploader
 */
class SimpleUploadProcessor extends Plugin {
	
	public function getDropBg($action, $httpVars, $fileVars){
    die('getDropBg');
		$lang = ConfService::getLanguage();
		$img = APP_PLUGINS_FOLDER."/uploader.html/i18n/$lang-dropzone.png";
		if(!is_file($img)) $img = APP_PLUGINS_FOLDER."uploader.html/i18n/en-dropzone.png";
		header("Content-Type: image/png; name=\"dropzone.png\"");
		header("Content-Length: ".filesize($img));
		header('Cache-Control: public');
		readfile($img);
	}
	
	public function preProcess($action, &$httpVars, &$fileVars){
		if(!isSet($httpVars["input_stream"])){
			return false;
		}

	    $headersCheck = isset(
	            $_SERVER['CONTENT_LENGTH'],
	            $_SERVER['HTTP_X_FILE_NAME']
	        ) ;
        if($headersCheck && isSet($_SERVER['HTTP_X_FILE_SIZE'])){
            if($_SERVER['CONTENT_LENGTH'] != $_SERVER['HTTP_X_FILE_SIZE'])  {
                exit('Warning, wrong headers');
            }
        }
	    $fileNameH = $_SERVER['HTTP_X_FILE_NAME'] ?? '';
	    $fileSizeH = $_SERVER['CONTENT_LENGTH'] ?? 0;

        if(dirname($httpVars["dir"]) == "/" && basename($httpVars["dir"]) == $fileNameH){
            $httpVars["dir"] = "/";
        }
        Logger::debug("SimpleUpload::preProcess", $httpVars);

        if($headersCheck){
	        // create the object and assign property
        	$fileVars["userfile_0"] = array(
        		"input_upload" => true,
        		"name"		   => SystemTextEncoding::fromUTF8(basename($fileNameH)),
        		"size"		   => $fileSizeH
        	);
	    }else{
	    	exit("Warning, missing headers!");
	    }
	}
	
	public function postProcess($action, $httpVars, $postProcessData){
		if(!isSet($httpVars["simple_uploader"]) && !isSet($httpVars["xhr_uploader"])){
			return false;
		}
		Logger::debug("SimpleUploadProc is active");
		$result = $postProcessData["processor_result"];
		
		if(isSet($httpVars["simple_uploader"])){	
			print("<html><script language=\"javascript\">\n");
			if(isSet($result["ERROR"])){
				$message = $result["ERROR"]["MESSAGE"]." (".$result["ERROR"]["CODE"].")";
				print("\n if(parent.app.actionBar.multi_selector) parent.app.actionBar.multi_selector.submitNext('".str_replace("'", "\'", $message)."');");		
			}else{
				print("\n if(parent.app.actionBar.multi_selector) parent.app.actionBar.multi_selector.submitNext();");
                if($result["CREATED_NODE"]){
                    $s = '<tree>';
                    $s .= XMLWriter::writeNodesDiff(array("ADD"=> array($result["CREATED_NODE"])), false);
                    $s.= '</tree>';
                    print("\n var resultString = '".$s."'; var resultXML = parent.parseXml(resultString);");
                    print("\n parent.app.actionBar.parseXmlMessage(resultXML);");
                }
			}
			print("</script></html>");
		}else{
			if(isSet($result["ERROR"])){
				$message = $result["ERROR"]["MESSAGE"]." (".$result["ERROR"]["CODE"].")";
				exit($message);
			}else{
                XMLWriter::header();
                if(isSet($result["CREATED_NODE"])){
                    XMLWriter::writeNodesDiff(array("ADD" => array($result["CREATED_NODE"])), true);
                }
                XMLWriter::close();
				//exit("OK");
			}
		}
		
	}	
	
	public function unifyChunks($action, $httpVars, $fileVars){
		$repository = ConfService::getRepository();
		if(!$repository->detectStreamWrapper(false)){
			return false;
		}
		$plugin = PluginsService::findPlugin("access", $repository->getAccessType());
		$streamData = $plugin->detectStreamWrapper(true);		
		$dir = Utils::decodeSecureMagic($httpVars["dir"]);
    	$destStreamURL = $streamData["protocol"]."://".$repository->getId().$dir."/";    	
		$filename = Utils::decodeSecureMagic($httpVars["file_name"]);
		$chunks = array();
		$index = 0;
		while(isSet($httpVars["chunk_".$index])){
			$chunks[] = Utils::decodeSecureMagic($httpVars["chunk_".$index]);
			$index++;
		}
		
		$newDest = fopen($destStreamURL.$filename, "w");
		for ($i = 0; $i < count($chunks) ; $i++){
			$part = fopen($destStreamURL.$chunks[$i], "r");
			while(!feof($part)){
				fwrite($newDest, fread($part, 4096));
			}
			fclose($part);
			unlink($destStreamURL.$chunks[$i]);
		}
		fclose($newDest);
		
	}
}
?>
