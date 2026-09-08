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
namespace BoA\Plugins\Access\Shared;

use BoA\Core\Access\UserSelection;
use BoA\Core\Http\XMLWriter;
use BoA\Core\Services\AuthService;
use BoA\Core\Services\ConfService;
use BoA\Core\Utils\Utils;
use BoA\Core\Utils\Text\SystemTextEncoding;
use BoA\Plugins\Core\Access\AbstractAccessDriver;

defined('APP_EXEC') or die( 'Access not allowed');
/**
 * @package APP_Plugins
 * @subpackage Access
 * @class SharedAccessDriver
 * Plugin to access the shared elements of the current user
 */
class SharedAccessDriver extends AbstractAccessDriver 
{	

    function initRepository(){
      //ToDo: Review how to include this file. require_once APP_PLUGINS_FOLDER."/action.share/class.ShareCenter.php";        
    }

	function switchAction($action, $httpVars, $fileVars){
		if(!isSet($this->actions[$action])) return;
		parent::accessPreprocess($action, $httpVars, $fileVars);
		$loggedUser = AuthService::getLoggedUser();
		if(!AuthService::usersEnabled()) return ;
		
		if($action == "edit"){
			if(isSet($httpVars["sub_action"])){
				$action = Utils::arrayGet($httpVars, "sub_action");
			}
		}
		$mess = ConfService::getMessages();
		
		switch($action)
		{			
			//------------------------------------
			//	BASIC LISTING
			//------------------------------------
			case "ls":
				$rootNodes = array(
					"files" => array("LABEL" => $mess["shared.3"], "ICON" => "html.png", "DESCRIPTION" => $mess["shared.28"]),
					"repositories" => array("LABEL" => $mess["shared.2"], "ICON" => "document_open_remote.png", "DESCRIPTION" => $mess["shared.29"]),
					"users" => array("LABEL" => $mess["shared.1"], "ICON" => "user_shared.png", "DESCRIPTION" => $mess["shared.30"])
				);
				$dir = (isset($httpVars["dir"])?$httpVars["dir"]:"");
				$splits = explode("/", $dir);
				if(count($splits)){
					if($splits[0] == "") array_shift($splits);
					if(count($splits)) $strippedDir = strtolower(urldecode($splits[0]));
					else $strippedDir = "";
				}				
				if(array_key_exists($strippedDir, $rootNodes)){
					XMLWriter::header();
					if($strippedDir == "users"){
						$this->listUsers();
					}else if($strippedDir == "repositories"){
						$this->listRepositories();
					}else if($strippedDir == "files"){
						$this->listSharedFiles();
					}
					XMLWriter::close();
					exit(1);
				}else{
					XMLWriter::header();
					XMLWriter::sendFilesListComponentConfig('<columns switchGridMode="filelist"><column messageId="shared.8" attributeName="APP_label" sortType="String"/><column messageId="shared.31" attributeName="description" sortType="String"/></columns>');
					foreach ($rootNodes as $key => $data){
						print '<tree text="'.Utils::arrayGet($data, "LABEL").'" icon="'.Utils::arrayGet($data, "ICON").'" filename="/'.$key.'" parentname="/" description="'.Utils::arrayGet($data, "DESCRIPTION").'" />';
					}
					XMLWriter::close();
				}
			break;
			
			case "stat" :
				
				header("Content-type:application/json");
				print '{"mode":true}';
				
			break;			
						
			case "delete" : 
				$mime = Utils::arrayGet($httpVars, "APP_mime");
				$selection = new UserSelection();
				$selection->initFromHttpVars();
				$files = $selection->getFiles();
				XMLWriter::header();
				foreach ($files as $index => $element){
					$element = basename($element);
                    $ar = explode("shared_", $mime);
                    $mime = array_pop($ar);
                    ShareCenter::deleteSharedElement($mime, $element, $loggedUser);
                    if($mime == "repository") $out = $mess["boaconf.59"];
                    else if($mime == "user") $out = $mess["boaconf.60"];
                    else if($mime == "file") $out = $mess["shared.13"];
				}
                XMLWriter::sendMessage($out, null);
                XMLWriter::reloadDataNode();
				XMLWriter::close();
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
			
			case "reset_download_counter" : 
				
				$selection = new UserSelection();
				$selection->initFromHttpVars();
				$elements = $selection->getFiles();
		        foreach ($elements as $element){
					PublicletCounter::reset(str_replace(".php", "", basename($element)));
				}
				XMLWriter::header();
				XMLWriter::reloadDataNode();
				XMLWriter::close();
			
			break;
			
			default:
			break;
		}

		return;
	}
	
