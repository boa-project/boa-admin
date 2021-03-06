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
namespace BoA\Plugins\Core\Auth;

use BoA\Core\Plugins\Plugin;
use BoA\Core\Services\ConfService;
use BoA\Core\Services\PluginsService;

defined('APP_EXEC') or die( 'Access not allowed');

/**
 * Config loader overrider
 * @package APP_Plugins
 * @subpackage Core
 */
class CoreAuthLoader extends Plugin{

    /**
     * @var AbstractAuthDriver
     */
    protected  static $authStorageImpl;

	public function getConfigs(){
		$configs = parent::getConfigs();
		$configs["ALLOW_GUEST_BROWSING"] = !isSet($_SERVER["HTTP_APP_FORCE_LOGIN"]) && ($configs["ALLOW_GUEST_BROWSING"] === "true" || $configs["ALLOW_GUEST_BROWSING"] === true || intval($configs["ALLOW_GUEST_BROWSING"]) == 1);
		return $configs;
	}

    public function getAuthImpl(){
        if(!isSet(self::$authStorageImpl)){
            if(!isSet($this->pluginConf["MASTER_INSTANCE_CONFIG"])){
                throw new Exception("Please set up at least one MASTER_INSTANCE_CONFIG in core.auth options");
            }
            $masterName = is_array($this->pluginConf["MASTER_INSTANCE_CONFIG"]) ? $this->pluginConf["MASTER_INSTANCE_CONFIG"]["instance_name"] : $this->pluginConf["MASTER_INSTANCE_CONFIG"];
            $masterName = str_replace("auth.", "", $masterName);
            if(!empty($this->pluginConf["SLAVE_INSTANCE_CONFIG"])){
                $slaveName = is_array($this->pluginConf["SLAVE_INSTANCE_CONFIG"]) ? $this->pluginConf["SLAVE_INSTANCE_CONFIG"]["instance_name"] : $this->pluginConf["SLAVE_INSTANCE_CONFIG"];
                $slaveName = str_replace("auth.", "", $slaveName);
                // Manually set up a multi config

                $userBase = $this->pluginConf["MULTI_USER_BASE_DRIVER"];
                if($userBase == "master") $baseName = $masterName;
                else if($userBase == "slave") $baseName = $slaveName;
                else $baseName = "";

                $mLabel = ""; $sLabel = "";$separator = "";
                if($this->pluginConf["MULTI_MODE"]["instance_name"] == "USER_CHOICE"){
                    $mLabel = $this->pluginConf["MULTI_MODE"]["MULTI_MASTER_LABEL"];
                    $sLabel = $this->pluginConf["MULTI_MODE"]["MULTI_SLAVE_LABEL"];
                    $separator = $this->pluginConf["MULTI_MODE"]["MULTI_USER_ID_SEPARATOR"];
                }
                $newOptions = array(
                    "instance_name" => "auth.multi",
                    "MODE" => $this->pluginConf["MULTI_MODE"]["instance_name"],
                    "MASTER_DRIVER" => $masterName,
                    "USER_BASE_DRIVER" => $baseName,
                    "USER_ID_SEPARATOR" => $separator,
                    "TRANSMIT_CLEAR_PASS" => $this->pluginConf["TRANSMIT_CLEAR_PASS"],
                    "DRIVERS" => array(
                        $masterName => array(
                            "NAME" => $masterName,
                            "LABEL" => $mLabel,
                            "OPTIONS" => $this->pluginConf["MASTER_INSTANCE_CONFIG"]
                        ),
                        $slaveName => array(
                            "NAME" => $slaveName,
                            "LABEL" => $sLabel,
                            "OPTIONS" => $this->pluginConf["SLAVE_INSTANCE_CONFIG"]
                        ),
                    )
                );
                // MERGE BASIC AUTH OPTIONS FROM MASTER
                $masterMainAuthOptions = array();
                $keys = array("TRANSMIT_CLEAR_PASS", "AUTOCREATE_USER", "LOGIN_REDIRECT", "APP_ADMIN_LOGIN");
                if(is_array($this->pluginConf["MASTER_INSTANCE_CONFIG"])){
                    foreach($keys as $key){
                        if(isSet($this->pluginConf["MASTER_INSTANCE_CONFIG"][$key])){
                            $masterMainAuthOptions[$key] = $this->pluginConf["MASTER_INSTANCE_CONFIG"][$key];
                        }
                    }
                }
                $newOptions = array_merge($newOptions, $masterMainAuthOptions);
                self::$authStorageImpl = ConfService::instanciatePluginFromGlobalParams($newOptions, "AbstractAuthDriver");
                PluginsService::getInstance()->setPluginUniqueActiveForType("auth", self::$authStorageImpl->getName(), self::$authStorageImpl);

            }else{
                self::$authStorageImpl = ConfService::instanciatePluginFromGlobalParams($this->pluginConf["MASTER_INSTANCE_CONFIG"], "BoA\Plugins\Core\Auth\AbstractAuthDriver");
                PluginsService::getInstance()->setPluginUniqueActiveForType("auth", self::$authStorageImpl->getName());
            }
        }
        return self::$authStorageImpl;
    }


}