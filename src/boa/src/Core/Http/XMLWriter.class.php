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
namespace BoA\Core\Http;

use BoA\Core\Http\Controller;
use BoA\Core\Services\AuthService;
use BoA\Core\Services\ConfService;
use BoA\Core\Utils\Utils;
use BoA\Core\Utils\Text\SystemTextEncoding;
use BoA\Plugins\Core\Log\Logger;

defined('APP_EXEC') or die( 'Access not allowed');

/**
 * XML output Generator
 * @package BoA
 * @subpackage Core
 */
class XMLWriter
{
	static $headerSent = false;

    /**
     * Output Headers, XML <?xml version...?> tag and a root node
     * @static
     * @param string $docNode
     * @param array $attributes
     */
	static function header($docNode="tree", $attributes=array())
	{
		if(self::$headerSent !== false && self::$headerSent == $docNode) return ;
		header('Content-Type: text/xml; charset=UTF-8');
		header('Cache-Control: no-cache');
		print('<?xml version="1.0" encoding="UTF-8"?>');
		$attString = "";
		if(count($attributes)){
			foreach ($attributes as $name=>$value){
				$attString.="$name=\"$value\" ";
			}
		}
		self::$headerSent = $docNode;
		print("<$docNode $attString>");

	}
	/**
     * Outputs a closing root not (</tree>)
     * @static
     * @param string $docNode
     * @return void
     */
	static function close($docNode="tree")
	{
		print("</$docNode>");
	}

    /**
     * @static
     * @param string $data
     * @param bool $print
     * @return string
     */
	static function write($data, $print){
		if($print) {
			print($data);
			return "";
		}else{
			return $data;
		}
	}

    /**
     * Ouput the <pagination> tag
     * @static
     * @param integer $count
     * @param integer $currentPage
     * @param integer $totalPages
     * @param integer $dirsCount
     * @return void
     */
	static function renderPaginationData($count, $currentPage, $totalPages, $dirsCount = -1){
		$string = '<pagination count="'.$count.'" total="'.$totalPages.'" current="'.$currentPage.'" overflowMessage="306" icon="folder.png" openicon="folder_open.png" dirsCount="'.$dirsCount.'"/>';
		XMLWriter::write($string, true);
	}
	/**
     * Prints out the XML headers and preamble, then an open node
     * @static
     * @param $nodeName
     * @param $nodeLabel
     * @param $isLeaf
     * @param array $metaData
     * @return void
     */
	static function renderHeaderNode($nodeName, $nodeLabel, $isLeaf, $metaData = array()){
		header('Content-Type: text/xml; charset=UTF-8');
		header('Cache-Control: no-cache');
		print('<?xml version="1.0" encoding="UTF-8"?>');
		self::$headerSent = "tree";
		XMLWriter::renderNode($nodeName, $nodeLabel, $isLeaf, $metaData, false);
	}

    /**
     * @static
     * @param ManifestNode $node
     * @return void
     */
    static function renderManifestHeaderNode($node){
        header('Content-Type: text/xml; charset=UTF-8');
        header('Cache-Control: no-cache');
        print('<?xml version="1.0" encoding="UTF-8"?>');
        self::$headerSent = "tree";
        self::renderManifestNode($node, false);
    }

    /**
     * The basic node
     * @static
     * @param string $nodeName
     * @param string $nodeLabel
     * @param bool $isLeaf
     * @param array $metaData
     * @param bool $close
     * @param bool $print
     * @return void|string
     */
	static function renderNode($nodeName, $nodeLabel, $isLeaf, $metaData = array(), $close=true, $print = true){
		$string = "<tree";
		$metaData["filename"] = $nodeName;
		if(!isSet($metaData["text"])){
			$metaData["text"] = $nodeLabel;
		}
		$metaData["is_file"] = ($isLeaf?"true":"false");

		foreach ($metaData as $key => $value){
            $value = Utils::xmlEntities($value, true);
			$string .= " $key=\"$value\"";
		}
		if($close){
			$string .= "/>";
		}else{
			$string .= ">";
		}
		return XMLWriter::write($string, $print);
	}

    /**
     * @static
     * @param ManifestNode $node
     * @param bool $close
     * @param bool $print
     * @return void|string
     */
    static function renderManifestNode($node, $close = true, $print = true){
        return XMLWriter::renderNode(
            $node->getPath(),
            $node->getLabel(),
            $node->isLeaf(),
            $node->metadata,
            $close,
            $print);
    }

