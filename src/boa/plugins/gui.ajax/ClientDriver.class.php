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
namespace BoA\Plugins\Gui\Ajax;

use BoA\Core\Http\Controller;
use BoA\Core\Http\HTMLWriter;
use BoA\Core\Http\XMLWriter;
use BoA\Core\Plugins\Plugin;
use BoA\Core\Services\AuthService;
use BoA\Core\Services\ConfService;
use BoA\Core\Services\PluginsService;
use BoA\Core\Utils\Utils;
use BoA\Core\Utils\Text\SystemTextEncoding;

defined('APP_EXEC') or die( 'Access not allowed');

/**
 * User Interface main implementation
 * @package APP_Plugins
 * @subpackage Gui
 */
class ClientDriver extends Plugin 
{
    private static $loadedBookmarks;

    public function isEnabled(){
        return true;
    }

    public function loadConfigs($configData){
        parent::loadConfigs($configData);
        if(preg_match('/MSIE 7/', $_SERVER['HTTP_USER_AGENT'] ?? '')){
            // Force legacy theme for the moment
             $this->pluginConf["GUI_THEME"] = "oxygen";
        }
        if(!defined("APP_THEME_FOLDER")){
            define("CLIENT_RESOURCES_FOLDER", APP_PLUGINS_FOLDER_REL."/gui.ajax/res");
            define("APP_THEME_FOLDER", CLIENT_RESOURCES_FOLDER."/themes/".$this->pluginConf["GUI_THEME"]);
        }
        if(!isSet($configData["CLIENT_TIMEOUT_TIME"])){
            $this->pluginConf["CLIENT_TIMEOUT_TIME"] = intval(ini_get("session.gc_maxlifetime"));
        }
    }

