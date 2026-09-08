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
namespace BoA\Core\Utils;

use BoA\Plugins\Core\Log\Logger;

defined('APP_EXEC') or die('Access not allowed');
/**
 *
 * @package BoA
 * @subpackage Core
 *
 */
class ShutdownScheduler
{
    private static $instance;

    private $callbacks; // array to store user callbacks

    /**
     * @static
     * @return ShutdownScheduler
     */
    public static function getInstance(){
        if(self::$instance == null) self::$instance = new ShutdownScheduler();
        return self::$instance;
    }

     public function __construct() {
         $this->callbacks = array();
         register_shutdown_function(array($this, 'callRegisteredShutdown'));
         ob_start();
     }
    public function registerShutdownEventArray() {
        $callback = func_get_args();

        if (empty($callback)) {
            throw new Exception('No callback passed to '.__FUNCTION__.' method');
        }
        if (!is_callable($callback[0])) {
            throw new Exception('Invalid callback ('.$callback[0].') passed to the '.__FUNCTION__.' method');
        }
        $flattenArray = array();
        $flattenArray[0] = $callback[0];
        if(is_array(Utils::arrayGet($callback, 1))) {
            foreach(Utils::arrayGet($callback, 1, array()) as $argument) $flattenArray[] = $argument;
        }
        $this->callbacks[] = $flattenArray;
        return true;
    }
     public function registerShutdownEvent() {
         $callback = func_get_args();

         if (empty($callback)) {
             throw new \Exception('No callback passed to '.__FUNCTION__.' method');
         }
         if (!is_callable($callback[0])) {
             throw new \Exception('Invalid callback ('.$callback[0].') passed to the '.__FUNCTION__.' method');
         }
         $this->callbacks[] = $callback;
         return true;
     }
     public function callRegisteredShutdown() {
        session_write_close();
         if(!headers_sent()){
             $size = ob_get_length();
             header("Connection: close\r\n");
             header("Content-Encoding: none\r\n");
             header("Content-Length: $size");
         }
        ob_end_flush();
        flush();
        foreach ($this->callbacks as $arguments) {
            $callback = array_shift($arguments);
            try{
                call_user_func_array($callback, $arguments);
            }catch (\Exception $e){
                Logger::logAction("error", array("context"=>"Applying hook ".get_class($callback[0])."::".$callback[1],  "message" => $e->getMessage()));
            }
        }
     }
}