	function listSharedFiles(){
		XMLWriter::sendFilesListComponentConfig('<columns switchGridMode="filelist">
				<column messageId="shared.4" attributeName="APP_label" sortType="String" width="20%"/>
				<column messageId="shared.17" attributeName="download_url" sortType="String" width="20%"/>
				<column messageId="shared.20" attributeName="download_count" sortType="String" width="2%"/>
                <column messageId="share_center.22" attributeName="download_limit" sortType="String" width="2%"/>
				<column messageId="shared.6" attributeName="password" sortType="String" width="5%"/>
				<column messageId="shared.7" attributeName="expiration" sortType="String" width="5%"/>				
			</columns>');
		$dlFolder = ConfService::getCoreConf("PUBLIC_DOWNLOAD_FOLDER");
		if(!is_dir($dlFolder)) return ;		
		$files = glob($dlFolder."/*.php");
        if(!is_array($files))return;
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
            $ar = explode(".", basename($file));
            $id = array_shift($ar);
            if($ar[0] != "php") continue;
            //if(strlen($id) != 32) continue;
			$publicletData = ShareCenter::loadPublicletData($id);
			if(isset($publicletData["OWNER_ID"]) && $publicletData["OWNER_ID"] != $userId){
				continue;
			}
			$expired = (Utils::arrayGet($publicletData, "EXPIRE_TIME")!=0?(Utils::arrayGet($publicletData, "EXPIRE_TIME")<time()?true:false):false);
            if(!is_a(Utils::arrayGet($publicletData, "REPOSITORY"), "Repository")) continue;
			XMLWriter::renderNode(str_replace(".php", "", basename($file)), "".SystemTextEncoding::toUTF8(Utils::arrayGet($publicletData, "REPOSITORY")->getDisplay()).":/".SystemTextEncoding::toUTF8(Utils::arrayGet($publicletData, "FILE_PATH")), true, array(
				"icon"		=> "html.png",
				"password" => (Utils::arrayGet($publicletData, "PASSWORD")!=""?Utils::arrayGet($publicletData, "PASSWORD"):"-"), 
				"expiration" => (Utils::arrayGet($publicletData, "EXPIRE_TIME")!=0?($expired?"[!]":"").date($mess["date_format"], Utils::arrayGet($publicletData, "EXPIRE_TIME")):"-"), 				
				"download_count" => Utils::arrayGet($publicletData, "DOWNLOAD_COUNT"),
                "download_limit" => ($publicletData["DOWNLOAD_LIMIT"] == 0 ? "-" : Utils::arrayGet($publicletData, "DOWNLOAD_LIMIT") ),
				"integrity"  => (!Utils::arrayGet($publicletData, "SECURITY_MODIFIED")?$mess["shared.15"]:$mess["shared.16"]),
				"download_url" => $downloadBase . "/".basename($file),
				"APP_mime" => "shared_file")
			);			
		}
	}
	
	function clearExpiredFiles(){
		$files = glob(ConfService::getCoreConf("PUBLIC_DOWNLOAD_FOLDER")."/*.php");
		$loggedUser = AuthService::getLoggedUser();
		$userId = $loggedUser->getId();
		$deleted = array();
		foreach ($files as $file){
            $ar = explode(".", basename($file));
            $id = array_shift($ar);
            if(strlen($id) != 32) continue;
			$publicletData = ShareCenter::loadPublicletData($id);
			if(!isSet($publicletData["OWNER_ID"]) || $publicletData["OWNER_ID"] != $userId){
				continue;
			}
			if( (isSet($publicletData["EXPIRE_TIME"]) && is_numeric($publicletData["EXPIRE_TIME"]) && $publicletData["EXPIRE_TIME"] > 0 && $publicletData["EXPIRE_TIME"] < time()) ||
                            (isSet($publicletData["DOWNLOAD_LIMIT"]) && $publicletData["DOWNLOAD_LIMIT"] > 0 && $publicletData["DOWNLOAD_LIMIT"] <= $publicletData["DOWNLOAD_COUNT"]) ) {
				unlink($file);
				$deleted[] = basename($file);
        		PublicletCounter::delete(str_replace(".php", "", basename($file)));
			}
		}
		return $deleted;
	}

