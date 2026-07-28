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
namespace BoA\Plugins\Uploader\Flex;

defined('APP_EXEC') or die( 'Access not allowed');

use BoA\Core\Plugins\Plugin;
use BoA\Core\Utils\Utils;
use BoA\Plugins\Core\Log\Logger;

/**
 * Legacy Flash plugin for upload
 * @package APP_Plugins
 * @subpackage Uploader
 */
class FlexUploadProcessor extends Plugin {

	private static $active = false;
	
	public function preProcess($action, &$httpVars, &$fileVars){
		if(isSet($fileVars["Filedata"])){
			self::$active = true;
			Logger::debug("Dir before base64", $httpVars);
			$httpVars["dir"] = base64_decode(urldecode(Utils::arrayGet($httpVars, "dir")));
			$fileVars["userfile_0"] = Utils::arrayGet($fileVars, "Filedata");
			unset($fileVars["Filedata"]);
			Logger::debug("Setting FlexProc active");
		}
	}	
	
	public function postProcess($action, $httpVars, $postProcessData){
		if(!self::$active){
			return false;
		}
		Logger::debug("FlexProc is active=".self::$active, $postProcessData);
		$result = Utils::arrayGet($postProcessData, "processor_result");
		if(isSet($result["SUCCESS"]) && $result["SUCCESS"] === true){
			header('HTTP/1.0 200 OK');
			//die("200 OK");
		}else if(isSet($result["ERROR"]) && is_array($result["ERROR"])){
			$code = Utils::arrayGet(Utils::arrayGet($result, "ERROR", array()), "CODE");
			$message = Utils::arrayGet(Utils::arrayGet($result, "ERROR", array()), "MESSAGE");
			
			//header("HTTP/1.0 $code $message");
			die("Error $code $message");
		}
	}	
}
?>