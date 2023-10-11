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
 *
 */
namespace BoA\Plugins\Access\BoAConf;

use BoA\Core\Http\Controller;
use BoA\Core\Http\HTMLWriter;
use BoA\Core\Http\XMLWriter;
use BoA\Core\Plugins\Plugin;
use BoA\Core\Security\Role;
use BoA\Core\Services\AuthService;
use BoA\Core\Services\ConfService;
use BoA\Core\Services\PluginsService;
use BoA\Core\Utils\Utils;
use BoA\Core\Utils\Text\SystemTextEncoding;
use BoA\Plugins\Core\Access\AbstractAccessDriver;
use BoA\Plugins\Core\Log\Logger;

defined('APP_EXEC') or die( 'Access not allowed');

/**
 * @package APP_Plugins
 * @subpackage Access
 * @class confAccessDriver
 * Plugin to access the configurations data
 */
class ConfAccessDriver extends AbstractAccessDriver
{

    private $listSpecialRoles = APP_SERVER_DEBUG;

	function listAllActions($action, $httpVars, $fileVars){
        if(!isSet($this->actions[$action])) return;
        parent::accessPreprocess($action, $httpVars, $fileVars);
        $loggedUser = AuthService::getLoggedUser();
        if(AuthService::usersEnabled() && !$loggedUser->isAdmin()) return ;
        switch($action)
        {
            //------------------------------------
            //	BASIC LISTING
            //------------------------------------
            case "list_all_repositories_json":

                $repositories = ConfService::getRepositoriesList("all");
                $repoOut = array();
                foreach($repositories as $repoObject){
                    $repoOut[$repoObject->getId()] = $repoObject->getDisplay();
                }
                HTMLWriter::charsetHeader("application/json");
                echo json_encode(array("LEGEND" => "Select a repository", "LIST" => $repoOut));

            break;

            case "list_all_plugins_actions":
                $nodes = PluginsService::getInstance()->searchAllManifests("//action", "node", false, true, true);
                $actions = array();
                foreach($nodes as $node){
                    $xPath = new \DOMXPath($node->ownerDocument);
                    $proc = $xPath->query("processing", $node);
                    if(!$proc->length) continue;
                    $txt = $xPath->query("gui/@text", $node);
                    if($txt->length){
                        $messId = $txt->item(0)->nodeValue;
                    }else{
                        $messId = "";
                    }
                    $parentPlugin = $node->parentNode->parentNode->parentNode;
                    $pId = $parentPlugin->attributes->getNamedItem("id")->nodeValue;
                    if(empty($pId)){
                        $pId = $parentPlugin->nodeName .".";
                        if($pId == "coredriver.") $pId = "access.";
                        $pId .= $parentPlugin->attributes->getNamedItem("name")->nodeValue;
                    }
                    //echo($pId." : ". $node->attributes->getNamedItem("name")->nodeValue . " (".$messId.")<br>");
                    if(!is_array($actions[$pId])) $actions[$pId] = array();
                    $actionName = $node->attributes->getNamedItem("name")->nodeValue;
                    $actions[$pId][$actionName] = array( "action" => $actionName , "label" => $messId);

                }
                foreach($actions as $actPid => $actionGroup){
                    ksort($actionGroup, SORT_STRING);
                    $actions[$actPid] = array();
                    foreach($actionGroup as $k => $v){
                        $actions[$actPid][] = $v;
                    }
                }
                HTMLWriter::charsetHeader("application/json");
                echo json_encode(array("LIST" => $actions, "HAS_GROUPS" => true));
                break;
            case "list_all_plugins_parameters":
                $nodes = PluginsService::getInstance()->searchAllManifests("//param|//global_param", "node", false, true, true);
                $actions = array();
                foreach($nodes as $node){
                    if($node->parentNode->nodeName != "server_settings") continue;
                    $parentPlugin = $node->parentNode->parentNode;
                    $pId = $parentPlugin->attributes->getNamedItem("id")->nodeValue;
                    if(empty($pId)){
                        $pId = $parentPlugin->nodeName .".";
                        if($pId == "coredriver.") $pId = "access.";
                        $pId .= $parentPlugin->attributes->getNamedItem("name")->nodeValue;
                    }
                    //echo($pId." : ". $node->attributes->getNamedItem("name")->nodeValue . " (".$messId.")<br>");
                    if(!is_array($actions[$pId])) $actions[$pId] = array();
                    $actionName = $node->attributes->getNamedItem("name")->nodeValue;
                    $messId = $node->attributes->getNamedItem("label")->nodeValue;
                    $actions[$pId][$actionName] = array( "parameter" => $actionName , "label" => XMLWriter::replaceXmlKeywords($messId));

                }
                foreach($actions as $actPid => $actionGroup){
                    ksort($actionGroup, SORT_STRING);
                    $actions[$actPid] = array();
                    foreach($actionGroup as $k => $v){
                        $actions[$actPid][] = $v;
                    }
                }
                HTMLWriter::charsetHeader("application/json");
                echo json_encode(array("LIST" => $actions, "HAS_GROUPS" => true));
                break;
            case "parameters_to_form_definitions" :

                $data = json_decode(Utils::decodeSecureMagic($httpVars["json_parameters"]), true);
                XMLWriter::header("standard_form");
                foreach($data as $repoScope => $pluginsData){
                    echo("<repoScope id='$repoScope'>");
                    foreach($pluginsData as $pluginId => $paramData){
                        foreach($paramData as $paramId => $paramValue){
                            $query = "//param[@name='$paramId']|//global_param[@name='$paramId']";
                            $nodes = PluginsService::getInstance()->searchAllManifests($query, "node", false, true, true);
                            if(!count($nodes)) continue;
                            $n = $nodes[0];
                            if($n->attributes->getNamedItem("group") != null){
                                $n->attributes->getNamedItem("group")->nodeValue = "$pluginId";
                            }else{
                                $n->appendChild($n->ownerDocument->createAttribute("group"));
                                $n->attributes->getNamedItem("group")->nodeValue = "$pluginId";
                            }
                            if(is_bool($paramValue)) $paramValue = ($paramValue ? "true" : "false");
                            if($n->attributes->getNamedItem("default") != null){
                                $n->attributes->getNamedItem("default")->nodeValue = $paramValue;
                            }else{
                                $n->appendChild($n->ownerDocument->createAttribute("default"));
                                $n->attributes->getNamedItem("default")->nodeValue = $paramValue;
                            }
                            echo(XMLWriter::replaceXmlKeywords($n->ownerDocument->saveXML($n)));
                        }
                    }
                    echo("</repoScope>");
                }
                XMLWriter::close("standard_form");
                break;

            default:
                break;
        }
    }

    function parseSpecificContributions(&$contribNode){
        parent::parseSpecificContributions($contribNode);
        if($contribNode->nodeName != "actions") return;
        $currentUserIsGroupAdmin = (AuthService::getLoggedUser() != null && AuthService::getLoggedUser()->getGroupPath() != "/");
        if(!$currentUserIsGroupAdmin) return;
        $actionXpath=new \DOMXPath($contribNode->ownerDocument);
        $publicUrlNodeList = $actionXpath->query('action[@name="create_repository"]/subMenu', $contribNode);
        if($publicUrlNodeList->length){
            $publicUrlNode = $publicUrlNodeList->item(0);
            $publicUrlNode->parentNode->removeChild($publicUrlNode);
        }
    }