	function switchAction($action, $httpVars, $fileVars)
	{
		if(!isSet($this->actions[$action])) return;
        if(preg_match('/MSIE 7/', $_SERVER['HTTP_USER_AGENT'] ?? '')){
            // Force legacy theme for the moment
            $this->pluginConf["GUI_THEME"] = "oxygen";
        }
        if(!defined("APP_THEME_FOLDER")){
            define("CLIENT_RESOURCES_FOLDER", APP_PLUGINS_FOLDER_REL."/gui.ajax/res");
            define("APP_THEME_FOLDER", CLIENT_RESOURCES_FOLDER."/themes/".$this->pluginConf["GUI_THEME"]);
        }		
		foreach($httpVars as $getName=>$getValue){
			$$getName = Utils::securePath($getValue);
		}
		if(isSet($dir) && $action != "upload") $dir = SystemTextEncoding::fromUTF8($dir);
		$mess = ConfService::getMessages();
		
		switch ($action){			
			//------------------------------------
			//	GET AN HTML TEMPLATE
			//------------------------------------
			case "get_template":
			
				HTMLWriter::charsetHeader();
				$folder = CLIENT_RESOURCES_FOLDER."/html";
				if(isSet($httpVars["pluginName"])){
					$folder = APP_PLUGINS_FOLDER."/".Utils::securePath($httpVars["pluginName"]);
					if(isSet($httpVars["pluginPath"])){
						$folder.= "/".Utils::securePath($httpVars["pluginPath"]);
					}
				}
                $crtTheme = $this->pluginConf["GUI_THEME"];
                $thFolder = APP_THEME_FOLDER."/html";
				if(isset($template_name))
				{
                    if(is_file($thFolder."/".$template_name)){
                        include($thFolder."/".$template_name);
                    }else if(is_file($folder."/".$template_name)){
    					include($folder."/".$template_name);
                    }
				}
				
			break;
						
			//------------------------------------
			//	GET I18N MESSAGES
			//------------------------------------
			case "get_i18n_messages":

                $refresh = false;
                if(isSet($httpVars["lang"])){
                    ConfService::setLanguage($httpVars["lang"]);
                    $refresh = true;
                }
				HTMLWriter::charsetHeader('text/javascript');
                var_dump(ConfService::getMessages($refresh));
				HTMLWriter::writeI18nMessagesClass(ConfService::getMessages($refresh));
				
			break;
			
			//------------------------------------
			//	SEND XML REGISTRY
			//------------------------------------
			case "get_xml_registry" :
				
				$regDoc = PluginsService::getXmlRegistry();
                $changes = Controller::filterActionsRegistry($regDoc);
                if($changes) PluginsService::updateXmlRegistry($regDoc);

                $clone = $regDoc->cloneNode(true);
                $clonePath = new \DOMXPath($clone);
                $serverCallbacks = $clonePath->query("//serverCallback|hooks");
                foreach($serverCallbacks as $callback){
                    $processing = $callback->parentNode->removeChild($callback);
                }

				if(isSet($_GET["xPath"])){
					//$regPath = new DOMXPath($regDoc);
					$nodes = $clonePath->query($_GET["xPath"]);
					XMLWriter::header("registry_part", array("xPath"=>$_GET["xPath"]));
					if($nodes->length){
						print(XMLWriter::replaceXmlKeywords($clone->saveXML($nodes->item(0))));
					}
					XMLWriter::close("registry_part");
				}else{
                    Utils::safeIniSet("zlib.output_compression", "4096");
					header('Content-Type: application/xml; charset=UTF-8');
                    print(XMLWriter::replaceXmlKeywords($clone->saveXML()));
				}
				
			break;
									
			//------------------------------------
			//	DISPLAY DOC
			//------------------------------------
			case "display_doc":
			
				HTMLWriter::charsetHeader();
				echo HTMLWriter::getDocFile(Utils::securePath(htmlentities($_GET["doc_file"] ?? "")));
				
			break;
			

			//------------------------------------
			//	GET BOOT GUI
			//------------------------------------
			case "get_boot_gui":

                HTMLWriter::internetExplorerMainDocumentHeader();
                HTMLWriter::charsetHeader();
				
				if(!is_file(TESTS_RESULT_FILE)){
					$outputArray = array();
					$testedParams = array();
					$passed = Utils::runTests($outputArray, $testedParams);
					if(!$passed && !isset($_GET["ignore_tests"])){
						die(Utils::testResultsToTable($outputArray, $testedParams));
					}else{
						Utils::testResultsToFile($outputArray, $testedParams);
					}
				}
				
				$START_PARAMETERS = array(
                    "BOOTER_URL"=>"index.php?get_action=get_boot_conf",
                    "MAIN_ELEMENT" => "desktop"
                );
				if(AuthService::usersEnabled())
				{
					AuthService::preLogUser((isSet($httpVars["remote_session"])?$httpVars["remote_session"]:""));
					AuthService::bootSequence($START_PARAMETERS);
					if(AuthService::getLoggedUser() != null || AuthService::logUser(null, null) == 1)
					{
						if(AuthService::getDefaultRootId() == -1){
							AuthService::disconnect();
						}else{
							$loggedUser = AuthService::getLoggedUser();
							if(!$loggedUser->canRead(ConfService::getCurrentRepositoryId())
									&& AuthService::getDefaultRootId() != ConfService::getCurrentRepositoryId())
							{
								ConfService::switchRootDir(AuthService::getDefaultRootId());
							}
						}
					}
				}
				
				Utils::parseApplicationGetParameters($_GET, $START_PARAMETERS, $_SESSION);
				
				$confErrors = ConfService::getErrors();
				if(count($confErrors)){
					$START_PARAMETERS["ALERT"] = implode(", ", array_values($confErrors));
				}
                // PRECOMPUTE BOOT CONF
                if(!preg_match('/MSIE 7/',$_SERVER['HTTP_USER_AGENT'] ?? '') && !preg_match('/MSIE 8/',$_SERVER['HTTP_USER_AGENT'] ?? '')){
                    $START_PARAMETERS["PRELOADED_BOOT_CONF"] = $this->computeBootConf();
                }

                // PRECOMPUTE REGISTRY
                $regDoc = PluginsService::getXmlRegistry();
                $changes = Controller::filterActionsRegistry($regDoc);
                if($changes) PluginsService::updateXmlRegistry($regDoc);
                $START_PARAMETERS["PRELOADED_REGISTRY"] = XMLWriter::replaceXmlKeywords($regDoc->saveXML());
				$JSON_START_PARAMETERS = json_encode($START_PARAMETERS);
                $crtTheme = $this->pluginConf["GUI_THEME"];
				if(ConfService::getConf("JS_DEBUG")){
					if(!isSet($mess)){
						$mess = ConfService::getMessages();
					}
                    if(is_file(APP_PLUGINS_FOLDER."/gui.ajax/res/themes/$crtTheme/html/gui_debug.html")){
                        include(APP_PLUGINS_FOLDER."/gui.ajax/res/themes/$crtTheme/html/gui_debug.html");
                    }else{
                        include(APP_PLUGINS_FOLDER."/gui.ajax/res/html/gui_debug.html");
                    }
				}else{
                    if(is_file(APP_PLUGINS_FOLDER."/gui.ajax/res/themes/$crtTheme/html/gui.html")){
                        $content = file_get_contents(APP_PLUGINS_FOLDER."/gui.ajax/res/themes/$crtTheme/html/gui.html");
                    }else{
                        $content = file_get_contents(APP_PLUGINS_FOLDER."/gui.ajax/res/html/gui.html");
                    }
                    if(preg_match('/MSIE 7/',$_SERVER['HTTP_USER_AGENT'] ?? '') || preg_match('/MSIE 8/',$_SERVER['HTTP_USER_AGENT'] ?? '')){
                        $content = str_replace("app_boot.js", "app_boot_protolegacy.js", $content);
                    }
					$content = XMLWriter::replaceXmlKeywords($content, false);
					if($JSON_START_PARAMETERS){
						$content = str_replace("//APP_JSON_START_PARAMETERS", "startParameters = ".$JSON_START_PARAMETERS.";", $content);
					}
					print($content);
				}				
			break;
			//------------------------------------
			//	GET CONFIG FOR BOOT
			//------------------------------------
			case "get_boot_conf":

                $out = array();
                Utils::parseApplicationGetParameters($_GET, $out, $_SESSION);
                $config = $this->computeBootConf();
				header("Content-type:application/json;charset=UTF-8");
				print(json_encode($config));
				
			break;
					
			default;
			break;
		}
		
		return false;		
	}