    /**
     * Render a node with arguments passed as array
     * @static
     * @param $array
     * @return void
     */
	static function renderNodeArray($array){
		self::renderNode($array[0],$array[1],$array[2],$array[3]);
	}
	/**
     * Error Catcher for PHP errors. Depending on the SERVER_DEBUG config
     * shows the file/line info or not.
     * @static
     * @param $code
     * @param $message
     * @param $fichier
     * @param $ligne
     * @param $context
     * @return
     */
	static function catchError($code, $message, $fichier, $ligne, $context = null){
		if(error_reporting() == 0) return ;
		if(ConfService::getConf("SERVER_DEBUG")){
			$message = "$message in $fichier (l.$ligne)";
            //debug_print_backtrace();
		}
        try{
            Logger::logAction("error", array("message" => $message));
        }catch(\Exception $e){
            // This will probably trigger a double exception!
            echo "<pre>Error in error";
            debug_print_backtrace();
            echo "</pre>";
            die("Recursive exception. Original error was : ".$message. " in $fichier , line $ligne");
        }
		if(!headers_sent()) XMLWriter::header();
		XMLWriter::sendMessage(null, SystemTextEncoding::toUTF8($message), true);
		XMLWriter::close();
		exit(1);
	}

	/**
	 * Catch exceptions, @see catchError
	 * @param Exception $exception
	 */
	static function catchException($exception){
        try{
            XMLWriter::catchError($exception->getCode(), SystemTextEncoding::fromUTF8($exception->getMessage()), $exception->getFile(), $exception->getLine(), null);
        }catch(\Exception $innerEx){
            print get_class($innerEx)." thrown within the exception handler! Message was: ".$innerEx->getMessage()." in ".$innerEx->getFile()." on line ".$innerEx->getLine()." ".$innerEx->getTraceAsString();
        }
	}
	/**
     * Dynamically replace XML keywords with their live values.
     * APP_SERVER_ACCESS, APP_MIMES_*,APP_ALL_MESSAGES, etc.
     * @static
     * @param string $xml
     * @param bool $stripSpaces
     * @return mixed
     */
	static function replaceXmlKeywords($xml, $stripSpaces = false){
		$messages = ConfService::getMessages();
        $confMessages = ConfService::getMessagesConf();
		$matches = array();
		if(isSet($_SESSION["APP_SERVER_PREFIX_URI"])){
			$xml = str_replace("APP_SERVER_ACCESS", $_SESSION["APP_SERVER_PREFIX_URI"].APP_SERVER_ACCESS, $xml);
		}else{
			$xml = str_replace("APP_SERVER_ACCESS", APP_SERVER_ACCESS, $xml);
		}
		$xml = str_replace("APP_MIMES_EDITABLE", Utils::getMimes("editable"), $xml);
		$xml = str_replace("APP_MIMES_IMAGE", Utils::getMimes("image"), $xml);
		$xml = str_replace("APP_MIMES_AUDIO", Utils::getMimes("audio"), $xml);
		$xml = str_replace("APP_MIMES_ZIP", Utils::getMimes("zip"), $xml);
		$authDriver = ConfService::getAuthDriverImpl();
		if($authDriver != NULL){
			$loginRedirect = $authDriver->getLoginRedirect();
			$xml = str_replace("APP_LOGIN_REDIRECT", ($loginRedirect!==false?"'".$loginRedirect."'":"false"), $xml);
		}
        $xml = str_replace("APP_REMOTE_AUTH", "false", $xml);
        $xml = str_replace("APP_NOT_REMOTE_AUTH", "true", $xml);
        $xml = str_replace("APP_ALL_MESSAGES", "MessageHash=".json_encode(ConfService::getMessages()).";", $xml);

		if(preg_match_all("/APP_MESSAGE(\[.*?\])/", $xml, $matches, PREG_SET_ORDER)){
			foreach($matches as $match){
				$messId = str_replace("]", "", str_replace("[", "", $match[1]));
                if (isset($messages[$messId])) {
                    $xml = str_replace("APP_MESSAGE[$messId]", $messages[$messId], $xml);
                }
			}
		}
		if(preg_match_all("/CONF_MESSAGE(\[.*?\])/", $xml, $matches, PREG_SET_ORDER)){
			foreach($matches as $match){
				$messId = str_replace(array("[", "]"), "", $match[1]);
                $message = $messId;
                if(array_key_exists($messId, $confMessages)){
                    $message = $confMessages[$messId];
                }
				$xml = str_replace("CONF_MESSAGE[$messId]", $message, $xml);
			}
		}
		if(preg_match_all("/MIXIN_MESSAGE(\[.*?\])/", $xml, $matches, PREG_SET_ORDER)){
			foreach($matches as $match){
				$messId = str_replace(array("[", "]"), "", $match[1]);
                $message = $messId;
                if(array_key_exists($messId, $confMessages)){
                    $message = $confMessages[$messId];
                }
				$xml = str_replace("MIXIN_MESSAGE[$messId]", $message, $xml);
			}
		}
		if($stripSpaces){
			$xml = preg_replace("/[\n\r]?/", "", $xml);
			$xml = preg_replace("/\t/", " ", $xml);
		}
        $xml = str_replace(array('xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"','xsi:noNamespaceSchemaLocation="file:../core.boa/registry.xsd"'), "", $xml);
        $tab = array(&$xml);
        Controller::applyIncludeHook("xml.filter", $tab);
		return $xml;
	}
	/**
     * Send a <reload> XML instruction for refreshing the list
     * @static
     * @param string $nodePath
     * @param string $pendingSelection
     * @param bool $print
     * @return string
     */
	static function reloadDataNode($nodePath="", $pendingSelection="", $print = true){
		$nodePath = Utils::xmlEntities($nodePath, true);
		$pendingSelection = Utils::xmlEntities($pendingSelection, true);
		return XMLWriter::write("<reload_instruction object=\"data\" node=\"$nodePath\" file=\"$pendingSelection\"/>", $print);
	}


