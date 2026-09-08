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
namespace BoA\Plugins\Core\Access;

use BoA\Core\Access\UserSelection;
use BoA\Core\Http\Controller;
use BoA\Core\Http\XMLWriter;
use BoA\Core\Plugins\Plugin;
use BoA\Core\Services\AuthService;
use BoA\Core\Services\ConfService;
use BoA\Core\Services\PluginsService;
use BoA\Core\Utils\Filters\VarsFilter;
use BoA\Core\Utils\Utils;
use BoA\Core\Xml\ManifestNode;
use BoA\Plugins\Core\Log\Logger;

defined('APP_EXEC') or die( 'Access not allowed');

/**
 * @package APP_Plugins
 * @subpackage Core
 * @class AbstractAccessDriver
 * Abstract representation of an action driver. Must be implemented.
 */
class AbstractAccessDriver extends Plugin {

    /**
    * @var Repository
    */
    public $repository;
    public $driverType = "access";

    public function init($repository, $options = array()){
        //$this->loadActionsFromManifest();
        parent::init($options);
        $this->repository = $repository;
    }

    function initRepository(){
        // To be implemented by subclasses
    }

    function accessPreprocess($actionName, &$httpVars, &$filesVar)
    {
        if($actionName == "apply_check_hook"){
            $hookName = $httpVars["hook_name"] ?? "";
            $hookArg = $httpVars["hook_arg"] ?? null;
            if(!in_array($hookName, array("before_create", "before_path_change", "before_change"))){
                return;
            }
            $selection = new UserSelection();
            $selection->initFromHttpVars($httpVars);
            $node = $selection->getUniqueNode($this);
            Controller::applyHook("node.".$hookName, array($node, $hookArg));
        }
        if($actionName == "ls"){
            // UPWARD COMPATIBILTY
            if(isSet($httpVars["options"])){
                if($httpVars["options"] == "al") $httpVars["mode"] = "file_list";
                else if($httpVars["options"] == "a") $httpVars["mode"] = "search";
                else if($httpVars["options"] == "d") $httpVars["skipZip"] = "true";
                // skip "complete" mode that was in fact quite the same as standard tree listing (dz)
            }
        }
    }

    protected function parseSpecificContributions(&$contribNode){
        parent::parseSpecificContributions($contribNode);
    }

    /**
     * Backward compatibility, now moved to SharedCenter::loadPubliclet();
     * @param $data
     * @return void
     */
    function loadPubliclet($data){
        require_once(APP_PLUGINS_FOLDER."/action.share/class.ShareCenter.php");
        ShareCenter::loadPubliclet($data);
    }

    /**
     * Populate publiclet options
     * @param String $filePath The path to the file to share
     * @param String $password optionnal password
     * @param String $downloadlimit optional limit for downloads
     * @param String $expires optional expiration date
     * @param Repository $repository
     * @return Array
     */
    function makePublicletOptions($filePath, $password, $expires, $downloadlimit, $repository) {}

    /**
     * Populate shared repository options
     * @param Array $httpVars
     * @param Repository $repository
     * @return Array
     */
    function makeSharedRepositoryOptions($httpVars, $repository){

    }

    function crossRepositoryCopy($httpVars){

        ConfService::detectRepositoryStreams(true);
        $mess = ConfService::getMessages();
        $selection = new UserSelection();
        $selection->initFromHttpVars($httpVars);
        $files = $selection->getFiles();

        $accessType = $this->repository->getAccessType();
        $repositoryId = $this->repository->getId();
        $plugin = PluginsService::findPlugin("access", $accessType);
        $origWrapperData = $plugin->detectStreamWrapper(true);
        $origStreamURL = $origWrapperData["protocol"]."://$repositoryId";
        $destRepoId = Utils::arrayGet($httpVars, "dest_repository_id");
        $destRepoObject = ConfService::getRepositoryById($destRepoId);
        $destRepoAccess = $destRepoObject->getAccessType();
        $plugin = PluginsService::findPlugin("access", $destRepoAccess);
        $destWrapperData = $plugin->detectStreamWrapper(true);
        $destStreamURL = $destWrapperData["protocol"]."://$destRepoId";
        // Check rights
        if(AuthService::usersEnabled()){
            $loggedUser = AuthService::getLoggedUser();
            if(!$loggedUser->canRead($repositoryId) || !$loggedUser->canWrite($destRepoId)
                || (isSet($httpVars["moving_files"]) && !$loggedUser->canWrite($repositoryId))
            ){
                throw new Exception($mess[364]);
            }
        }

        $messages = array();
        foreach ($files as $file){
            $origFile = $origStreamURL.$file;
            $localName = "";
            Controller::applyHook("dl.localname", array($origFile, &$localName, $origWrapperData["classname"]));
            if(isSet($httpVars["moving_files"])){
                Controller::applyHook("node.before_path_change", array(new ManifestNode($origFile)));
            }
            $bName = basename($file);
            if($localName != ""){
                $bName = $localName;
            }
            if(isSet($httpVars["moving_files"])){
                $touch = filemtime($origFile);
            }
            $destFile = $destStreamURL.SystemTextEncoding::fromUTF8(Utils::arrayGet($httpVars, "dest"))."/".$bName;
            Controller::applyHook("node.before_create", array($destFile));
            if(!is_file($origFile)){
                throw new Exception("Cannot find $origFile");
            }
            $origHandler = fopen($origFile, "r");
            $destHandler = fopen($destFile, "w");
            if($origHandler === false || $destHandler === false) {
                $errorMessages[] = XMLWriter::sendMessage(null, $mess[114]." ($origFile to $destFile)", false);
                continue;
            }
            while(!feof($origHandler)){
                fwrite($destHandler, fread($origHandler, 4096));
            }
            fflush($destHandler);
            fclose($origHandler); 
            fclose($destHandler);
            Controller::applyHook("node.change", array(null, new ManifestNode($destFile)));
            if(isSet($httpVars["moving_files"])){
                $wrapName = $destWrapperData["classname"];
                if(!call_user_func(array($wrapName, "isRemote"))){
                    $real = call_user_func(array($this->wrapperClassName, "getRealFSReference"), $destFile, true);
                    $r = @touch($real, $touch, $touch);

                }
                Controller::applyHook("node.change", array(new ManifestNode($origFile), null));
            }
            $messages[] = $mess[34]." ".SystemTextEncoding::toUTF8(basename($origFile))." ".(isSet($httpVars["moving_files"])?$mess[74]:$mess[73])." ".SystemTextEncoding::toUTF8($destFile);
        }
        XMLWriter::header();
        if(count($errorMessages)){
            XMLWriter::sendMessage(null, join("\n", $errorMessages), true);
        }
        XMLWriter::sendMessage(join("\n", $messages), null, true);
        XMLWriter::close();
    }

