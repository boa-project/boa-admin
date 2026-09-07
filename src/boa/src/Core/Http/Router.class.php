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
 * Description : main access point of the application, this script is called by any Ajax query.
 * Will dispatch the actions on the plugins.
 */
namespace BoA\Core\Http;

use BoA\Core\Http\Controller;
use BoA\Core\Services\AuthService;
use BoA\Core\Services\ConfService;
use BoA\Core\Services\PluginsService;
use BoA\Core\Http\XMLWriter;
use BoA\Plugins\Core\Log;

defined('APP_EXEC') or die('Access not allowed');

class Router {
    const APP_SESSION_COOKIE = "BoA";
    /**
     * Router constructor.
     * @param array $cacheOptions
     */
    public function __construct(){

    }

    /**
     *
     *
     */
    public function route() {

        if( !isSet($_GET["action"]) && !isSet($_GET["get_action"])
            && !isSet($_POST["action"]) && !isSet($_POST["get_action"])
            && defined("APP_FORCE_SSL_REDIRECT") && APP_FORCE_SSL_REDIRECT === true
            && $_SERVER['SERVER_PORT'] != 443) {
            header("HTTP/1.1 301 Moved Permanently");
            header("Location: https://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']);
            exit();
        }

        if(isSet($_GET[self::APP_SESSION_COOKIE]))
        {
            // Don't overwrite cookie
            if (!isSet($_COOKIE[self::APP_SESSION_COOKIE]))
                $_COOKIE[self::APP_SESSION_COOKIE] = $_GET["app_sessid"];
        }

        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
        header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
        header("Cache-Control: no-cache, must-revalidate");
        header("Pragma: no-cache");

        if(is_file(TESTS_RESULT_FILE)){
            set_error_handler(array("\BoA\Core\Http\XMLWriter", "catchError"), E_ALL & ~E_NOTICE & ~E_STRICT );
            set_exception_handler(array("\BoA\Core\Http\XMLWriter", "catchException"));
        }

        $pServ = PluginsService::getInstance();
        ConfService::init();
        $confPlugin = ConfService::getInstance()->confPluginSoftLoad($pServ);
        try{
            $pServ->loadPluginsRegistry(APP_PLUGINS_FOLDER, $confPlugin);
        }catch (\Exception $e){
            die("Severe error while loading plugins registry : ".$e->getMessage());
        }
        ConfService::start();

        $confStorageDriver = ConfService::getConfStorageImpl();
        require_once($confStorageDriver->getUserClassFileName());

        if(!isSet($OVERRIDE_SESSION)){
            session_name(self::APP_SESSION_COOKIE);
        }
        session_start();

        if(isSet($_GET["tmp_repository_id"])){
            ConfService::switchRootDir($_GET["tmp_repository_id"], true);
        }else if(isSet($_SESSION["SWITCH_BACK_REPO_ID"])){
            ConfService::switchRootDir($_SESSION["SWITCH_BACK_REPO_ID"]);
            unset($_SESSION["SWITCH_BACK_REPO_ID"]);
        }
        $action = "ping";
        if(preg_match('/MSIE 7/',$_SERVER['HTTP_USER_AGENT']) || preg_match('/MSIE 8/',$_SERVER['HTTP_USER_AGENT'])){
            $action = "get_boot_gui";
        }else{
            $action = (strpos($_SERVER["HTTP_ACCEPT"], "text/html") !== false ? "get_boot_gui" : "ping");
        }
        if(isSet($_GET["action"]) || isSet($_GET["get_action"])) $action = (isset($_GET["get_action"])?$_GET["get_action"]:$_GET["action"]);
        else if(isSet($_POST["action"]) || isSet($_POST["get_action"])) $action = (isset($_POST["get_action"])?$_POST["get_action"]:$_POST["action"]);

        $pluginsUnSecureActions = ConfService::getDeclaredUnsecureActions();
        $unSecureActions = array_merge($pluginsUnSecureActions, array("get_secure_token"));
        if(!in_array($action, $unSecureActions) && AuthService::getSecureToken()){
            $token = "";
            if(isSet($_GET["secure_token"])) $token = $_GET["secure_token"];
            else if(isSet($_POST["secure_token"])) $token = $_POST["secure_token"];
            if( $token == "" || !AuthService::checkSecureToken($token)){
                throw new \Exception("You are not allowed to access this resource.");
            }
        }

        if(AuthService::usersEnabled())
        {
            $httpVars = array_merge($_GET, $_POST);

            AuthService::logUser(null, null);
            // Check that current user can access current repository, try to switch otherwise.
            $loggedUser = AuthService::getLoggedUser();
            if($loggedUser == null) {
                // Try prelogging user if the session expired but the logging data is in fact still present
                // For example, for basic_http auth.
                AuthService::preLogUser((isSet($httpVars["remote_session"])?$httpVars["remote_session"]:""));
                $loggedUser = AuthService::getLoggedUser();
                if($loggedUser == null) {
                    $requireAuth = true;
                }
            }
            if($loggedUser != null)
            {
                   $res = ConfService::switchUserToActiveRepository($loggedUser, (isSet($httpVars["tmp_repository_id"])?$httpVars["tmp_repository_id"]:"-1"));
                   if(!$res){
                       AuthService::disconnect();
                       $requireAuth = true;
                   }
            }

        }else{
            Logger::debug(ConfService::getCurrentRepositoryId());
        }

        //Set language
        $loggedUser = AuthService::getLoggedUser();
        if($loggedUser != null && $loggedUser->getPref("lang") != "") ConfService::setLanguage($loggedUser->getPref("lang"));
        else if(isSet($_COOKIE["APP_lang"])) ConfService::setLanguage($_COOKIE["APP_lang"]);

        //------------------------------------------------------------
        // SPECIAL HANDLING FOR FANCY UPLOADER RIGHTS FOR THIS ACTION
        //------------------------------------------------------------
        if(AuthService::usersEnabled())
        {
            $loggedUser = AuthService::getLoggedUser();
            if($action == "upload" && ($loggedUser == null || !$loggedUser->canWrite(ConfService::getCurrentRepositoryId()."")) && isSet($_FILES['Filedata']))
            {
                header('HTTP/1.0 ' . '410 Not authorized');
                die('Error 410 Not authorized!');
            }
        }

        // THIS FIRST DRIVERS DO NOT NEED ID CHECK
        $authDriver = ConfService::getAuthDriverImpl();
        // DRIVERS BELOW NEED IDENTIFICATION CHECK
        if(!AuthService::usersEnabled() || ConfService::getCoreConf("ALLOW_GUEST_BROWSING", "auth") || AuthService::getLoggedUser()!=null){
            $confDriver = ConfService::getConfStorageImpl();
            $Driver = ConfService::loadRepositoryDriver();
        }
        PluginsService::getInstance()->initActivePlugins();
        $xmlResult = Controller::findActionAndApply($action, array_merge($_GET, $_POST), $_FILES);

        if ($action == 'get_drop_bg') {
            var_dump($xmlResult);
            die('action end');
        }

        if($xmlResult !== false && $xmlResult != ""){
            XMLWriter::header();
            print($xmlResult);
            XMLWriter::close();
        }else if(isset($requireAuth) && Controller::$lastActionNeedsAuth){
            XMLWriter::header();
            XMLWriter::requireAuth();
            XMLWriter::close();
        }

        session_write_close();
    }
}