	/**
     * Send a <reload> XML instruction for refreshing the list
     * @static
     * @param string $nodePath
     * @param string $pendingSelection
     * @param bool $print
     * @return string
     */
	static function writeNodesDiff($diffNodes, $print = false){
        $mess = ConfService::getMessages();
        $buffer = "<nodes_diff>";
        if(isSet($diffNodes["REMOVE"]) && count($diffNodes["REMOVE"])){
            $buffer .= "<remove>";
            foreach($diffNodes["REMOVE"] as $nodePath){
                $nodePath = Utils::xmlEntities($nodePath, true);
                $buffer .= "<tree filename=\"$nodePath\"/>";
            }
            $buffer .= "</remove>";
        }
        if(isSet($diffNodes["ADD"]) && count($diffNodes["ADD"])){
            $buffer .= "<add>";
            foreach($diffNodes["ADD"] as $node){
                $node->loadNodeInfo(false, false, "all");
                if(!empty($node->metaData["mimestring_id"]) && array_key_exists($node->metaData["mimestring_id"], $mess)){
                    $node->mergeMetadata(array("mimestring" =>  $mess[$node->metaData["mimestring_id"]]));
                }
                $buffer .=  self::renderManifestNode($node, true, false);
            }
            $buffer .= "</add>";
        }
        if(isSet($diffNodes["UPDATE"]) && count($diffNodes["UPDATE"])){
            $buffer .= "<update>";
            foreach($diffNodes["UPDATE"] as $originalPath => $node){
                $node->loadNodeInfo(false, false, "all");
                if(!empty($node->metaData["mimestring_id"]) && array_key_exists($node->metaData["mimestring_id"], $mess)){
                    $node->mergeMetadata(array("mimestring" =>  $mess[$node->metaData["mimestring_id"]]));
                }
                $node->original_path = $originalPath;
                $buffer .= self::renderManifestNode($node, true, false);
            }
            $buffer .= "</update>";
        }
        $buffer .= "</nodes_diff>";
        return XMLWriter::write($buffer, $print);

        /*
		$nodePath = Utils::xmlEntities($nodePath, true);
		$pendingSelection = Utils::xmlEntities($pendingSelection, true);
		return XMLWriter::write("<reload_instruction object=\"data\" node=\"$nodePath\" file=\"$pendingSelection\"/>", $print);
        */
	}


	/**
     * Send a <reload> XML instruction for refreshing the repositories list
     * @static
     * @param bool $print
     * @return string
     */
	static function reloadRepositoryList($print = true){
		return XMLWriter::write("<reload_instruction object=\"repository_list\"/>", $print);
	}
	/**
     * Outputs a <require_auth/> tag
     * @static
     * @param bool $print
     * @return string
     */
	static function requireAuth($print = true)
	{
		return XMLWriter::write("<require_auth/>", $print);
	}
	/**
     * Triggers a background action client side
     * @static
     * @param $actionName
     * @param $parameters
     * @param $messageId
     * @param bool $print
     * @param int $delay
     * @return string
     */
	static function triggerBgAction($actionName, $parameters, $messageId, $print=true, $delay = 0){
        $messageId = Utils::xmlEntities($messageId);
		$data = XMLWriter::write("<trigger_bg_action name=\"$actionName\" messageId=\"$messageId\" delay=\"$delay\">", $print);
		foreach ($parameters as $paramName=>$paramValue){
            $paramValue = Utils::xmlEntities($paramValue);
			$data .= XMLWriter::write("<param name=\"$paramName\" value=\"$paramValue\"/>", $print);
		}
		$data .= XMLWriter::write("</trigger_bg_action>", $print);
		return $data;
	}