    function computeBootConf(){
        if(isSet($_GET["server_prefix_uri"])){
            $_SESSION["APP_SERVER_PREFIX_URI"] = str_replace("_UP_", "..", $_GET["server_prefix_uri"]);
        }
        $config = array();
        $config["resourcesFolder"] = APP_PLUGINS_FOLDER_REL."/gui.ajax/res";
        if(session_name() == "App_Shared"){
            $config["appServerAccess"] = "index_shared.php";
        }else{
            $config["appServerAccess"] = APP_SERVER_ACCESS;
        }
        $config["zipEnabled"] = ConfService::zipEnabled();
        $config["multipleFilesDownloadEnabled"] = ConfService::getCoreConf("ZIP_CREATION");
        $config["customWording"] = array(
            "welcomeMessage" => $this->pluginConf["CUSTOM_WELCOME_MESSAGE"],
            "title"			 => ConfService::getCoreConf("APPLICATION_TITLE"),
            "icon"			 => $this->pluginConf["CUSTOM_ICON"],
            "iconWidth"		 => $this->pluginConf["CUSTOM_ICON_WIDTH"],
            "iconHeight"     => $this->pluginConf["CUSTOM_ICON_HEIGHT"],
            "iconOnly"       => $this->pluginConf["CUSTOM_ICON_ONLY"],
            "titleFontSize"	 => $this->pluginConf["CUSTOM_FONT_SIZE"]
        );
        if(!empty($this->pluginConf["CUSTOM_ICON_BINARY"])){
            $config["customWording"]["icon_binary_url"] = "get_action=get_global_binary_param&binary_id=".$this->pluginConf["CUSTOM_ICON_BINARY"];
        }
        $config["usersEnabled"] = AuthService::usersEnabled();
        $config["loggedUser"] = (AuthService::getLoggedUser()!=null);
        $config["currentLanguage"] = ConfService::getLanguage();
        $config["session_timeout"] = intval(ini_get("session.gc_maxlifetime"));
        if(!isSet($this->pluginConf["CLIENT_TIMEOUT_TIME"]) || $this->pluginConf["CLIENT_TIMEOUT_TIME"] == ""){
            $to = $config["session_timeout"];
        }else{
            $to = $this->pluginConf["CLIENT_TIMEOUT_TIME"];
        }
        $config["client_timeout"] = $to;
        $config["client_timeout_warning"] = $this->pluginConf["CLIENT_TIMEOUT_WARN"];
        $config["availableLanguages"] = ConfService::getConf("AVAILABLE_LANG");
        $config["usersEditable"] = ConfService::getAuthDriverImpl()->usersEditable();
        $config["appVersion"] = APP_VERSION;
        $config["appVersionDate"] = APP_VERSION_DATE;
        if(stristr($_SERVER["HTTP_USER_AGENT"] ?? "", "msie 6")){
            $config["cssResources"] = array("css/pngHack/pngHack.css");
        }
        if(!empty($this->pluginConf['GOOGLE_ANALYTICS_ID'])) {
            $config["googleAnalyticsData"] = array(
                "id"=> 		$this->pluginConf['GOOGLE_ANALYTICS_ID'],
                "domain" => $this->pluginConf['GOOGLE_ANALYTICS_DOMAIN'],
                "event" => 	$this->pluginConf['GOOGLE_ANALYTICS_EVENT']);
        }
        $config["i18nMessages"] = ConfService::getMessages();
        $config["password_min_length"] = ConfService::getCoreConf("PASSWORD_MINLENGTH", "auth");
        $config["SECURE_TOKEN"] = AuthService::generateSecureToken();
        $config["streaming_supported"] = "true";
        $config["theme"] = $this->pluginConf["GUI_THEME"];
        return $config;
    }