	function switchAction($action, $httpVars, $fileVars){
		if(!isSet($this->actions[$action])) return;
		parent::accessPreprocess($action, $httpVars, $fileVars);
		$loggedUser = AuthService::getLoggedUser();
		if(AuthService::usersEnabled() && !$loggedUser->isAdmin()) return ;

		if($action == "edit"){
			if(isSet($httpVars["sub_action"])){
				$action = $httpVars["sub_action"];
			}
		}
		$mess = ConfService::getMessages();
        $currentUserIsGroupAdmin = (AuthService::getLoggedUser() != null && AuthService::getLoggedUser()->getGroupPath() != "/");

		switch($action)
		{
			//------------------------------------
			//	BASIC LISTING
			//------------------------------------
			case "ls":

                $rootNodes = array(
                    "data" => array(
                        "LABEL" => $mess["boaconf.110"],
                        "ICON" => "user.png",
                        "DESCRIPTION" => "Day-to-day administration of the application : who accesses to what, create roles, etc.",
                        "CHILDREN" => array(
                            "repositories" => array(
                                "LABEL" => $mess["boaconf.3"],
                                "DESCRIPTION" => "Create and delete workspaces, add features to them using meta sources.",
                                "ICON" => "hdd_external_unmount.png",
                                "LIST" => "listRepositories"),
                            "users" => array(
                                "LABEL" => $mess["boaconf.2"],
                                "DESCRIPTION" => "Manage users and groups",
                                "ICON" => "users-folder.png",
                                "LIST" => "listUsers"
                            ),
                            "roles" => array(
                                "LABEL" => $mess["boaconf.69"],
                                "DESCRIPTION" => "Define profiles that can be applied at once to whole bunch of users.",
                                "ICON" => "user-acl.png",
                                "LIST" => "listRoles"),
                        )
                    ),
                    "config" => array(
                        "LABEL" => $mess["boaconf.109"],
                        "ICON" => "preferences_desktop.png",
                        "DESCRIPTION" => "Global configurations of the application core and of each plugin. Enable/disable plugins",
                        "CHILDREN" => array(
                            "core"	   	   => array(
                                "LABEL" => $mess["boaconf.98"],
                                "DESCRIPTION" => "Core application parameters",
                                "ICON" => "preferences_desktop.png",
                                "LIST" => "listPlugins"),
                            "core_plugins" => array(
                                "LABEL" => "Core Plugins",
                                "DESCRIPTION" => "Enable/disable core plugins (auth, conf, mail, etc), check if they are correctly working. Configuration of these plugins are generally done through the Main Options",
                                "ICON" => "folder_development.png",
                                "LIST" => "listPlugins"),
                            "plugins"	   => array(
                                "LABEL" => $mess["boaconf.99"],
                                "DESCRIPTION" => "Enable/disable additional feature-oriented plugins, check if they are correctly working, set up global parameters of the plugins.",
                                "ICON" => "folder_development.png",
                                "LIST" => "listPlugins")
                        )
                    ),
                    "admin" => array(
                        "LABEL" => $mess["boaconf.111"],
                        "ICON" => "toggle_log.png",
                        "DESCRIPTION" => "Administrator tasks to monitor the application state.",
                        "CHILDREN" => array(
                            "logs" => array(
                                "LABEL" => $mess["boaconf.4"],
                                "DESCRIPTION" => "Monitor all activities happening on the server",
                                "ICON" => "toggle_log.png",
                                "LIST" => "listLogFiles"),
                            "files" => array(
                                "LABEL" => $mess["shared.3"],
                                "DESCRIPTION" => "Monitor all files shared as public links by every users",
                                "ICON" => "html.png",
                                "LIST" => "listSharedFiles"),
                            "diagnostic" => array(
                                "LABEL" => $mess["boaconf.5"],
                                "DESCRIPTION" => "Read the system generated start-up diagnostic",
                                "ICON" => "susehelpcenter.png", "LIST" => "printDiagnostic")
                        )
                    ),
                    "developer" => array(
                        "LABEL" => "Developer Resources",
                        "ICON" => "applications_engineering.png",
                        "DESCRIPTION" => "Generated documentations for developers",
                        "CHILDREN" => array(
                            "actions" => array(
                                "LABEL" => "Actions API",
                                "DESCRIPTION" => "List all actions contributed by all plugins and visualize their input parameters",
                                "ICON" => "book.png",
                                "LIST" => "listActions"),
                            "hooks" => array(
                                "LABEL" => "Hooks Definitions",
                                "DESCRIPTION" => "List all hooks triggered in the application, their documentation, where there are triggered and which plugin listen to them.",
                                "ICON" => "book.png",
                                "LIST" => "listHooks")
                        )
                    )
                );
                if($currentUserIsGroupAdmin){
                    unset($rootNodes["config"]);
                    unset($rootNodes["admin"]);
                    unset($rootNodes["developer"]);
                }
                Controller::applyHook("conf.list_config_nodes", array(&$rootNodes));
				$dir = trim(Utils::decodeSecureMagic((isset($httpVars["dir"])?$httpVars["dir"]:"")), " /");
                if($dir != ""){
                    $hash = null;
                    if(strstr(urldecode($dir), "#") !== false){
                        list($dir, $hash) = explode("#", urldecode($dir));
                    }
    				$splits = explode("/", $dir);
                    $root = array_shift($splits);
                    if(count($splits)){
                        $child = $splits[0];
                        if(isSet($rootNodes[$root]["CHILDREN"][$child])){
                            $callback = $rootNodes[$root]["CHILDREN"][$child]["LIST"];
                            if(is_string($callback) && method_exists($this, $callback)){
                                XMLWriter::header();
                                call_user_func(array($this, $callback), implode("/", $splits), $root, $hash);
                                XMLWriter::close();
                            }else if(is_array($callback)){
                                call_user_func($callback, implode("/", $splits), $root, $hash);
                            }
                            return;
                        }
                    }else{
                        $parentName = "/".$root."/";
                        $nodes = $rootNodes[$root]["CHILDREN"];
                    }
				}else{
                    $parentName = "/";
                    $nodes = $rootNodes;
                }
                if(isSet($httpVars["file"])){
                    $parentName = $httpVars["dir"]."/";
                    $nodes = array(basename($httpVars["file"]) =>  array("LABEL" => basename($httpVars["file"])));
                }
                if(isSet($nodes)){
                    XMLWriter::header();
                    if(!isSet($httpVars["file"])) XMLWriter::sendFilesListComponentConfig('<columns switchDisplayMode="detail"><column messageId="boaconf.1" attributeName="APP_label" sortType="String"/><column messageId="boaconf.102" attributeName="description" sortType="String"/></columns>');
                    foreach ($nodes as $key => $data){
                        print '<tree text="'.Utils::xmlEntities($data["LABEL"]).'" description="'
                            . Utils::xmlEntities(isset($data["DESCRIPTION"]) ? $data["DESCRIPTION"] : '')
                            . '" icon="'.$data["ICON"].'" filename="'.$parentName.$key.'"/>';
                    }
                    XMLWriter::close();

                }

			break;

			case "stat" :

				header("Content-type:application/json");
				print '{"mode":true}';
				return;

			break;

            case "create_group":

                if(isSet($httpVars["group_path"])){
                    $basePath = dirname($httpVars["group_path"]);
                    if(empty($basePath)) $basePath = "/";
                    $gName = Utils::sanitize(Utils::decodeSecureMagic(basename($httpVars["group_path"])), APP_SANITIZE_ALPHANUM);
                }else{
                    $basePath = substr($httpVars["dir"], strlen("/data/users"));
                    $gName    = Utils::sanitize(SystemTextEncoding::magicDequote($httpVars["group_name"]), APP_SANITIZE_ALPHANUM);
                }
                $gLabel   = Utils::decodeSecureMagic($httpVars["group_label"]);
                AuthService::createGroup($basePath, $gName, $gLabel);
                XMLWriter::header();
                XMLWriter::reloadDataNode();
                XMLWriter::close();

            break;

            case "create_role":
				$roleId = Utils::sanitize(SystemTextEncoding::magicDequote($httpVars["role_id"]), APP_SANITIZE_HTML_STRICT);
				if(!strlen($roleId)){
					throw new \Exception($mess[349]);
				}
				if(AuthService::getRole($roleId) !== false){
					throw new \Exception($mess["boaconf.65"]);
				}
                $r = new Role($roleId);
                if(AuthService::getLoggedUser()!=null && AuthService::getLoggedUser()->getGroupPath()!=null){
                    $r->setGroupPath(AuthService::getLoggedUser()->getGroupPath());
                }
				AuthService::updateRole($r);
				XMLWriter::header();
				XMLWriter::sendMessage($mess["boaconf.66"], null);
				XMLWriter::reloadDataNode("", $httpVars["role_id"]);
				XMLWriter::close();
			break;

			case "edit_role" :
				$roleId = SystemTextEncoding::magicDequote($httpVars["role_id"]);
                $roleGroup = false;
                if(strpos($roleId, "APP_GRP_") === 0){
                    $groupPath = AuthService::filterBaseGroup(substr($roleId, strlen("APP_GRP_/")));
                    $groups = AuthService::listChildrenGroups(dirname($groupPath));
                    $key = "/".basename($groupPath);
                    if(!array_key_exists($key, $groups)){
                        throw new \Exception("Cannot find group with this id!");
                    }
                    $roleId = "APP_GRP_".$groupPath;
                    $groupLabel = $groups[$key];
                    $roleGroup = true;
                }

                if(strpos($roleId, "APP_USR_") === 0){
                    $usrId = str_replace("APP_USR_/", "", $roleId);
                    $userObject = ConfService::getConfStorageImpl()->createUserObject($usrId);
                    $role = $userObject->personalRole;
                }else{
                    $role = AuthService::getRole($roleId, $roleGroup);
                }

				if($role === false) {
                    throw new \Exception("Cant find role! ");
				}
                if(isSet($httpVars["format"]) && $httpVars["format"] == "json"){
                    HTMLWriter::charsetHeader("application/json");
                    $roleData = $role->getDataArray();
                    $repos = ConfService::getAccessibleRepositories($userObject, true, true, ($userObject == null ? true:false));
                    $data = array(
                        "ROLE" => $roleData,
                        "ALL"  => array(
                            "REPOSITORIES" => $repos
                        )
                    );
                    if(isSet($userObject)){
                        $data["USER"] = array();
                        $data["USER"]["LOCK"] = $userObject->getLock();
                        $data["USER"]["DEFAULT_REPOSITORY"] = $userObject->getPref("force_default_repository");
                        $data["USER"]["PROFILE"] = $userObject->getProfile();
                        $data["ALL"]["PROFILES"] = array("standard|Standard","admin|Administrator","shared|Shared","guest|Guest");
                        $data["USER"]["ROLES"] = array_keys($userObject->getRoles());
                        $data["ALL"]["ROLES"] = array_keys(AuthService::getRolesList(array(), true));
                        if(isSet($userObject->parentRole)){
                            $data["PARENT_ROLE"] = $userObject->parentRole->getDataArray();
                        }
                    }else if(isSet($groupPath)){
                        $data["GROUP"] = array("PATH" => $groupPath, "LABEL" => $groupLabel);
                    }

                    $scope = "role";
                    if($roleGroup) $scope = "group";
                    else if(isSet($userObject)) $scope = "user";
                    $data["SCOPE_PARAMS"] = array();
                    $nodes = PluginsService::getInstance()->searchAllManifests("//param[contains(@scope,'".$scope."')]|//global_param[contains(@scope,'".$scope."')]", "node", false, true, true);
                    foreach($nodes as $node){
                        $pId = $node->parentNode->parentNode->attributes->getNamedItem("id")->nodeValue;
                        $origName = $node->attributes->getNamedItem("name")->nodeValue;
                        $node->attributes->getNamedItem("name")->nodeValue = "APP_REPO_SCOPE_ALL/".$pId."/".$origName;
                        $nArr = array();
                        foreach($node->attributes as $attrib){
                            $nArr[$attrib->nodeName] = XMLWriter::replaceXmlKeywords($attrib->nodeValue);
                        }
                        $data["SCOPE_PARAMS"][] = $nArr;
                    }

                    echo json_encode($data);
                }
			break;

            case "post_json_role" :

                $roleId = SystemTextEncoding::magicDequote($httpVars["role_id"]);
                $roleGroup = false;
                if(strpos($roleId, "APP_GRP_") === 0){
                    $groupPath = AuthService::filterBaseGroup(substr($roleId, strlen("APP_GRP_")));
                    $roleId = "APP_GRP_".$groupPath;
                    $groups = AuthService::listChildrenGroups(dirname($groupPath));
                    $key = "/".basename($groupPath);
                    if(!array_key_exists($key, $groups)){
                        throw new \Exception("Cannot find group with this id!");
                    }
                    $groupLabel = $groups[$key];
                    $roleGroup = true;
                }
                if(strpos($roleId, "APP_USR_") === 0){
                    $usrId = str_replace("APP_USR_/", "", $roleId);
                    $userObject = ConfService::getConfStorageImpl()->createUserObject($usrId);
                    $originalRole = $userObject->personalRole;
                }else{
                    // second param = create if not exists.
                    $originalRole = AuthService::getRole($roleId, $roleGroup);
                }
                if($originalRole === false) {
                    throw new \Exception("Cant find role! ");
                }

                $jsonData = Utils::decodeSecureMagic($httpVars["json_data"]);
                $data = json_decode($jsonData, true);
                $roleData = $data["ROLE"];
                $forms = $data["FORMS"];
                $binariesContext = array();
                if(isset($userObject)){
                    $binariesContext = array("USER" => $userObject->getId());
                }
                foreach($forms as $repoScope => $plugData){
                    foreach($plugData as $plugId => $formsData){
                        $parsed = array();
                        Utils::parseStandardFormParameters(
                            $formsData,
                            $parsed,
                            ($userObject!=null?$usrId:null),
                            "ROLE_PARAM_",
                            $binariesContext
                        );
                        $roleData["PARAMETERS"][$repoScope][$plugId] = $parsed;
                    }
                }
                if(isSet($userObject) && isSet($data["USER"]) && isSet($data["USER"]["PROFILE"])){
                    $userObject->setAdmin(($data["USER"]["PROFILE"] == "admin"));
                    $userObject->setProfile($data["USER"]["PROFILE"]);
                }
                if(isSet($data["GROUP_LABEL"]) && isSet($groupLabel) && $groupLabel != $data["GROUP_LABEL"]){
                    ConfService::getConfStorageImpl()->relabelGroup($groupPath, $data["GROUP_LABEL"]);
                }

                $output = array();
                try{
                    $originalRole->bunchUpdate($roleData);
                    if(isSet($userObject)){
                        $userObject->personalRole = $originalRole;
                        $userObject->save("superuser");
                        //AuthService::updateRole($originalRole, $userObject);
                    }else{
                        AuthService::updateRole($originalRole);
                    }
                    $output = array("ROLE" => $originalRole->getDataArray(), "SUCCESS" => true);
                }catch (\Exception $e){
                    $output = array("ERROR" => $e->getMessage());
                }
                HTMLWriter::charsetHeader("application/json");
                echo(json_encode($output));

            break;


            case "user_set_lock" :

                $userId = Utils::decodeSecureMagic($httpVars["user_id"]);
                $lock = ($httpVars["lock"] == "true" ? true : false);
                $lockType = $httpVars["lock_type"];
                if(AuthService::userExists($userId)){
                    $userObject = ConfService::getConfStorageImpl()->createUserObject($userId);
                    if($lock){
                        $userObject->setLock($lockType);
                    }else{
                        $userObject->removeLock();
                    }
                    $userObject->save("superuser");
                }

            break;

			case "create_user" :

				if(!isset($httpVars["new_user_login"]) || $httpVars["new_user_login"] == "" ||!isset($httpVars["new_user_pwd"]) || $httpVars["new_user_pwd"] == "")
				{
					XMLWriter::header();
					XMLWriter::sendMessage(null, $mess["boaconf.61"]);
					XMLWriter::close();
					return;
				}
				$new_user_login = Utils::sanitize(SystemTextEncoding::magicDequote($httpVars["new_user_login"]), APP_SANITIZE_EMAILCHARS);
				if(AuthService::userExists($new_user_login, "w") || AuthService::isReservedUserId($new_user_login))
				{
					XMLWriter::header();
					XMLWriter::sendMessage(null, $mess["boaconf.43"]);
					XMLWriter::close();
					return;
				}

                AuthService::createUser($new_user_login, $httpVars["new_user_pwd"]);
                $confStorage = ConfService::getConfStorageImpl();
				$newUser = $confStorage->createUserObject($new_user_login);
                $basePath = AuthService::getLoggedUser()->getGroupPath();
                if(empty ($basePath)) $basePath = "/";
                if(!empty($httpVars["group_path"])){
                    $newUser->setGroupPath($basePath.ltrim($httpVars["group_path"], "/"));
                }else{
                    $newUser->setGroupPath($basePath);
                }

				$newUser->save("superuser");
				XMLWriter::header();
				XMLWriter::sendMessage($mess["boaconf.44"], null);
				XMLWriter::reloadDataNode("", $new_user_login);
				XMLWriter::close();

			break;

			case "change_admin_right" :
				$userId = $httpVars["user_id"];
				if(!AuthService::userExists($userId)){
					throw new \Exception("Invalid user id!");
				}
				$confStorage = ConfService::getConfStorageImpl();
				$user = $confStorage->createUserObject($userId);
				$user->setAdmin(($httpVars["right_value"]=="1"?true:false));
				$user->save("superuser");
				XMLWriter::header();
				XMLWriter::sendMessage($mess["boaconf.45"].$httpVars["user_id"], null);
				XMLWriter::reloadDataNode();
				XMLWriter::close();

			break;

			case "user_update_right" :
				if(!isSet($httpVars["user_id"])
					|| !isSet($httpVars["repository_id"])
					|| !isSet($httpVars["right"])
					|| !AuthService::userExists($httpVars["user_id"]))
				{
					XMLWriter::header();
					XMLWriter::sendMessage(null, $mess["boaconf.61"]);
					print("<update_checkboxes user_id=\"".$httpVars["user_id"]."\" repository_id=\"".$httpVars["repository_id"]."\" read=\"old\" write=\"old\"/>");
					XMLWriter::close();
					return;
				}
				$confStorage = ConfService::getConfStorageImpl();
				$user = $confStorage->createUserObject($httpVars["user_id"]);
				$user->personalRole->setAcl(Utils::sanitize($httpVars["repository_id"], APP_SANITIZE_ALPHANUM), Utils::sanitize($httpVars["right"], APP_SANITIZE_ALPHANUM));
				$user->save();
				$loggedUser = AuthService::getLoggedUser();
				if($loggedUser->getId() == $user->getId()){
					AuthService::updateUser($user);
				}
				XMLWriter::header();
				XMLWriter::sendMessage($mess["boaconf.46"].$httpVars["user_id"], null);
				print("<update_checkboxes user_id=\"".$httpVars["user_id"]."\" repository_id=\"".$httpVars["repository_id"]."\" read=\"".$user->canRead($httpVars["repository_id"])."\" write=\"".$user->canWrite($httpVars["repository_id"])."\"/>");
				XMLWriter::reloadRepositoryList();
				XMLWriter::close();
				return ;
			break;

            case "user_update_group":

                $userSelection = new UserSelection();
                $userSelection->initFromHttpVars($httpVars);
                $dir = $httpVars["dir"];
                $dest = $httpVars["dest"];
                if(isSet($httpVars["group_path"])){
                    // API Case
                    $groupPath = $httpVars["group_path"];
                }else{
                    if(strpos($dir, "/data/users",0)!==0 || strpos($dest, "/data/users",0)!==0){
                        break;
                    }
                    $groupPath = substr($dest, strlen("/data/users"));
                }

                $confStorage = ConfService::getConfStorageImpl();

                foreach($userSelection->getFiles() as $selectedUser){
                    $userId = basename($selectedUser);
                    if(!AuthService::userExists($userId)){
                        continue;
                    }
                    $user = $confStorage->createUserObject($userId);
                    $basePath = (AuthService::getLoggedUser()!=null ? AuthService::getLoggedUser()->getGroupPath(): "/");
                    if(empty ($basePath)) $basePath = "/";
                    if(!empty($groupPath)){
                        $user->setGroupPath(rtrim($basePath, "/")."/".ltrim($groupPath, "/"), true);
                    }else{
                        $user->setGroupPath($basePath, true);
                    }
                    $user->save("superuser");
                }
                XMLWriter::header();
                XMLWriter::reloadDataNode();
                XMLWriter::reloadDataNode($dest, $userId);
                XMLWriter::close();

                break;

			case "user_add_role" :
			case "user_delete_role":

				if(!isSet($httpVars["user_id"]) || !isSet($httpVars["role_id"]) || !AuthService::userExists($httpVars["user_id"]) || !AuthService::getRole($httpVars["role_id"])){
					throw new \Exception($mess["boaconf.61"]);
				}
				if($action == "user_add_role"){
					$act = "add";
					$messId = "73";
				}else{
					$act = "remove";
					$messId = "74";
				}
				$this->updateUserRole($httpVars["user_id"], $httpVars["role_id"], $act);
				XMLWriter::header();
				XMLWriter::sendMessage($mess["boaconf.".$messId].$httpVars["user_id"], null);
				XMLWriter::close();
				return ;

			break;

			case "user_update_role" :

				$confStorage = ConfService::getConfStorageImpl();
				$selection = new UserSelection();
				$selection->initFromHttpVars($httpVars);
				$files = $selection->getFiles();
				$detectedRoles = array();

				if(isSet($httpVars["role_id"]) && isset($httpVars["update_role_action"])){
					$update = $httpVars["update_role_action"];
					$roleId = $httpVars["role_id"];
					if(AuthService::getRole($roleId) === false){
						throw new \Exception("Invalid role id");
					}
				}
				foreach ($files as $index => $file){
					$userId = basename($file);
					if(isSet($update)){
						$userObject = $this->updateUserRole($userId, $roleId, $update);
					}else{
						$userObject = $confStorage->createUserObject($userId);
					}
					if($userObject->hasParent()){
						unset($files[$index]);
						continue;
					}
					$userRoles = $userObject->getRoles();
					foreach ($userRoles as $roleIndex => $bool){
						if(!isSet($detectedRoles[$roleIndex])) $detectedRoles[$roleIndex] = 0;
						if($bool === true) $detectedRoles[$roleIndex] ++;
					}
				}
				$count = count($files);
				XMLWriter::header("admin_data");
				print("<user><roles>");
				foreach ($detectedRoles as $roleId => $roleCount){
					if($roleCount < $count) continue;
					print("<role id=\"$roleId\"/>");
				}
				print("</roles></user>");
				print("<roles>");
				foreach (AuthService::getRolesList(array(), !$this->listSpecialRoles) as $roleId => $roleObject){
					print("<role id=\"$roleId\"/>");
				}
				print("</roles>");
				XMLWriter::close("admin_data");

			break;

			case "save_custom_user_params" :
				$userId = $httpVars["user_id"];
				if($userId == $loggedUser->getId()){
					$user = $loggedUser;
				}else{
					$confStorage = ConfService::getConfStorageImpl();
					$user = $confStorage->createUserObject($userId);
				}
				$custom = $user->getPref("CUSTOM_PARAMS");
				if(!is_array($custom)) $custom = array();

				$options = $custom;
				$this->parseParameters($httpVars, $options, $userId);
				$custom = $options;
				$user->setPref("CUSTOM_PARAMS", $custom);
				$user->save();

				if($loggedUser->getId() == $user->getId()){
					AuthService::updateUser($user);
				}
				XMLWriter::header();
				XMLWriter::sendMessage($mess["boaconf.47"].$httpVars["user_id"], null);
				XMLWriter::close();

			break;

			case "save_repository_user_params" :
				$userId = $httpVars["user_id"];
				if($userId == $loggedUser->getId()){
					$user = $loggedUser;
				}else{
					$confStorage = ConfService::getConfStorageImpl();
					$user = $confStorage->createUserObject($userId);
				}
				$wallet = $user->getPref("APP_WALLET");
				if(!is_array($wallet)) $wallet = array();
				$repoID = $httpVars["repository_id"];
				if(!array_key_exists($repoID, $wallet)){
					$wallet[$repoID] = array();
				}
				$options = $wallet[$repoID];
				$this->parseParameters($httpVars, $options, $userId);
				$wallet[$repoID] = $options;
				$user->setPref("APP_WALLET", $wallet);
				$user->save();

				if($loggedUser->getId() == $user->getId()){
					AuthService::updateUser($user);
				}
				XMLWriter::header();
				XMLWriter::sendMessage($mess["boaconf.47"].$httpVars["user_id"], null);
				XMLWriter::close();

			break;

			case "update_user_pwd" :
				if(!isSet($httpVars["user_id"]) || !isSet($httpVars["user_pwd"]) || !AuthService::userExists($httpVars["user_id"]) || trim($httpVars["user_pwd"]) == "")
				{
					XMLWriter::header();
					XMLWriter::sendMessage(null, $mess["boaconf.61"]);
					XMLWriter::close();
					return;
				}
				$res = AuthService::updatePassword($httpVars["user_id"], $httpVars["user_pwd"]);
				XMLWriter::header();
				if($res === true)
				{
					XMLWriter::sendMessage($mess["boaconf.48"].$httpVars["user_id"], null);
				}
				else
				{
					XMLWriter::sendMessage(null, $mess["boaconf.49"]." : $res");
				}
				XMLWriter::close();

			break;

            case "save_user_preference":

                if(!isSet($httpVars["user_id"]) || !AuthService::userExists($httpVars["user_id"]) ){
                    throw new \Exception($mess["boaconf.61"]);
                }
                $userId = $httpVars["user_id"];
                if($userId == $loggedUser->getId()){
                    $userObject = $loggedUser;
                }else{
                    $confStorage = ConfService::getConfStorageImpl();
                    $userObject = $confStorage->createUserObject($userId);
                }
                $i = 0;
                while(isSet($httpVars["pref_name_".$i]) && isSet($httpVars["pref_value_".$i]))
                {
                    $prefName = Utils::sanitize($httpVars["pref_name_".$i], APP_SANITIZE_ALPHANUM);
                    $prefValue = Utils::sanitize(SystemTextEncoding::magicDequote(($httpVars["pref_value_".$i])));
                    if($prefName == "password") continue;
                    if($prefName != "pending_folder" && $userObject == null){
                        $i++;
                        continue;
                    }
                    $userObject->setPref($prefName, $prefValue);
                    $userObject->save("user");
                    $i++;
                }
                XMLWriter::header();
                XMLWriter::sendMessage("Succesfully saved user preference", null);
                XMLWriter::close();

            break;

			case  "get_drivers_definition":

				XMLWriter::header("drivers", array("allowed" => $currentUserIsGroupAdmin ? "false" : "true"));
				print(XMLWriter::replaceXmlKeywords(ConfService::availableDriversToXML("param", "", true)));
				XMLWriter::close("drivers");


			break;

			case  "get_templates_definition":

				XMLWriter::header("repository_templates");
				$repositories = ConfService::getRepositoriesList("all");
				foreach ($repositories as $repo){
					if(!$repo->isTemplate) continue;
					$repoId = $repo->getId();
					$repoLabel = $repo->getDisplay();
					$repoType = $repo->getAccessType();
					print("<template repository_id=\"$repoId\" repository_label=\"$repoLabel\" repository_type=\"$repoType\">");
					foreach($repo->getOptionsDefined() as $optionName){
						print("<option name=\"$optionName\"/>");
					}
					print("</template>");
				}
				XMLWriter::close("repository_templates");


			break;

			case "create_repository" :

                $repDef = $httpVars;
                $isTemplate = isSet($httpVars["sf_checkboxes_active"]);
                unset($repDef["get_action"]);
                unset($repDef["sf_checkboxes_active"]);
                if(isSet($httpVars["json_data"])){
                    $options = json_decode($httpVars["json_data"], true);
                }else{
                    $options = array();
                    $this->parseParameters($repDef, $options, null, true);
                }
				if(count($options)){
					$repDef["DRIVER_OPTIONS"] = $options;
                    unset($repDef["DRIVER_OPTIONS"]["APP_GROUP_PATH_PARAMETER"]);
				}
				if(strstr($repDef["DRIVER"], "template_") !== false){
					$templateId = substr($repDef["DRIVER"], 14);
					$templateRepo = ConfService::getRepositoryById($templateId);
					$newRep = $templateRepo->createTemplateChild($repDef["DISPLAY"], $repDef["DRIVER_OPTIONS"]);
				}else{
                    if($currentUserIsGroupAdmin){
                        throw new \Exception("You are not allowed to create a repository from a driver. Use a template instead.");
                    }
                    $pServ = PluginsService::getInstance();
                    $driver = $pServ->getPluginByTypeName("access", $repDef["DRIVER"]);

					$newRep = ConfService::createRepositoryFromArray(0, $repDef);
                    $testFile = $driver->getBaseDir()."/test.".$newRep->getAccessType()."Access.php";
					if(!$isTemplate && is_file($testFile))
					{
					    //chdir(APP_TESTS_FOLDER."/plugins");
						$className = $newRep->getAccessType()."AccessTest";
						if (!class_exists($className))
							include($testFile);
						$class = new $className();
						$result = $class->doRepositoryTest($newRep);
						if(!$result){
							XMLWriter::header();
							XMLWriter::sendMessage(null, $class->failedInfo);
							XMLWriter::close();
							return;
						}
					}
                    // Apply default metasource if any
                    if($driver != null && $driver->getConfigs()!=null ){
                        $confs = $driver->getConfigs();
                        if(!empty($confs["DEFAULT_METASOURCES"])){
                            $metaIds = Utils::parseCSL($confs["DEFAULT_METASOURCES"]);
                            $metaSourceOptions = array();
                            foreach($metaIds as $metaID){
                                $metaPlug = $pServ->getPluginById($metaID);
                                if($metaPlug == null) continue;
                                $pNodes = $metaPlug->getManifestRawContent("//param[@default]", "nodes");
                                $defaultParams = array();
                                foreach($pNodes as $domNode){
                                    $defaultParams[$domNode->getAttribute("name")] = $domNode->getAttribute("default");
                                }
                                $metaSourceOptions[$metaID] = $defaultParams;
                            }
                            $newRep->addOption("META_SOURCES", $metaSourceOptions);
                        }
                    }
				}

                if ($this->repositoryExists($newRep->getDisplay()))
                {
					XMLWriter::header();
					XMLWriter::sendMessage(null, $mess["boaconf.50"]);
					XMLWriter::close();
					return;
                }
                if($isTemplate){
                    $newRep->isTemplate = true;
                }
                if($currentUserIsGroupAdmin){
                    $newRep->setGroupPath(AuthService::getLoggedUser()->getGroupPath());
                }else if(!empty($options["APP_GROUP_PATH_PARAMETER"])){
                    $basePath = "/";
                    if(AuthService::getLoggedUser()!=null && AuthService::getLoggedUser()->getGroupPath()!=null){
                        $basePath = AuthService::getLoggedUser()->getGroupPath();
                    }
                    $value =  Utils::securePath(rtrim($basePath, "/")."/".ltrim($options["APP_GROUP_PATH_PARAMETER"], "/"));
                    $newRep->setGroupPath($value);
                }

				$res = ConfService::addRepository($newRep);
				XMLWriter::header();
				if($res == -1){
					XMLWriter::sendMessage(null, $mess["boaconf.51"]);
				}else{
					$loggedUser = AuthService::getLoggedUser();
					$loggedUser->personalRole->setAcl($newRep->getUniqueId(), "rw");
                    $loggedUser->recomputeMergedRole();
					$loggedUser->save("superuser");
					AuthService::updateUser($loggedUser);

					XMLWriter::sendMessage($mess["boaconf.52"], null);
					XMLWriter::reloadDataNode("", $newRep->getUniqueId());
					XMLWriter::reloadRepositoryList();
				}
				XMLWriter::close();



			break;

			case "edit_repository" :
				$repId = $httpVars["repository_id"];
                $repository = ConfService::getRepositoryById($repId);
                if($repository == null){
                    throw new \Exception("Cannot find repository with id $repId");
                }
                if(!AuthService::canAdministrate($repository)){
                    throw new \Exception("You are not allowed to edit this repository!");
                }
				$pServ = PluginsService::getInstance();
				$plug = $pServ->getPluginById("access.".$repository->accessType);
				if($plug == null){
					throw new \Exception("Cannot find access driver (".$repository->accessType.") for repository!");
				}
				XMLWriter::header("admin_data");
				$slug = $repository->getSlug();
				if($slug == "" && $repository->isWriteable()){
					$repository->setSlug();
					ConfService::replaceRepository($repId, $repository);
				}
                if(AuthService::getLoggedUser()!=null && AuthService::getLoggedUser()->getGroupPath() != null){
                    $rgp = $repository->getGroupPath();
                    if($rgp == null) $rgp = "/";
                    if(strlen($rgp) < strlen(AuthService::getLoggedUser()->getGroupPath())) {
                        $repository->setWriteable(false);
                    }
                }
				$nested = array();
				print("<repository index=\"$repId\"");
				foreach ($repository as $name => $option){
					if(strstr($name, " ")>-1) continue;
					if(!is_array($option)){
						if(is_bool($option)){
							$option = ($option?"true":"false");
						}
						print(" $name=\"".SystemTextEncoding::toUTF8(Utils::xmlEntities($option))."\" ");
					}else if(is_array($option)){
						$nested[] = $option;
					}
				}
				if(count($nested)){
					print(">");
					foreach ($nested as $option){
						foreach ($option as $key => $optValue){
							if(is_array($optValue) && count($optValue)){
								print("<param name=\"$key\"><![CDATA[".json_encode($optValue)."]]></param>");
							}else{
								if(is_bool($optValue)){
									$optValue = ($optValue?"true":"false");
								}
								print("<param name=\"$key\" value=\"$optValue\"/>");
							}
						}
					}
					// Add SLUG
					if(!$repository->isTemplate) print("<param name=\"APP_SLUG\" value=\"".$repository->getSlug()."\"/>");
                    if($repository->getGroupPath() != null) {
                        $basePath = "/";
                        if(AuthService::getLoggedUser()!=null && AuthService::getLoggedUser()->getGroupPath()!=null){
                            $basePath = AuthService::getLoggedUser()->getGroupPath();
                        }
                        $groupPath = $repository->getGroupPath();
                        if($basePath != "/") $groupPath = substr($repository->getGroupPath(), strlen($basePath));
                        print("<param name=\"APP_GROUP_PATH_PARAMETER\" value=\"".$groupPath."\"/>");
                    }

					print("</repository>");
				}else{
					print("/>");
				}
				if($repository->hasParent()){
					$parent = ConfService::getRepositoryById($repository->getParentId());
					if(isSet($parent) && $parent->isTemplate){
						$parentLabel = $parent->getDisplay();
						$parentType = $parent->getAccessType();
						print("<template repository_id=\"".$repository->getParentId()."\" repository_label=\"$parentLabel\" repository_type=\"$parentType\">");
						foreach($parent->getOptionsDefined() as $parentOptionName){
							print("<option name=\"$parentOptionName\"/>");
						}
						print("</template>");
					}
				}
				$manifest = $plug->getManifestRawContent("server_settings/param");
                $manifest = XMLWriter::replaceXmlKeywords($manifest);
				print("<coredriver name=\"".$repository->accessType."\">$manifest</coredriver>");
				print("<metasources>");
				$metas = $pServ->getPluginsByType("metastore");
				$metas = array_merge($metas, $pServ->getPluginsByType("meta"));
                $metas = array_merge($metas, $pServ->getPluginsByType("index"));
				foreach ($metas as $metaPlug){
					print("<meta id=\"".$metaPlug->getId()."\" label=\"".Utils::xmlEntities($metaPlug->getManifestLabel())."\">");
					$manifest = $metaPlug->getManifestRawContent("server_settings/param");
                    $manifest = XMLWriter::replaceXmlKeywords($manifest);
					print($manifest);
					print("</meta>");
				}
				print("</metasources>");
				XMLWriter::close("admin_data");
				return ;
			break;

			case "edit_repository_label" :
			case "edit_repository_data" :
				$repId = $httpVars["repository_id"];
				$repo = ConfService::getRepositoryById($repId);
				$res = 0;
				if(isSet($httpVars["newLabel"])){
					$newLabel = Utils::decodeSecureMagic($httpVars["newLabel"]);
                    if ($this->repositoryExists($newLabel))
                    {
		     			XMLWriter::header();
			    		XMLWriter::sendMessage(null, $mess["boaconf.50"]);
				    	XMLWriter::close();
					    return;
                    }
					$repo->setDisplay($newLabel);
					$res = ConfService::replaceRepository($repId, $repo);
				}else{
					$options = array();
					$this->parseParameters($httpVars, $options, null, true);
					if(count($options)){
						foreach ($options as $key=>$value) {
							if($key == "APP_SLUG"){
								$repo->setSlug($value);
								continue;
							}elseif($key == "APP_GROUP_PATH_PARAMETER"){
                                $basePath = "/";
                                if(AuthService::getLoggedUser()!=null && AuthService::getLoggedUser()->getGroupPath()!=null){
                                    $basePath = AuthService::getLoggedUser()->getGroupPath();
                                }
                                $value =  Utils::securePath(rtrim($basePath, "/")."/".ltrim($value, "/"));
                                $repo->setGroupPath($value);
                                continue;
                            }
							$repo->addOption($key, $value);
						}
					}
                    if($repo->getOption("DEFAULT_RIGHTS")){
                        $gp = $repo->getGroupPath();
                        if(empty($gp) || $gp == "/"){
                            $defRole = AuthService::getRole("ROOT_ROLE");
                        }else{
                            $defRole = AuthService::getRole("APP_GRP_".$gp, true);
                        }
                        if($defRole !== false){
                            $defRole->setAcl($repId, $repo->getOption("DEFAULT_RIGHTS"));
                            AuthService::updateRole($defRole);
                        }
                    }
					if(is_file(APP_TESTS_FOLDER."/plugins/test.app_".$repo->getAccessType().".php")){
					    chdir(APP_TESTS_FOLDER."/plugins");
						include(APP_TESTS_FOLDER."/plugins/test.app_".$repo->getAccessType().".php");
						$className = "app_".$repo->getAccessType();
						$class = new $className();
						$result = $class->doRepositoryTest($repo);
						if(!$result){
							XMLWriter::header();
							XMLWriter::sendMessage(null, $class->failedInfo);
							XMLWriter::close();
							return;
						}
					}

					ConfService::replaceRepository($repId, $repo);
				}
				XMLWriter::header();
				if($res == -1){
					XMLWriter::sendMessage(null, $mess["boaconf.53"]);
				}else{
					XMLWriter::sendMessage($mess["boaconf.54"], null);
					XMLWriter::reloadDataNode("", (isSet($httpVars["newLabel"])?$repId:false));
					XMLWriter::reloadRepositoryList();
				}
				XMLWriter::close();

			break;

			case "meta_source_add" :
				$repId = $httpVars["repository_id"];
				$repo = ConfService::getRepositoryById($repId);
				if(!is_object($repo)){
					throw new \Exception("Invalid repository id! $repId");
				}
				$metaSourceType = Utils::sanitize($httpVars["new_meta_source"], APP_SANITIZE_ALPHANUM);
                if(isSet($httpVars["json_data"])){
                    $options = json_decode($httpVars["json_data"], true);
                }else{
                    $options = array();
                    $this->parseParameters($httpVars, $options, null, true);
                }
				$repoOptions = $repo->getOption("META_SOURCES");
				if(is_array($repoOptions) && isSet($repoOptions[$metaSourceType])){
					throw new \Exception($mess["boaconf.55"]);
				}
				if(!is_array($repoOptions)){
					$repoOptions = array();
				}
				$repoOptions[$metaSourceType] = $options;
				uksort($repoOptions, array($this,"metaSourceOrderingFunction"));
				$repo->addOption("META_SOURCES", $repoOptions);
				ConfService::replaceRepository($repId, $repo);
				XMLWriter::header();
				XMLWriter::sendMessage($mess["boaconf.56"],null);
				XMLWriter::close();
			break;

			case "meta_source_delete" :

				$repId = $httpVars["repository_id"];
				$repo = ConfService::getRepositoryById($repId);
				if(!is_object($repo)){
					throw new \Exception("Invalid repository id! $repId");
				}
				$metaSourceId = $httpVars["plugId"];
				$repoOptions = $repo->getOption("META_SOURCES");
				if(is_array($repoOptions) && array_key_exists($metaSourceId, $repoOptions)){
					unset($repoOptions[$metaSourceId]);
					uksort($repoOptions, array($this,"metaSourceOrderingFunction"));
					$repo->addOption("META_SOURCES", $repoOptions);
					ConfService::replaceRepository($repId, $repo);
				}
				XMLWriter::header();
				XMLWriter::sendMessage($mess["boaconf.57"],null);
				XMLWriter::close();

			break;

			case "meta_source_edit" :
				$repId = $httpVars["repository_id"];
				$repo = ConfService::getRepositoryById($repId);
				if(!is_object($repo)){
					throw new \Exception("Invalid repository id! $repId");
				}
				$metaSourceId = $httpVars["plugId"];
                $repoOptions = $repo->getOption("META_SOURCES");
                if(!is_array($repoOptions)){
                    $repoOptions = array();
                }
                if(isSet($httpVars["json_data"])){
                    $options = json_decode($httpVars["json_data"], true);
                }else{
                    $options = array();
                    $this->parseParameters($httpVars, $options, null, true);
                }
				$repoOptions[$metaSourceId] = $options;
				uksort($repoOptions, array($this,"metaSourceOrderingFunction"));
				$repo->addOption("META_SOURCES", $repoOptions);
				ConfService::replaceRepository($repId, $repo);
				XMLWriter::header();
				XMLWriter::sendMessage($mess["boaconf.58"],null);
				XMLWriter::close();
			break;


			case "delete" :
                // REST API mapping
                if(isSet($httpVars["data_type"])){
                    switch($httpVars["data_type"]){
                        case "repository":
                            $httpVars["repository_id"] = basename($httpVars["data_id"]);
                            break;
                        case "shared_file":
                            $httpVars["shared_file"] = basename($httpVars["data_id"]);
                            break;
                        case "role":
                            $httpVars["role_id"] = basename($httpVars["data_id"]);
                            break;
                        case "user":
                            $httpVars["user_id"] = basename($httpVars["data_id"]);
                            break;
                        case "group":
                            $httpVars["group"] = "/data/users".$httpVars["data_id"];
                            break;
                        default:
                            break;
                    }
                    unset($httpVars["data_type"]);
                    unset($httpVars["data_id"]);
                }
				if(isSet($httpVars["repository_id"])){
					$repId = $httpVars["repository_id"];
					$res = ConfService::deleteRepository($repId);
					XMLWriter::header();
					if($res == -1){
						XMLWriter::sendMessage(null, $mess["boaconf.51"]);
					}else{
						XMLWriter::sendMessage($mess["boaconf.59"], null);
						XMLWriter::reloadDataNode();
						XMLWriter::reloadRepositoryList();
					}
					XMLWriter::close();
					return;
				}else if(isSet($httpVars["shared_file"])){
					XMLWriter::header();
					$element = basename($httpVars["shared_file"]);
					$dlFolder = ConfService::getCoreConf("PUBLIC_DOWNLOAD_FOLDER");
					$publicletData = $this->loadPublicletData($dlFolder."/".$element.".php");
					unlink($dlFolder."/".$element.".php");
					XMLWriter::sendMessage($mess["shared.13"], null);
					XMLWriter::reloadDataNode();
					XMLWriter::close();
				}else if(isSet($httpVars["role_id"])){
					$roleId = $httpVars["role_id"];
					if(AuthService::getRole($roleId) === false){
						throw new \Exception($mess["boaconf.67"]);
					}
					AuthService::deleteRole($roleId);
					XMLWriter::header();
					XMLWriter::sendMessage($mess["boaconf.68"], null);
					XMLWriter::reloadDataNode();
					XMLWriter::close();
                }else if(isSet($httpVars["group"])){
                    $groupPath = $httpVars["group"];
                    $basePath = substr(dirname($groupPath), strlen("/data/users"));
                    $gName = basename($groupPath);
                    AuthService::deleteGroup($basePath, $gName);
                    XMLWriter::header();
                    XMLWriter::reloadDataNode();
                    XMLWriter::close();
                }else{
					if(!isset($httpVars["user_id"]) || $httpVars["user_id"]==""
						|| AuthService::isReservedUserId($httpVars["user_id"])
						|| $loggedUser->getId() == $httpVars["user_id"])
					{
						XMLWriter::header();
						XMLWriter::sendMessage(null, $mess["boaconf.61"]);
						XMLWriter::close();
					}
					$res = AuthService::deleteUser($httpVars["user_id"]);
					XMLWriter::header();
					XMLWriter::sendMessage($mess["boaconf.60"], null);
					XMLWriter::reloadDataNode();
					XMLWriter::close();

				}
			break;

			case "clear_expired" :

				$deleted = $this->clearExpiredFiles();
				XMLWriter::header();
				if(count($deleted)){
					XMLWriter::sendMessage(sprintf($mess["shared.23"], count($deleted).""), null);
					XMLWriter::reloadDataNode();
				}else{
					XMLWriter::sendMessage($mess["shared.24"], null);
				}
				XMLWriter::close();

			break;

			case "get_plugin_manifest" :
				$plugin = PluginsService::getInstance()->getPluginById($httpVars["plugin_id"]);
				XMLWriter::header("admin_data");

                $fullManifest = $plugin->getManifestRawContent("", "xml");
                $xPath = new \DOMXPath($fullManifest->ownerDocument);
                $addParams = "";
                $pInstNodes = $xPath->query("server_settings/global_param[contains(@type, 'plugin_instance:')]");
                foreach($pInstNodes as $pInstNode){
                    $type = $pInstNode->getAttribute("type");
                    $instType = str_replace("plugin_instance:", "", $type);
                    $fieldName = $pInstNode->getAttribute("name");
                    $pInstNode->setAttribute("type", "group_switch:".$fieldName);
                    $typePlugs = PluginsService::getInstance()->getPluginsByType($instType);
                    foreach($typePlugs as $typePlug){
                        if($typePlug->getId() == "auth.multi") continue;
                        $checkErrorMessage = "";
                        try{
                            $typePlug->performChecks();
                        }catch (\Exception $e){
                            $checkErrorMessage = " (Warning : ".$e->getMessage().")";
                        }
                        $tParams = XMLWriter::replaceXmlKeywords($typePlug->getManifestRawContent("server_settings/param[not(@group_switch_name)]"));
                        $addParams .= '<global_param group_switch_name="'.$fieldName.'" name="instance_name" group_switch_label="'.$typePlug->getManifestLabel().$checkErrorMessage.'" group_switch_value="'.$typePlug->getId().'" default="'.$typePlug->getId().'" type="hidden"/>';
                        $addParams .= str_replace("<param", "<global_param group_switch_name=\"${fieldName}\" group_switch_label=\"".$typePlug->getManifestLabel().$checkErrorMessage."\" group_switch_value=\"".$typePlug->getId()."\" ", $tParams);
                        $addParams .= str_replace("<param", "<global_param", XMLWriter::replaceXmlKeywords($typePlug->getManifestRawContent("server_settings/param[@group_switch_name]")));
                        $addParams .= XMLWriter::replaceXmlKeywords($typePlug->getManifestRawContent("server_settings/global_param"));
                    }
                }
                $allParams = XMLWriter::replaceXmlKeywords($fullManifest->ownerDocument->saveXML($fullManifest));
                $allParams = str_replace('type="plugin_instance:', 'type="group_switch:', $allParams);
                $allParams = str_replace("</server_settings>", $addParams."</server_settings>", $allParams);

                echo($allParams);
				$definitions = $plugin->getConfigsDefinitions();
				$values = $plugin->getConfigs();
                if(!is_array($values)) $values = array();
                echo("<plugin_settings_values>");
                foreach($values as $key => $value){
                    $attribute = true;
                    $type = $definitions[$key]["type"];
                    if($type == "array" && is_array($value)){
                        $value = implode(",", $value);
                    }else if((strpos($type, "group_switch:") === 0 || strpos($type, "plugin_instance:") === 0 ) && is_array($value)){
                        $res = array();
                        $this->flattenKeyValues($res, $value, $key);
                        foreach($res as $newKey => $newVal){
                            echo("<param name=\"$newKey\" value=\"".Utils::xmlEntities($newVal)."\"/>");
                        }
                        continue;
                    }else if($type == "boolean"){
                        $value = ($value === true || $value === "true" || $value == 1?"true":"false");
                    }else if($type == "textarea"){
                        $attribute = false;
                    }
                    if($attribute){
                        echo("<param name=\"$key\" value=\"".Utils::xmlEntities($value)."\"/>");
                    }else{
                        echo("<param name=\"$key\" cdatavalue=\"true\"><![CDATA[".$value."]]></param>");
                    }
                }
                if($plugin->getType() != "core"){
                    echo("<param name=\"APP_PLUGIN_ENABLED\" value=\"".($plugin->isEnabled()?"true":"false")."\"/>");
                }
                echo("</plugin_settings_values>");
                echo("<plugin_doc><![CDATA[<p>".$plugin->getPluginInformationHTML("Charles du Jeu", "https://github.com/boa-project/boa")."</p>");
                if(file_exists($plugin->getBaseDir()."/plugin_doc.html")){
                    echo(file_get_contents($plugin->getBaseDir()."/plugin_doc.html"));
                }
                echo("]]></plugin_doc>");
				XMLWriter::close("admin_data");

			break;

            case "run_plugin_action":

                $options = array();
                $this->parseParameters($httpVars, $options, null, true);
                $pluginId = $httpVars["action_plugin_id"];
                if(isSet($httpVars["button_key"])){
                    $options = $options[$httpVars["button_key"]];
                }
                $plugin = PluginsService::getInstance()->softLoad($pluginId, $options);
                if(method_exists($plugin, $httpVars["action_plugin_method"])){
                    try{
                        $res = call_user_func(array($plugin, $httpVars["action_plugin_method"]), $options);
                    }catch (\Exception $e){
                        echo("ERROR:" . $e->getMessage());
                        break;
                    }
                    echo($res);
                }else{
                    echo 'ERROR: Plugin '.$httpVars["action_plugin_id"].' does not implement '.$httpVars["action_plugin_method"].' method!';
                }

            break;

			case "edit_plugin_options":

				$options = array();
				$this->parseParameters($httpVars, $options, null, true);
                $confStorage = ConfService::getConfStorageImpl();
                $confStorage->savePluginConfig($httpVars["plugin_id"], $options);
				@unlink(APP_PLUGINS_CACHE_FILE);
				@unlink(APP_PLUGINS_REQUIRES_FILE);
				@unlink(APP_PLUGINS_MESSAGES_FILE);
				XMLWriter::header();
				XMLWriter::sendMessage($mess["boaconf.97"], null);
				XMLWriter::reloadDataNode();
				XMLWriter::close();


			break;

			default:
			break;
		}

		return;
	}


