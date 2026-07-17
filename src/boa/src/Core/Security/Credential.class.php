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
namespace BoA\Core\Security;

use BoA\Core\Services\AuthService;

defined('APP_EXEC') or die( 'Access not allowed');

/**
 * Credential keeper that can be stored in the session, the credentials are kept crypted.
 * @package BoA
 * @subpackage Core
 */
class Credential{
	
	private static $instance;
	
	private $user;
	private $encodedPassword;
	private $separator = "__SAFE_SEPARATOR__";
	private $forceSessionCredentials = false;
	/**
     * Instance constructor
     */
	public function __construct(){
	} 
	/**
     * Store the user/password pair. Password will be encoded
     * @param string $user
     * @param string $password
     * @return void
     */
	public function setCredentials($user, $password){
		$this->user = $user;
		$this->encodedPassword = $this->_encodePassword($password, $user);
	}
	/**
     * Return the user/password pair, or false if cannot find it.
     * @return array|bool
     */
	public function getCredentials(){
		if(isSet($this->user) && isSet($this->encodedPassword)){
			$decoded = $this->_decodePassword($this->encodedPassword, $this->user);
			return array(
				"user" 		=> $this->user,
				"password"	=> $decoded,
				0			=> $this->user,
				1			=> $decoded
			);
		}else{
			return false;
		}
	}
	/**
     * Encode password with OpenSSL AES-256-GCM (Crypto helper).
     * @param $password
     * @param $user
     * @return string
     */
	private function _encodePassword($password, $user){
		if (!Crypto::isAvailable()) {
			return $password;
		}
		return Crypto::encrypt($password, 'credential:'.$user);
	}
	/**
     * Decode password; legacy mcrypt payloads return false / empty (no dual-read).
     * @param $encoded
     * @param $user
     * @return string
     */
	private function _decodePassword($encoded, $user){
		$decoded = Crypto::decrypt($encoded, 'credential:'.$user);
		return ($decoded === false) ? '' : $decoded;
	}
	/**
     * Store the password credentials in the session
     * @return void
     */
	public function store(){
		$_SESSION["APP_SAFE_CREDENTIALS"] = base64_encode($this->user.$this->separator.$this->encodedPassword);
	}
	/**
     * Load the credentials from session
     * @return
     */
	public function load($encodedString = ""){
        if($encodedString == "" && !empty($_SESSION["APP_SAFE_CREDENTIALS"])){
            $encodedString = $_SESSION["APP_SAFE_CREDENTIALS"];
        }
		if(empty($encodedString)) return;
		$sessData = base64_decode($encodedString);
		$parts = explode($this->separator, $sessData);
		$this->user = $parts[0];
		$this->encodedPassword = $parts[1];
	}
	/**
     * Remove the credentials from session
     * @return void
     */
	public function clear(){
		unset($_SESSION["APP_SAFE_CREDENTIALS"]);
		$this->user = null;
		$this->encodedPassword = null;
	}
	/**
     * For the session credentials to override other credentials set via config
     * @return void
     */
	public function forceSessionCredentialsUsage(){
		$this->forceSessionCredentials = true;
	}
	
		
	
	/**
	 * Creates the singleton instance
	 * @return Credential
	 */
	public static function getInstance(){
		if(empty(self::$instance)){
			self::$instance = new Credential();
		}
		return self::$instance;
	}
	/**
     * Store the user/pass key pair
     * @static
     * @param string $user
     * @param string $password
     * @return void
     */
	public static function storeCredentials($user, $password){
		$inst = Credential::getInstance();
		$inst->setCredentials($user, $password);
		$inst->store();
	}
	/**
     * Remove the user/pass encoded from the session
     * @static
     * @return void
     */
	public static function clearCredentials(){
		$inst = Credential::getInstance();
		$inst->clear();
	}
	/**
     * Retrieve the user/pass from the session
     * @static
     * @return array|bool
     */
	public static function loadCredentials(){
		$inst = Credential::getInstance();
		$inst->load();
		return $inst->getCredentials();
	}

    public static function getEncodedCredentialString(){
        return $_SESSION["APP_SAFE_CREDENTIALS"];
    }

    public static function getCredentialsFromEncodedString($encoded){
        $tmpInstance = new Credential();
        $tmpInstance->load($encoded);
        return $tmpInstance->getCredentials();
    }
		
	/**
	 * Will try to get the credentials for a given repository as follow :
     * + Try to get the credentials from the url parsing
     * + Try to get them from the user "Wallet" (personal data)
     * + Try to get them from the repository configuration
     * + Try to get them from the Credential.
     * 
	 * @param array $parsedUrl
	 * @param Repository $repository
     * @return array
	 */
	public static function tryLoadingCredentialsFromSources($parsedUrl, $repository){
		$user = $password = "";
		$optionsPrefix = "";
		if($repository->getAccessType() == "ftp"){
			$optionsPrefix = "FTP_";
		}
		// Get USER/PASS
		// 1. Try from URL
		if(isSet($parsedUrl["user"]) && isset($parsedUrl["pass"])){
			$user = rawurldecode($parsedUrl["user"]);
			$password = rawurldecode($parsedUrl["pass"]);
		}
		// 2. Try from user wallet
		if($user==""){
			$loggedUser = AuthService::getLoggedUser();
			if($loggedUser != null){
				$wallet = $loggedUser->getPref("APP_WALLET");
				if(is_array($wallet) && isSet($wallet[$repository->getId()][$optionsPrefix."USER"])){
					$user = $wallet[$repository->getId()][$optionsPrefix."USER"];
					$password = $loggedUser->decodeUserPassword($wallet[$repository->getId()][$optionsPrefix."PASS"]);
				}
			}
		}
        // 2bis. Wallet is now a custom parameter
        if($user ==""){
            $loggedUser = AuthService::getLoggedUser();
            if($loggedUser != null){
                $u = $loggedUser->mergedRole->filterParameterValue("access.".$repository->getAccessType(), $optionsPrefix."USER", $repository->getId(), "");
                $p = $loggedUser->mergedRole->filterParameterValue("access.".$repository->getAccessType(), $optionsPrefix."PASS", $repository->getId(), "");
                if(!empty($u) && !empty($p)){
                    $user = $u;
                    $password = $loggedUser->decodeUserPassword($p);
                }
            }
        }
		// 3. Try from repository config
		if($user==""){
			$user = $repository->getOption($optionsPrefix."USER");
			$password = $repository->getOption($optionsPrefix."PASS");
		}
        // 4. Test if there are encoded credentials available
        if($user == "" && $repository->getOption("ENCODED_CREDENTIALS") != ""){
            list($user, $password) = Credential::getCredentialsFromEncodedString($repository->getOption("ENCODED_CREDENTIALS"));
        }
		// 5. Try from session
		if($user=="" && ( $repository->getOption("USE_SESSION_CREDENTIALS") || self::getInstance()->forceSessionCredentials )){
			$safeCred = Credential::loadCredentials();
			if($safeCred !== false){			
				$user = $safeCred["user"];
				$password = $safeCred["password"];
			}
		}		
		return array("user" => $user, "password" => $password);
		
	}
	
}
