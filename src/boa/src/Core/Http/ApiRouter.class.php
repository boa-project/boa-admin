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

use BoA\Core\Services\ConfService;
use BoA\Core\Services\PluginsService;

defined('APP_EXEC') or die('Access not allowed');

class ApiRouter {
    /**
     * Router constructor.
     * @param array $cacheOptions
     */
    public function __construct(){

    }

    public static function init(){
        $pServ = PluginsService::getInstance();
        ConfService::init();
        $confPlugin = ConfService::getInstance()->confPluginSoftLoad($pServ);

        try{
            $pServ->loadPluginsRegistry(APP_PLUGINS_FOLDER, $confPlugin);
        }catch (\Exception $e){
            die("Severe error while loading plugins registry : ".$e->getMessage());
        }
        ConfService::start();
    }

    /**
     *
     *
     */
    public function run(){
        $uri = $_SERVER["REQUEST_URI"];
        // dirname("/index.rest.php") is "/" — avoid producing "//api/"
        $dir = str_replace('\\', '/', dirname($_SERVER["SCRIPT_NAME"]));
        if ($dir === '/' || $dir === '') {
            $scriptUri = '/api/';
        } else {
            $scriptUri = rtrim($dir, '/') . '/api/';
        }
        if (strpos($uri, $scriptUri) === 0) {
            $uri = substr($uri, strlen($scriptUri));
        } elseif (preg_match('#/api/(.*)$#', $uri, $m)) {
            $uri = $m[1];
        } else {
            $uri = ltrim($uri, '/');
        }
        // Strip query string.
        if (($q = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $q);
        }

        $matches = [];
        if (preg_match("/^catalogs(?:\/(\S+))*/", $uri, $matches)){
            if (count($matches) > 1){
                $id = $matches[1];
                return $this->getCatalog($id);
            }

            return $this->getCatalogs();
        }
    }

    private function getCatalog($id){
        $repo = ConfService::findRepositoryByIdOrAlias($id);
        header('Content-Type: application/json');
        echo json_encode($this->getRepoView($repo));
    }

    private function getCatalogs(){
        $repos = ConfService::getRepositoriesList();
        header('Content-Type: application/json');
        $data = array_reduce($repos, function($result, $repo) { $result[] = $this->getRepoView($repo); return $result; }, array());
        echo json_encode($data);
    }

    private function getRepoView($repo){
        return empty($repo) ? null :
            array(
                "id" => $repo->id,
                "alias" => $repo->slug,
                "type" => $repo->accessType,
                "name" => $repo->display,
                "path" => str_replace("APP_DATA_PATH", APP_DATA_PATH, $repo->options["PATH"] ?? "")
                ); 
    }
}