	function listPlugins($dir, $root = NULL){
        $dir = "/$dir";
		Logger::logAction("Listing plugins"); // make sure that the logger is started!
		$pServ = PluginsService::getInstance();
		$types = $pServ->getDetectedPlugins();
		$uniqTypes = array("core");
        $coreTypes = array("auth", "conf", "boot", "feed", "log", "mailer", "mq");
        if($dir == "/plugins" || $dir == "/core_plugins"){
            if($dir == "/core_plugins") $uniqTypes = $coreTypes;
            else $uniqTypes = array_diff(array_keys($types), $coreTypes);
			XMLWriter::sendFilesListComponentConfig('<columns switchGridMode="filelist" template_name="conf.plugins_folder">
			<column messageId="boaconf.101" attributeName="APP_label" sortType="String"/>
			</columns>');
			ksort($types);
			foreach( $types as $t => $tPlugs){
				if(!in_array($t, $uniqTypes))continue;
				$meta = array(
					"icon" 		=> "folder_development.png",
					"plugin_id" => $t
				);
				XMLWriter::renderNode("/".$root.$dir."/".$t, ucfirst($t), false, $meta);
			}
		}else if($dir == "/core"){
			XMLWriter::sendFilesListComponentConfig('<columns switchGridMode="filelist" switchDisplayMode="detail"  template_name="conf.plugins">
			<column messageId="boaconf.101" attributeName="APP_label" sortType="String"/>
			<column messageId="boaconf.102" attributeName="plugin_id" sortType="String"/>
			<column messageId="boaconf.103" attributeName="plugin_description" sortType="String"/>
			</columns>');
			$mess = ConfService::getMessages();
            $all =  $first = "";
			foreach($uniqTypes as $type){
				if(!isset($types[$type])) continue;
				foreach($types[$type] as $pId => $pObject){
                    $isMain = ($pObject->getId() == "core.boa");
					$meta = array(
						"icon" 		=> ($isMain?"preferences_desktop.png":"desktop.png"),
						"APP_mime" => "plugin",
						"plugin_id" => $pObject->getId(),
						"plugin_description" => $pObject->getManifestDescription()
					);
                    // Check if there are actually any parameters to display!
                    if($pObject->getManifestRawContent("server_settings", "xml")->length == 0) continue;
                    $label =  $pObject->getManifestLabel();
                    $nodeString =XMLWriter::renderNode("/$root".$dir."/".$pObject->getId(), $label, true, $meta, true, false);
                    if($isMain){
                        $first = $nodeString;
                    }else{
                        $all .= $nodeString;
                    }
				}
			}
            print($first.$all);
		}else{
			$split = explode("/", $dir);
			if(empty($split[0])) array_shift($split);
			$type = $split[1];
			XMLWriter::sendFilesListComponentConfig('<columns switchGridMode="filelist" switchDisplayMode="full" template_name="conf.plugin_detail">
			<column messageId="boaconf.101" attributeName="APP_label" sortType="String" defaultWidth="10%"/>
			<column messageId="boaconf.102" attributeName="plugin_id" sortType="String" defaultWidth="10%"/>
			<column messageId="boaconf.103" attributeName="plugin_description" sortType="String" defaultWidth="60%"/>
			<column messageId="boaconf.104" attributeName="enabled" sortType="String" defaultWidth="10%"/>
			<column messageId="boaconf.105" attributeName="can_active" sortType="String" defaultWidth="10%"/>
			</columns>');
            $mess = ConfService::getMessages();
			foreach($types[$type] as $pId => $pObject){
				$errors = "OK";
				try{
					$pObject->performChecks();
				}catch(\Exception $e){
					$errors = "ERROR : ".$e->getMessage();
				}
				$meta = array(
					"icon" 		=> "preferences_plugin.png",
					"APP_mime" => "plugin",
					"can_active"	=> $errors,
					"enabled"	=> ($pObject->isEnabled()?$mess[440]:$mess[441]),
					"plugin_id" => $pObject->getId(),
					"plugin_description" => $pObject->getManifestDescription()
				);
				XMLWriter::renderNode("/$root".$dir."/".$pObject->getId(), $pObject->getManifestLabel(), true, $meta);
			}
		}
	}

	function listUsers($root, $child, $hashValue = null){
        $columns = '<columns switchDisplayMode="list" switchGridMode="filelist" template_name="conf.users">
        			<column messageId="boaconf.6" attributeName="APP_label" sortType="String" defaultWidth="40%"/>
        			<column messageId="boaconf.7" attributeName="isAdmin" sortType="String" defaultWidth="10%"/>
        			<column messageId="boaconf.70" attributeName="roles" sortType="String" defaultWidth="15%"/>
        			<column messageId="boaconf.62" attributeName="rights_summary" sortType="String" defaultWidth="15%"/>
        			</columns>';
        if(AuthService::driverSupportsAuthSchemes()){
            $columns = '<columns switchDisplayMode="list" switchGridMode="filelist" template_name="conf.users_authscheme">
            			<column messageId="boaconf.6" attributeName="APP_label" sortType="String" defaultWidth="40%"/>
            			<column messageId="boaconf.115" attributeName="auth_scheme" sortType="String" defaultWidth="5%"/>
            			<column messageId="boaconf.7" attributeName="isAdmin" sortType="String" defaultWidth="5%"/>
            			<column messageId="boaconf.70" attributeName="roles" sortType="String" defaultWidth="15%"/>
            			<column messageId="boaconf.62" attributeName="rights_summary" sortType="String" defaultWidth="15%"/>
            </columns>';
        }
		XMLWriter::sendFilesListComponentConfig($columns);
		if(!AuthService::usersEnabled()) return ;
        $USER_PER_PAGE = 50;
        if(empty($hashValue)) $hashValue = 1;
        if($root == "users") $baseGroup = "/";
        else $baseGroup = substr($root, strlen("users"));

        $count = AuthService::authCountUsers($baseGroup);
        if(AuthService::authSupportsPagination() && $count >= $USER_PER_PAGE){
            $offset = ($hashValue - 1) * $USER_PER_PAGE;
            XMLWriter::renderPaginationData($count, $hashValue, ceil($count/$USER_PER_PAGE));
            $users = AuthService::listUsers($baseGroup, "", $offset, $USER_PER_PAGE);
            if($hashValue == 1){
                $groups = AuthService::listChildrenGroups($baseGroup);
            }else{
                $groups = array();
            }
        }else{
            $users = AuthService::listUsers($baseGroup);
            $groups = AuthService::listChildrenGroups($baseGroup);
        }
        foreach($groups as $groupId => $groupLabel){

            XMLWriter::renderNode("/data/".$root."/".ltrim($groupId,"/"),
                $groupLabel, false, array(
                    "icon" => "users-folder.png",
                    "APP_mime" => "group"
                ));

        }
		$mess = ConfService::getMessages();
		$repos = ConfService::getRepositoriesList("all");
		$loggedUser = AuthService::getLoggedUser();
        $userArray = array();
		foreach ($users as $userIndex => $userObject){
			$label = $userObject->getId();
			if($userObject->hasParent()){
				$label = $userObject->getParent()."000".$label;
			}
            $userArray[$label] = $userObject;
        }
        ksort($userArray);
        foreach($userArray as $userObject) {
			$isAdmin = $userObject->isAdmin();
			$userId = $userObject->getId();
			$icon = "user".($userId=="guest"?"_guest":($isAdmin?"_admin":""));
			if($userObject->hasParent()){
				$icon = "user_child";
			}
			$rightsString = "";
			if($isAdmin) {
				$rightsString = $mess["boaconf.63"];
			}else{
				$r = array();
				foreach ($repos as $repoId => $repository){
					if($repository->getAccessType() == "shared") continue;
                    if(!$userObject->canRead($repoId) && !$userObject->canWrite($repoId)) continue;
                    $rs = ($userObject->canRead($repoId) ? "r" : "");
                    $rs .= ($userObject->canWrite($repoId) ? "w" : "");
                    $r[] = $repository->getDisplay()." (".$rs.")";
				}
				$rightsString = implode(", ", $r);
			}
            $nodeLabel = $userId;
            $test = $userObject->personalRole->filterParameterValue("core.conf", "USER_DISPLAY_NAME", APP_REPO_SCOPE_ALL, "");
            if(!empty($test)) $nodeLabel = $test;
            $scheme = AuthService::getAuthScheme($userId);
			XMLWriter::renderNode("/data/users/".$userId, $nodeLabel, true, array(
				"isAdmin" => $mess[($isAdmin?"boaconf.14":"boaconf.15")],
				"icon" => $icon.".png",
                "auth_scheme" => ($scheme != null? $scheme : ""),
				"rights_summary" => $rightsString,
				"roles" => implode(", ", array_keys($userObject->getRoles())),
				"APP_mime" => "user".(($userId!="guest"&&$userId!=$loggedUser->getId())?"_editable":"")
			));
		}
	}

	function listRoles(){
		XMLWriter::sendFilesListComponentConfig('<columns switchGridMode="filelist" template_name="conf.roles">
			<column messageId="boaconf.6" attributeName="APP_label" sortType="String"/>
			<column messageId="boaconf.114" attributeName="is_default" sortType="String"/>
			<column messageId="boaconf.62" attributeName="rights_summary" sortType="String"/>
			</columns>');
		if(!AuthService::usersEnabled()) return ;
		$roles = AuthService::getRolesList(array(), !$this->listSpecialRoles);
		$mess = ConfService::getMessages();
		$repos = ConfService::getRepositoriesList("all");
        ksort($roles);
        foreach($roles as $roleId => $roleObject) {
            //if(strpos($roleId, "APP_GRP_") === 0 && !$this->listSpecialRoles) continue;
			$r = array();
            if(!AuthService::canAdministrate($roleObject)) continue;
			foreach ($repos as $repoId => $repository){
				if($repository->getAccessType() == "shared") continue;
                if(!$roleObject->canRead($repoId) && !$roleObject->canWrite($repoId)) continue;
                $rs = ($roleObject->canRead($repoId) ? "r" : "");
                $rs .= ($roleObject->canWrite($repoId) ? "w" : "");
                $r[] = $repository->getDisplay()." (".$rs.")";
			}
			$rightsString = implode(", ", $r);
			XMLWriter::renderNode("/roles/".$roleId, $roleId, true, array(
				"icon" => "user-acl.png",
				"rights_summary" => $rightsString,
                "is_default"    => implode(",", $roleObject->listAutoApplies()), //($roleObject->autoAppliesTo("standard") ? $mess[440]:$mess[441]),
				"APP_mime" => "role",
                "text"      => $roleObject->getLabel()
			));
		}
	}

    function repositoryExists($name)
    {
		$repos = ConfService::getRepositoriesList();
        foreach ($repos as $obj)
            if ($obj->getDisplay() == $name) return true;

        return false;
    }

    /**
     * @param Repository $a
     * @param Repository $b
     * @return integer
     */
    function sortReposByLabel($a, $b){
        return strcasecmp($a->getDisplay(), $b->getDisplay());
    }

	function listRepositories(){
		$repos = ConfService::getRepositoriesList("all");
		XMLWriter::sendFilesListComponentConfig('<columns switchDisplayMode="list" switchGridMode="filelist" template_name="conf.repositories">
			<column messageId="boaconf.8" attributeName="APP_label" sortType="String"/>
			<column messageId="boaconf.9" attributeName="accessType" sortType="String"/>
			<column messageId="shared.27" attributeName="owner" sortType="String"/>
			<column messageId="boaconf.106" attributeName="repository_id" sortType="String"/>
			</columns>');
        $repoArray = array();
        $childRepos = array();
        $templateRepos = array();
        $flatChildrenRepos = array();
        //uasort($repos, array($this, "sortReposByLabel"));
		foreach ($repos as $repoIndex => $repoObject){
            if(!AuthService::canAdministrate($repoObject)){
                continue;
            }
			if($repoObject->getAccessType() == "boaconf" || $repoObject->getAccessType() == "shared") continue;
			if(is_numeric($repoIndex)) $repoIndex = "".$repoIndex;
            $name = Utils::xmlEntities(SystemTextEncoding::toUTF8($repoObject->getDisplay()));
			if($repoObject->hasOwner() || $repoObject->hasParent()) {
				$parentId = $repoObject->getParentId();
                if(isSet($repos[$parentId]) && AuthService::canAdministrate($repos[$parentId])){
                    if(!isSet($childRepos[$parentId])) $childRepos[$parentId] = array();
                    $childRepos[$parentId][] = array("name" => $name, "index" => $repoIndex);
                    $flatChildrenRepos[] = $repoIndex;
                    continue;
                }
			}
			if($repoObject->isTemplate){
				$templateRepos[$name] = $repoIndex;
			}else{
	            $repoArray[$name] = $repoIndex;
			}
        }
        // Sort the list now by name
        ksort($templateRepos);
        ksort($repoArray);
        $repoArray = array_merge($templateRepos, $repoArray);
        // Append child repositories
        $sortedArray = array();
        foreach ($repoArray as $name => $repoIndex) {
        	$sortedArray[$name] = $repoIndex;
        	if(isSet($childRepos[$repoIndex]) && is_array($childRepos[$repoIndex])){
        		foreach ($childRepos[$repoIndex] as $childData){
        			$sortedArray[$childData["name"]] = $childData["index"];
        		}
        	}
        }
        foreach ($sortedArray as $name => $repoIndex) {
            $repoObject =& $repos[$repoIndex];
            $icon = (in_array($repoIndex, $flatChildrenRepos)?"repo_child.png":"hdd_external_unmount.png");
            $editable = $repoObject->isWriteable();
            if($repoObject->isTemplate) {
                $icon = "hdd_external_mount.png";
                if(AuthService::getLoggedUser() != null && AuthService::getLoggedUser()->getGroupPath() != "/"){
                    $editable = false;
                }
            }
            $metaData = array(
            	"repository_id" => $repoIndex,
            	"accessType"	=> ($repoObject->isTemplate?"Template for ":"").$repoObject->getAccessType(),
            	"icon"			=> $icon,
            	"owner"			=> ($repoObject->hasOwner()?$repoObject->getOwner():""),
            	"openicon"		=> $icon,
            	"parentname"	=> "/repositories",
				"APP_mime" 	=> "repository".($editable?"_editable":"")
            );
            XMLWriter::renderNode("/repositories/$repoIndex", $name, true, $metaData);
		}
	}

    function listActions($dir, $root = NULL){
        $parts = explode("/",$dir);
        $pServ = PluginsService::getInstance();
        $activePlugins = $pServ->getActivePlugins();
        $types = $pServ->getDetectedPlugins();
        if(count($parts) == 1){
            // list all types
            XMLWriter::sendFilesListComponentConfig('<columns switchGridMode="filelist" template_name="conf.plugins_folder">
			<column messageId="boaconf.101" attributeName="APP_label" sortType="String"/>
			</columns>');
            ksort($types);
            foreach( $types as $t => $tPlugs){
                $meta = array(
                    "icon" 		=> "folder_development.png",
                    "plugin_id" => $t
                );
                XMLWriter::renderNode("/$root/actions/".$t, ucfirst($t), false, $meta);
            }

        }else if(count($parts) == 2){
            // list plugs
            $type = $parts[1];
            XMLWriter::sendFilesListComponentConfig('<columns switchDisplayMode="detail" template_name="conf.plugins_folder">
                <column messageId="boaconf.101" attributeName="APP_label" sortType="String"/>
                <column messageId="boaconf.103" attributeName="actions" sortType="String"/>
			</columns>');
            $pObject = new Plugin("","");
            foreach($types[$type] as $pId => $pObject){
                $actions = $pObject->getManifestRawContent("//action/@name", "xml", true);
                $actLabel = array();
                if($actions->length){
                    foreach($actions as $node){
                        $actLabel[] = $node->nodeValue;
                    }
                }
                $meta = array(
                    "icon" 		=> "preferences_plugin.png",
                    "plugin_id" => $pObject->getId(),
                    "actions"   => implode(", ", $actLabel)
                );
                XMLWriter::renderNode("/$root/actions/$type/".$pObject->getName(), $pObject->getManifestLabel(), false, $meta);
            }

        }else if(count($parts) == 3){
            // list actions
            $type = $parts[1];
            $name = $parts[2];
            $mess = ConfService::getMessages();
            XMLWriter::sendFilesListComponentConfig('<columns switchDisplayMode="full" template_name="conf.plugins_folder">
                <column messageId="boaconf.101" attributeName="APP_label" sortType="String" defaultWidth="10%"/>
                <column messageId="boaconf.103" attributeName="parameters" sortType="String" fixedWidth="30%"/>
			</columns>');
            $pObject = new Plugin("","");
            $pObject = $types[$type][$name];

            $actions = $pObject->getManifestRawContent("//action", "xml", true);
            $allNodes = array();
            if($actions->length){
                foreach($actions as $node){
                    $xPath = new \DOMXPath($node->ownerDocument);
                    $callbacks = $xPath->query("processing/serverCallback", $node);
                    if(!$callbacks->length) continue;
                    $callback = $callbacks->item(0);

                    $actName = $actLabel = $node->attributes->getNamedItem("name")->nodeValue;
                    $text = $xPath->query("gui/@text", $node);
                    if($text->length) {
                        $actLabel = $actName ." (" . $mess[$text->item(0)->nodeValue].")";
                    }
                    $params = $xPath->query("processing/serverCallback/input_param", $node);
                    $paramLabel = array();
                    if($callback->getAttribute("developerComment") != ""){
                        $paramLabel[] = "<span class='developerComment'>".$callback->getAttribute("developerComment")."</span>";
                    }
                    $restPath = "";
                    if($callback->getAttribute("restParams")){
                        $restPath = "/api/$actName/". ltrim($callback->getAttribute("restParams"), "/");
                    }
                    if($restPath != null){
                        $paramLabel[] = "<span class='developerApiAccess'>"."API Access : ".$restPath."</span>";
                    }
                    if($params->length){
                        $paramLabel[] = "Expected Parameters :";
                        foreach($params as $param){
                            $paramLabel[]= '. ['.$param->getAttribute("type").'] <b>'.$param->getAttribute("name").($param->getAttribute("mandatory") == "true" ? '*':'').'</b> : '.$param->getAttribute("description");
                        }
                    }
                    $parameters = "";
                    $meta = array(
                        "icon" 		=> "preferences_plugin.png",
                        "action_id" => $actName,
                        "parameters"=> '<div class="developerDoc">'.implode("<br/>", $paramLabel).'</div>',
                        "rest_params"=> $restPath
                    );
                    $allNodes[$actName] = XMLWriter::renderNode(
                        "/$root/actions/$type/".$pObject->getName()."/$actName",
                        $actLabel,
                        true,
                        $meta,
                        true,
                        false
                    );
                }
                ksort($allNodes);
                print(implode("", array_values($allNodes)));
            }

        }
    }

    function listHooks($dir, $root = NULL){
        $jsonContent = json_decode(file_get_contents(Utils::getHooksFile()), true);
        $config = '<columns switchDisplayMode="full" template_name="hooks.list">
				<column messageId="boaconf.17" attributeName="APP_label" sortType="String" defaultWidth="20%"/>
				<column messageId="boaconf.18" attributeName="description" sortType="String" defaultWidth="20%"/>
				<column messageId="boaconf.19" attributeName="triggers" sortType="String" defaultWidth="25%"/>
				<column messageId="boaconf.20" attributeName="listeners" sortType="String" defaultWidth="25%"/>
				<column messageId="boaconf.21" attributeName="sample" sortType="String" defaultWidth="10%"/>
			</columns>';
        XMLWriter::sendFilesListComponentConfig($config);
        foreach($jsonContent as $hookName => $hookData){
            $metadata = array(
                "icon"          => "preferences_plugin.png",
                "description"   => $hookData["DESCRIPTION"],
                "sample"        => $hookData["PARAMETER_SAMPLE"],
            );
            $trigs = array();
            foreach($hookData["TRIGGERS"] as $trigger){
                $trigs[] = "<span>".$trigger["FILE"]." (".$trigger["LINE"].")</span>";
            }
            $metadata["triggers"] = implode("<br/>", $trigs);
            $listeners = array();
            foreach($hookData["LISTENERS"] as $listener){
                $listeners[] = "<span>Plugin ".$listener["PLUGIN_ID"].", in method ".$listener["METHOD"]."</span>";
            }
            $metadata["listeners"] = implode("<br/>", $listeners);
            XMLWriter::renderNode("/$root/hooks/$hookName/$hookName", $hookName, true, $metadata);
        }
    }

	function listLogFiles($dir, $root = NULL){
        $dir = "/$dir";
		$logger = Logger::getInstance();
		$parts = explode("/", $dir);
		if(count($parts)>4){
			$config = '<columns switchDisplayMode="list" switchGridMode="grid" template_name="conf.logs">
				<column messageId="boaconf.17" attributeName="date" sortType="MyDate" defaultWidth="10%"/>
				<column messageId="boaconf.18" attributeName="ip" sortType="String" defaultWidth="10%"/>
				<column messageId="boaconf.19" attributeName="level" sortType="String" defaultWidth="10%"/>
				<column messageId="boaconf.20" attributeName="user" sortType="String" defaultWidth="10%"/>
				<column messageId="boaconf.21" attributeName="action" sortType="String" defaultWidth="10%"/>
				<column messageId="boaconf.22" attributeName="params" sortType="String" defaultWidth="50%"/>
			</columns>';
			XMLWriter::sendFilesListComponentConfig($config);
			$date = $parts[count($parts)-1];
			$logger->xmlLogs($dir, $date, "tree", $root."/logs");
		}else{
			XMLWriter::sendFilesListComponentConfig('<columns switchGridMode="filelist"><column messageId="boaconf.16" attributeName="APP_label" sortType="String"/></columns>');
			$logger->xmlListLogFiles("tree", (count($parts)>2?$parts[2]:null), (count($parts)>3?$parts[3]:null), $root."/logs");
		}
	}

	function printDiagnostic(){
		$outputArray = array();
		$testedParams = array();
		$passed = Utils::runTests($outputArray, $testedParams);
		Utils::testResultsToFile($outputArray, $testedParams);
		XMLWriter::sendFilesListComponentConfig('<columns switchDisplayMode="list" switchGridMode="fileList" template_name="conf.diagnostic" defaultWidth="20%"><column messageId="boaconf.23" attributeName="APP_label" sortType="String"/><column messageId="boaconf.24" attributeName="data" sortType="String"/></columns>');
		if(is_file(TESTS_RESULT_FILE)){
			include_once(TESTS_RESULT_FILE);
            if(isset($diagResults)){
                foreach ($diagResults as $id => $value){
                    $value = Utils::xmlEntities($value);
                    print "<tree icon=\"susehelpcenter.png\" is_file=\"1\" filename=\"$id\" text=\"$id\" data=\"$value\" APP_mime=\"testResult\"/>";
                }
            }
		}
	}

	function listSharedFiles(){
		XMLWriter::sendFilesListComponentConfig('<columns switchGridMode="filelist" template_name="conf.shared">
				<column messageId="shared.4" attributeName="APP_label" sortType="String" defaultWidth="30%"/>
				<column messageId="shared.27" attributeName="owner" sortType="String" defaultWidth="10%"/>
				<column messageId="shared.17" attributeName="download_url" sortType="String" defaultWidth="40%"/>
				<column messageId="shared.6" attributeName="password" sortType="String" defaultWidth="4%"/>
				<column messageId="shared.7" attributeName="expiration" sortType="String" defaultWidth="4%"/>
				<column messageId="shared.20" attributeName="expired" sortType="String" defaultWidth="4%"/>
				<column messageId="shared.14" attributeName="integrity" sortType="String" defaultWidth="4%" hidden="true"/>
			</columns>');
		$dlFolder = ConfService::getCoreConf("PUBLIC_DOWNLOAD_FOLDER");
		if(!is_dir($dlFolder)) return ;
		$files = glob($dlFolder."/*.php");
		if($files === false) return ;
		$mess = ConfService::getMessages();
		$loggedUser = AuthService::getLoggedUser();
		$userId = $loggedUser->getId();
		$dlURL = ConfService::getCoreConf("PUBLIC_DOWNLOAD_URL");
        if($dlURL!= ""){
        	$downloadBase = rtrim($dlURL, "/");
        }else{
	        $fullUrl = Utils::detectServerURL() . dirname($_SERVER['REQUEST_URI']);
	        $downloadBase = str_replace("\\", "/", $fullUrl.rtrim(str_replace(APP_INSTALL_PATH, "", $dlFolder), "/"));
        }

		foreach ($files as $file){
			$publicletData = $this->loadPublicletData($file);
            if(!is_a($publicletData["REPOSITORY"], "Repository")){
                continue;
            }
			XMLWriter::renderNode(str_replace(".php", "", basename($file)), "".SystemTextEncoding::toUTF8($publicletData["REPOSITORY"]->getDisplay()).":/".SystemTextEncoding::toUTF8($publicletData["FILE_PATH"]), true, array(
				"icon"		=> "html.png",
				"password" => ($publicletData["PASSWORD"]!=""?$publicletData["PASSWORD"]:"-"),
				"expiration" => ($publicletData["EXPIRE_TIME"]!=0?date($mess["date_format"], $publicletData["EXPIRE_TIME"]):"-"),
				"expired" => ($publicletData["EXPIRE_TIME"]!=0?($publicletData["EXPIRE_TIME"]<time()?$mess["shared.21"]:$mess["shared.22"]):"-"),
				"integrity"  => (!$publicletData["SECURITY_MODIFIED"]?$mess["shared.15"]:$mess["shared.16"]),
				"download_url" => $downloadBase . "/".basename($file),
				"owner" => (isset($publicletData["OWNER_ID"])?$publicletData["OWNER_ID"]:"-"),
				"APP_mime" => "shared_file")
			);
		}
	}

	function metaSourceOrderingFunction($key1, $key2){
        $a1 = explode(".", $key1);
		$t1 = array_shift($a1);
        $a2 = explode(".", $key2);
		$t2 = array_shift($a2);
		if($t1 == "index") return 1;
        if($t1 == "metastore") return -1;
		if($t2 == "index") return -1;
        if($t2 == "metastore") return 1;
        if($key1 == "meta.git" || $key1 == "meta.svn") return 1;
        if($key2 == "meta.git" || $key2 == "meta.svn") return -1;
		return 0;
	}

	function clearExpiredFiles(){
		$files = glob(ConfService::getCoreConf("PUBLIC_DOWNLOAD_FOLDER")."/*.php");
		$loggedUser = AuthService::getLoggedUser();
		$userId = $loggedUser->getId();
		$deleted = array();
		foreach ($files as $file){
			$publicletData = $this->loadPublicletData($file);
			if(isSet($publicletData["EXPIRATION_TIME"]) && is_numeric($publicletData["EXPIRATION_TIME"]) && $publicletData["EXPIRATION_TIME"] > 0 && $publicletData["EXPIRATION_TIME"] < time()){
				unlink($file);
				$deleted[] = basename($file);
			}
		}
		return $deleted;
	}

	protected function loadPublicletData($file){
        $inputData = null;
		$lines = file($file);
		$id = str_replace(".php", "", basename($file));
		$code = trim($lines[3] . $lines[4] . $lines[5]);
        if(strpos($code, '$cypheredData =') !== 0) return null;
		eval($code);
		$dataModified = !ShareCenter::checkHash($inputData, $id);
		$publicletData = unserialize($inputData);
        if(!is_array($publicletData)) return null;
		$publicletData["SECURITY_MODIFIED"] = $dataModified;
		return $publicletData;
	}

	function updateUserRole($userId, $roleId, $addOrRemove, $updateSubUsers = false){
		$confStorage = ConfService::getConfStorageImpl();
		$user = $confStorage->createUserObject($userId);
		//if($user->hasParent()) return $user;
		if($addOrRemove == "add"){
            $roleObject = AuthService::getRole($roleId);
			$user->addRole($roleObject);
		}else{
			$user->removeRole($roleId);
		}
		$user->save("superuser");
		$loggedUser = AuthService::getLoggedUser();
		if($loggedUser->getId() == $user->getId()){
			AuthService::updateUser($user);
		}
		return $user;

	}


	function parseParameters(&$repDef, &$options, $userId = null, $globalBinaries = false){

        Utils::parseStandardFormParameters($repDef, $options, $userId, "DRIVER_OPTION_", ($globalBinaries?array():null));

	}

    function flattenKeyValues(&$result, $values, $parent = ""){
        foreach($values as $key => $value){
            if(is_array($value)){
                $this->flattenKeyValues($result, $value, $parent."/".$key);
            }else{
                if($key == "group_switch_value" || $key == "instance_name"){
                    $result[$parent] = $value;
                }else{
                    $result[$parent.'/'.$key] = $value;
                }
            }
        }
    }

}