	function listUsers(){
		XMLWriter::sendFilesListComponentConfig('<columns switchGridMode="filelist"><column messageId="boaconf.6" attributeName="APP_label" sortType="String"/><column messageId="shared.10" attributeName="repo_accesses" sortType="String"/></columns>');		
		if(!AuthService::usersEnabled()) return ;
		$users = AuthService::listUsers();
		$mess = ConfService::getMessages();
		$loggedUser = AuthService::getLoggedUser();		
		$repoList = ConfService::getRepositoriesList("all");
        $userArray = array();
		foreach ($users as $userIndex => $userObject){
			$label = $userObject->getId();
			if(!$userObject->hasParent() || $userObject->getParent() != $loggedUser->getId()) continue;
			if($userObject->hasParent()){
				$label = $userObject->getParent()."000".$label;
			}
            $userArray[$label] = $userObject;
        }        
        ksort($userArray);
        foreach($userArray as $userObject) {
			$isAdmin = $userObject->isAdmin();
			$userId = Utils::xmlEntities($userObject->getId());
			$repoAccesses = array();
			foreach ($repoList as $repoObject) {
				if($repoObject->hasOwner() && $repoObject->getOwner() == $loggedUser->getId()){
                    $acl = $userObject->mergedRole->getAcl($repoObject->getId());
                    if(!empty($acl)) $repoAccesses[] = $repoObject->getDisplay()." ($acl)";
				}
			}			
			print '<tree 
				text="'.$userId.'"
				isAdmin="'.$mess[($isAdmin?"boaconf.14":"boaconf.15")].'" 
				icon="user_shared.png" 
				openicon="user_shared.png" 
				filename="/users/'.$userId.'" 
				repo_accesses="'.implode(", ", $repoAccesses).'"
				parentname="/users" 
				is_file="1" 
				APP_mime="shared_user"
				/>';
		}
	}
	
	function listRepositories(){
		$repos = ConfService::getRepositoriesList("all");
		XMLWriter::sendFilesListComponentConfig('<columns switchGridMode="filelist"><column messageId="boaconf.8" attributeName="APP_label" sortType="String"/><column messageId="boaconf.9" attributeName="accessType" sortType="String"/><column messageId="shared.9" attributeName="repo_accesses" sortType="String"/></columns>');		
        $repoArray = array();
        $childRepos = array();
        $loggedUser = AuthService::getLoggedUser();        
        $users = AuthService::listUsers();
		foreach ($repos as $repoIndex => $repoObject){
			if($repoObject->getAccessType() == "boaconf") continue;			
			if(!$repoObject->hasOwner() || $repoObject->getOwner() != $loggedUser->getId()){				
				continue;
			}
			if(is_numeric($repoIndex)) $repoIndex = "".$repoIndex;
            $name = Utils::xmlEntities(SystemTextEncoding::toUTF8($repoObject->getDisplay()));
            $repoArray[$name] = $repoIndex;
        }
        // Sort the list now by name        
        ksort($repoArray);
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
            $repoAccesses = array();
			foreach ($users as $userId => $userObject) {
				//if(!$userObject->hasParent()) continue;
                if($userObject->getId() == $loggedUser->getId()) continue;
                $label = $userObject->personalRole->filterParameterValue("core.conf", "USER_DISPLAY_NAME", APP_REPO_SCOPE_ALL, $userId);
                $acl = $userObject->mergedRole->getAcl($repoObject->getId());
                if(!empty($acl)) $repoAccesses[] = $label. " (".$acl.")";
            }
            
            $metaData = array(
            	"repository_id" => $repoIndex,
            	"accessType"	=> $repoObject->getAccessType(),
            	"icon"			=> "document_open_remote.png",
            	"openicon"		=> "document_open_remote.png",
            	"parentname"	=> "/repositories",
            	"repo_accesses" => implode(", ", $repoAccesses),
				"APP_mime" 	=> "shared_repository"
            );
            XMLWriter::renderNode("/repositories/$repoIndex", $name, true, $metaData);
		}
	}
		    
}