    /**
     * @param ManifestNode $node
     * @return void
     */
    function nodeBookmarkMetadata(&$node){
        $user = AuthService::getLoggedUser();
        if($user == null) return;
        $metadata = $node->retrieveMetadata("bookmarked", true, APP_METADATA_SCOPE_REPOSITORY, true);
        if(is_array($metadata) && count($metadata)){
            $node->mergeMetadata(array(
                     "bookmarked" => "true",
                     "overlay_icon"  => "bookmark.png"
                ), true);
            return;
        }
        if(!isSet(self::$loadedBookmarks)){
            self::$loadedBookmarks = $user->getBookmarks();
        }
        foreach(self::$loadedBookmarks as $bm){
            if($bm["PATH"] == $node->getPath()){
                $node->mergeMetadata(array(
                         "bookmarked" => "true",
                         "overlay_icon"  => "bookmark.png"
                    ), true);
                $node->setMetadata("bookmarked", array("bookmarked"=> "true"), true, APP_METADATA_SCOPE_REPOSITORY, true);
            }
        }
    }

    static function filterXml(&$value){
        $instance = PluginsService::getInstance()->findPlugin("gui", "ajax");
        if($instance === false) return ;
        $confs = $instance->getConfigs();
        $theme = $confs["GUI_THEME"];
        if(!defined("APP_THEME_FOLDER")){
            define("CLIENT_RESOURCES_FOLDER", APP_PLUGINS_FOLDER_REL."/gui.ajax/res");
            define("APP_THEME_FOLDER", CLIENT_RESOURCES_FOLDER."/themes/".$theme);
        }
        $value = str_replace(array("APP_CLIENT_RESOURCES_FOLDER", "APP_CURRENT_VERSION"), array(CLIENT_RESOURCES_FOLDER, APP_VERSION), $value);
        
        if(isSet($_SESSION["APP_SERVER_PREFIX_URI"])){
            $value = str_replace("APP_THEME_FOLDER", $_SESSION["APP_SERVER_PREFIX_URI"].APP_THEME_FOLDER, $value);
        }else{
            $value = str_replace("APP_THEME_FOLDER", APP_THEME_FOLDER, $value);
        }
        return $value;
    }
}

Controller::registerIncludeHook("xml.filter", array("BoA\Plugins\Gui\Ajax\ClientDriver", "filterXml"));