    /**
     * 
     * Try to reapply correct permissions
     * @param oct $mode
     * @param Repository $repoObject
     * @param Function $remoteDetectionCallback
     */
    public static function fixPermissions(&$stat, $repoObject, $remoteDetectionCallback = null){

        $fixPermPolicy = $repoObject->getOption("FIX_PERMISSIONS");
        $loggedUser = AuthService::getLoggedUser();
        if($loggedUser == null){
            return;
        }
        $sessionKey = md5($repoObject->getId()."-".$loggedUser->getId()."-fixPermData");

        if(!isSet($_SESSION[$sessionKey])){
            if($fixPermPolicy == "detect_remote_user_id" && $remoteDetectionCallback != null){
                list($uid, $gid) = call_user_func($remoteDetectionCallback, $repoObject);
                if($uid != null && $gid != null){
                    $_SESSION[$sessionKey] = array("uid" => $uid, "gid" => $gid);
                }

            }else if(substr($fixPermPolicy, 0, strlen("file:")) == "file:"){
                $filePath = VarsFilter::filter(substr($fixPermPolicy, strlen("file:")));
                if(file_exists($filePath)){
                    // GET A GID/UID FROM FILE
                    $lines = file($filePath);
                    foreach($lines as $line){
                        $res = explode(":", $line);
                        if($res[0] == $loggedUser->getId()){
                            $uid = $res[1];
                            $gid = $res[2];
                            $_SESSION[$sessionKey] = array("uid" => $uid, "gid" => $gid);
                            break;
                        }
                    }
                }
            }
            // If not set, set an empty anyway
            if(!isSet($_SESSION[$sessionKey])){
                $_SESSION[$sessionKey] = array(null, null);
            }

        }else{
            $data = $_SESSION[$sessionKey];
            if(!empty($data)){
                if(isSet($data["uid"])) $uid = $data["uid"];
                if(isSet($data["gid"])) $gid = $data["gid"];
            }
        }

        $p = $stat["mode"];
        $st = sprintf("%07o", ($p & 7777770));
        Logger::debug("FIX PERM DATA ($fixPermPolicy, $st)".$p,sprintf("%o", ($p & 000777)));
        if($p != NULL){
            $isdir = ($p&0040000?true:false);
            $changed = false;
            if( ( isSet($uid) && $stat["uid"] == $uid ) || $fixPermPolicy == "user"  ) {
                Logger::debug("upgrading abit to ubit");
                $changed = true;
                $p  = $p&7777770;
                if( $p&0x0100 ) $p += 04;
                if( $p&0x0080 ) $p += 02;
                if( $p&0x0040 ) $p += 01;
            }else if( ( isSet($gid) && $stat["gid"] == $gid )  || $fixPermPolicy == "group"  ) {
                Logger::debug("upgrading abit to gbit");
                $changed = true;
                $p  = $p&7777770;
                if( $p&0x0020 ) $p += 04;
                if( $p&0x0010 ) $p += 02;
                if( $p&0x0008 ) $p += 01;
            }
            if($isdir && $changed){
                $p += 0040000;
            } 
            $stat["mode"] = $stat[2] = $p;
            Logger::debug("FIXED PERM DATA ($fixPermPolicy)",sprintf("%o", ($p & 000777)));
        }
    }

    protected function resetAllPermission($value){

    }

}