    static function triggerBgJSAction($jsCode, $messageId, $print=true, $delay = 0){
   		$data = XMLWriter::write("<trigger_bg_action name=\"javascript_instruction\" messageId=\"$messageId\" delay=\"$delay\">", $print);
        $data .= XMLWriter::write("<clientCallback><![CDATA[".$jsCode."]]></clientCallback>", $print);
   		$data .= XMLWriter::write("</trigger_bg_action>", $print);
   		return $data;
   	}

	/**
     * List all bookmmarks as XML
     * @static
     * @param $allBookmarks
     * @param bool $print
     * @return string
     */
	static function writeBookmarks($allBookmarks, $print = true)
	{
		$buffer = "";
		foreach ($allBookmarks as $bookmark)
		{
			$path = ""; $title = "";
			if(is_array($bookmark)){
				$path = $bookmark["PATH"];
				$title = $bookmark["TITLE"];
			}else if(is_string($bookmark)){
				$path = $bookmark;
				$title = basename($bookmark);
			}
			$buffer .= "<bookmark path=\"".Utils::xmlEntities($path)."\" title=\"".Utils::xmlEntities($title)."\"/>";
		}
		if($print) print $buffer;
		else return $buffer;
	}
	/**
     * Utilitary for generating a <component_config> tag for the FilesList component
     * @static
     * @param $config
     * @return void
     */
	static function sendFilesListComponentConfig($config){
		if(is_string($config)){
			print("<client_configs><component_config className=\"FilesList\">$config</component_config></client_configs>");
		}
	}
	/**
     * Send a success or error message to the client.
     * @static
     * @param $logMessage
     * @param $errorMessage
     * @param bool $print
     * @return string
     */
	static function sendMessage($logMessage, $errorMessage, $print = true)
	{
		$messageType = "";
		$message = "";
		if($errorMessage == null)
		{
			$messageType = "SUCCESS";
			$message = Utils::xmlContentEntities($logMessage);
		}
		else
		{
			$messageType = "ERROR";
			$message = Utils::xmlContentEntities($errorMessage);
		}
		return XMLWriter::write("<message type=\"$messageType\">".$message."</message>", $print);
	}
    /**
     * Writes the user data as XML
     * @static
     * @param null $userObject
     * @param bool $details
     * @return void
     */
	static function sendUserData($userObject = null, $details=false){
		print(XMLWriter::getUserXML($userObject, $details));
	}
	/**
     * Extract all the user data and put it in XML
     * @static
     * @param null $userObject
     * @param bool $details
     * @return string
     */
	static function getUserXML($userObject = null, $details=false)
	{
		$buffer = "";
		$loggedUser = AuthService::getLoggedUser();
        $confDriver = ConfService::getConfStorageImpl();
		if($userObject != null) $loggedUser = $userObject;
		if(!AuthService::usersEnabled()){
			$buffer.="<user id=\"shared\">";
			if(!$details){
				$buffer.="<active_repo id=\"".ConfService::getCurrentRepositoryId()."\" write=\"1\" read=\"1\"/>";
			}
			$buffer.= XMLWriter::writeRepositoriesData(null, $details);
			$buffer.="</user>";
		}else if($loggedUser != null){
            $lock = $loggedUser->getLock();
			$buffer.="<user id=\"".$loggedUser->id."\">";
			if(!$details){
				$buffer.="<active_repo id=\"".ConfService::getCurrentRepositoryId()."\" write=\"".($loggedUser->canWrite(ConfService::getCurrentRepositoryId())?"1":"0")."\" read=\"".($loggedUser->canRead(ConfService::getCurrentRepositoryId())?"1":"0")."\"/>";
			}else{
				$buffer .= "<roles>";
				foreach ($loggedUser->getRoles() as $roleId => $boolean){
					if($boolean === true) $buffer.= "<role id=\"$roleId\"/>";
				}
				$buffer .= "</roles>";
			}
			$buffer.= XMLWriter::writeRepositoriesData($loggedUser, $details);
			$buffer.="<preferences>";
            $preferences = $confDriver->getExposedPreferences($loggedUser);
            foreach($preferences as $prefName => $prefData){
                $atts = "";
                if(isSet($prefData["exposed"]) && $prefData["exposed"] == true){
                    foreach($prefData as $k => $v) {
                        if($k=="name") continue;
                        if($k == "value") $k = "default";
                        $atts .= "$k='$v' ";
                    }
                }
                if(isset($prefData["pluginId"])){
                    $atts .=  "pluginId='".$prefData["pluginId"]."' ";
                }
                if($prefData["type"] == "string"){
                    $buffer.="<pref name=\"$prefName\" value=\"".$prefData["value"]."\" $atts/>";
                }else if($prefData["type"] == "json"){
                    $buffer.="<pref name=\"$prefName\" $atts><![CDATA[".$prefData["value"]."]]></pref>";
                }
            }
			$buffer.="</preferences>";
			$buffer.="<special_rights is_admin=\"".($loggedUser->isAdmin()?"1":"0")."\"  ".($lock!==false?"lock=\"$lock\"":"")."/>";
			$bMarks = $loggedUser->getBookmarks();
			if(count($bMarks)){
				$buffer.= "<bookmarks>".XMLWriter::writeBookmarks($bMarks, false)."</bookmarks>";
			}
			$buffer.="</user>";
		}
		return $buffer;
	}
	/**
     * Write the repositories access rights in XML format
     * @static
     * @param AbstractUser|null $loggedUser
     * @param bool $details
     * @return string
     */
	static function writeRepositoriesData($loggedUser, $details=false){
		$st = "<repositories>";
		$streams = ConfService::detectRepositoryStreams(false);
        foreach(ConfService::getAccessibleRepositories($loggedUser, $details, false) as $repoId => $repoObject){
            $toLast = false;
            if($repoObject->getAccessType()=="boaconf"){
                if(AuthService::usersEnabled() && !$loggedUser->isAdmin())continue;
                $toLast = true;
            }
            $rightString = "";
            if($details){
                $rightString = " r=\"".($loggedUser->canRead($repoId)?"1":"0")."\" w=\"".($loggedUser->canWrite($repoId)?"1":"0")."\"";
            }
            $streamString = "";
            if(in_array($repoObject->accessType, $streams)){
                $streamString = "allowCrossRepositoryCopy=\"true\"";
            }
            if($repoObject->getUniqueUser()){
                $streamString .= " user_editable_repository=\"true\" ";
            }
            $slugString = "";
            $slug = $repoObject->getSlug();
            if(!empty($slug)){
                $slugString = "repositorySlug=\"$slug\"";
            }
            $isSharedString = "";
            if($repoObject->hasOwner()){
                $uId = $repoObject->getOwner();
                $uObject = ConfService::getConfStorageImpl()->createUserObject($uId);
                $label = $uObject->personalRole->filterParameterValue("core.conf", "USER_DISPLAY_NAME", APP_REPO_SCOPE_ALL, $uId);
                $isSharedString =  "owner='".$label."'";
            }
            $descTag = "";
            $description = $repoObject->getDescription();
            if(!empty($description)){
                $descTag = '<description>'.$description.'</description>';
            }
            $xmlString = "<repo access_type=\"".$repoObject->accessType."\" id=\"".$repoId."\"$rightString $streamString $slugString $isSharedString><label>".SystemTextEncoding::toUTF8(Utils::xmlEntities($repoObject->getDisplay()))."</label>".$descTag.$repoObject->getClientSettings()."</repo>";
            if($toLast){
                $lastString = $xmlString;
            }else{
                $st .= $xmlString;
            }
        }

		if(isSet($lastString)){
			$st.= $lastString;
		}
		$st .= "</repositories>";
		return $st;
	}
	/**
     * Writes a <logging_result> tag
     * @static
     * @param integer $result
     * @param string $rememberLogin
     * @param string $rememberPass
     * @param string $secureToken
     * @return void
     */
	static function loggingResult($result, $rememberLogin="", $rememberPass = "", $secureToken="")
	{
		$remString = "";
		if($rememberPass != "" && $rememberLogin!= ""){
			$remString = " remember_login=\"$rememberLogin\" remember_pass=\"$rememberPass\"";
		}
		if($secureToken != ""){
			$remString .= " secure_token=\"$secureToken\"";
		}
		print("<logging_result value=\"$result\"$remString/>");
	}

}

