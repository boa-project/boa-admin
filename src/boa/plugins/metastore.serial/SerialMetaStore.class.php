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
namespace BoA\Plugins\Metastore\Serial;

use BoA\Core\Plugins\Plugin;
use BoA\Core\Http\Controller;
use BoA\Core\Services\AuthService;
use BoA\Core\Services\ConfService;
use BoA\Plugins\Core\Metastore\MetaStoreProvider;


defined('APP_EXEC') or die( 'Access not allowed');
/**
 * Simple metadata implementation, stored in hidden files inside the
 * folders
 * @package APP_Plugins
 * @subpackage Metastore
 */
class SerialMetaStore extends Plugin implements MetaStoreProvider {

  private static $currentMetaName;
  private static $metaCache;
  private static $fullMetaCache;

  protected $globalMetaFile;
  protected $accessDriver;


  public function init($options){
    $this->options = $options;
    $this->loadRegistryContributions();
    $this->globalMetaFile = APP_DATA_PATH."/plugins/metastore.serial/metadata";
  }

  public function initMeta($accessDriver){
    $this->accessDriver = $accessDriver;
  }

  /**
   * @abstract
   * @return bool
   */
  public function inherentMetaMove(){
    return false;
  }


  protected function getUserId(){
    if(AuthService::usersEnabled()) return AuthService::getLoggedUser()->getId();
    return "shared";
  }

  public function setMetadata($node, $nameSpace, $metaData, $private = false, $scope=APP_METADATA_SCOPE_REPOSITORY){
    $this->loadMetaFileData(
      $node,
      $scope,
      ($private?$this->getUserId():APP_METADATA_SHAREDUSER)
    );
    if(!isSet(self::$metaCache[$nameSpace])){
      self::$metaCache[$nameSpace] = array();
    }
    self::$metaCache[$nameSpace] = array_merge(self::$metaCache[$nameSpace], $metaData);
    $this->saveMetaFileData(
      $node,
      $scope,
      ($private?$this->getUserId():APP_METADATA_SHAREDUSER)
    );
  }

  public function removeMetadata($node, $nameSpace, $private = false, $scope=APP_METADATA_SCOPE_REPOSITORY){
    $this->loadMetaFileData(
      $node,
      $scope,
      ($private?$this->getUserId():APP_METADATA_SHAREDUSER)
    );
    if(!isSet(self::$metaCache[$nameSpace])) return;
    unset(self::$metaCache[$nameSpace]);
    $this->saveMetaFileData(
      $node,
      $scope,
      ($private?$this->getUserId():APP_METADATA_SHAREDUSER)
    );
  }

  public function retrieveMetadata($node, $nameSpace, $private = false, $scope=APP_METADATA_SCOPE_REPOSITORY){
    $this->loadMetaFileData(
      $node,
      $scope,
      ($private?$this->getUserId():APP_METADATA_SHAREDUSER)
    );
    //var_dump($metaCache);
    //var_dump($nameSpace);
    if(!isSet(self::$metaCache[$nameSpace])) return array();
    else return self::$metaCache[$nameSpace];
  }

  /**
   * @param Node $node
   * @return void
   */
  public function enrichNode(&$node){
    // Try both
    $all = array();
    $this->loadMetaFileData($node, APP_METADATA_SCOPE_GLOBAL, APP_METADATA_SHAREDUSER);
    $all[] = self::$metaCache;
    $this->loadMetaFileData($node, APP_METADATA_SCOPE_GLOBAL, $this->getUserId());
    $all[] = self::$metaCache;
    $this->loadMetaFileData($node, APP_METADATA_SCOPE_REPOSITORY, APP_METADATA_SHAREDUSER);
    $all[] = self::$metaCache;
    $this->loadMetaFileData($node, APP_METADATA_SCOPE_REPOSITORY, $this->getUserId());
    $all[] = self::$metaCache;
    $allMeta = array();
    foreach($all as $metadata){
      foreach($metadata as $namespace => $meta){
        foreach( $meta as $key => $value){
          $allMeta[$namespace."-".$key] = $value;
        }
      }
    }
    $node->mergeMetadata($allMeta);
  }

  protected function updateSecurityScope($metaFile, $repositoryId){

    $repo = ConfService::getRepositoryById($repositoryId);
    if(!is_object($repo)) {
      return $metaFile;
    }
    $securityScope = $repo->securityScope();
    if($securityScope == false) return $metaFile;

    if(AuthService::getLoggedUser() != null){
      if($securityScope == "USER"){
        $u = AuthService::getLoggedUser();
        if($u->getResolveAsParent()) $id = $u->getParent();
        else $id = $u->getId();
        $metaFile .= "_".$id;
      }else if($securityScope == "GROUP"){
        $u = AuthService::getLoggedUser()->getGroupPath();
        $u = str_replace("/", "__", $u);
        $metaFile .= "_".$u;
      }
    }
    return $metaFile;
  }

  /**
   * @param Node $node
   * @param String $scope
   * @param String $userId
   * @return void
   */
  protected function loadMetaFileData($node, $scope, $userId, $p=2){
    $currentFile = $node->getUrl();
    $fileKey = $node->getPath();
    if($fileKey == null) $fileKey = "/";

    if(isSet($this->options["METADATA_FILE_LOCATION"]) && $this->options["METADATA_FILE_LOCATION"] == "outside"){
      // Force scope
      $scope = APP_METADATA_SCOPE_REPOSITORY;
    }

    if($scope == APP_METADATA_SCOPE_GLOBAL){
      $metaFile = dirname($currentFile)."/".$this->options["METADATA_FILE"];
      if(preg_match("/\.zip\//",$currentFile)){
        self::$fullMetaCache[$metaFile] = array();
        self::$metaCache = array();
        return ;
      }
      $fileKey = basename($fileKey);
    }else{
      $metaFile = $this->globalMetaFile."_".$node->getRepositoryId();
      $metaFile = $this->updateSecurityScope($metaFile, $node->getRepositoryId());
    }
    self::$metaCache = array();

    if(!isSet(self::$fullMetaCache[$metaFile])){
      self::$currentMetaName = $metaFile;
      $rawData = @file_get_contents($metaFile);

      if($rawData !== false){
        if (preg_match("/^[\[\{]/",$rawData)) {
          self::$fullMetaCache[$metaFile] = json_decode($rawData, true);
        }
        else {
          self::$fullMetaCache[$metaFile] = unserialize($rawData);
        }
      }
    }
    if(isSet(self::$fullMetaCache[$metaFile]) && is_array(self::$fullMetaCache[$metaFile])){
      if(isSet(self::$fullMetaCache[$metaFile][$fileKey][$userId])){
        self::$metaCache = self::$fullMetaCache[$metaFile][$fileKey][$userId];
      }else{
        if(isset($this->options["UPGRADE_FROM_METASERIAL"]) && $this->options["UPGRADE_FROM_METASERIAL"] == true &&
            count(self::$fullMetaCache[$metaFile]) && !isSet(self::$fullMetaCache[$metaFile]["APP_METASTORE_UPGRADED"])){

          self::$fullMetaCache[$metaFile] = $this->upgradeDataFromMetaSerial(self::$fullMetaCache[$metaFile]);
          if(isSet(self::$fullMetaCache[$metaFile][$fileKey][$userId])){
            self::$metaCache = self::$fullMetaCache[$metaFile][$fileKey][$userId];
          }
          // Save upgraded version
          $data = json_encode(self::$fullMetaCache[$metaFile], JSON_PRETTY_PRINT);
          //serialize(self::$fullMetaCache[$metaFile])
          file_put_contents($metaFile, $data);
        }
      }
    }else{
      self::$fullMetaCache[$metaFile] = array();
      self::$metaCache = array();
    }
  }

  /**
   * @param Node $node
   * @param String $scope
   * @param String $userId
   */
  protected function saveMetaFileData($node, $scope, $userId){
    $currentFile = $node->getUrl();
    $repositoryId = $node->getRepositoryId();
    $fileKey = $node->getPath();
    if(isSet($this->options["METADATA_FILE_LOCATION"]) && $this->options["METADATA_FILE_LOCATION"] == "outside"){
      // Force scope
      $scope = APP_METADATA_SCOPE_REPOSITORY;
    }
    if($scope == APP_METADATA_SCOPE_GLOBAL){
      $metaFile = dirname($currentFile)."/".$this->options["METADATA_FILE"];
      $fileKey = basename($fileKey);
    }else{
      if(!is_dir(dirname($this->globalMetaFile))){
        mkdir(dirname($this->globalMetaFile), 0755, true);
      }
      $metaFile = $this->globalMetaFile."_".$repositoryId;
      $metaFile = $this->updateSecurityScope($metaFile, $node->getRepositoryId());
    }
    if($scope==APP_METADATA_SCOPE_REPOSITORY
      || (@is_file($metaFile) && call_user_func(array($this->accessDriver, "isWriteable"), $metaFile))
      || call_user_func(array($this->accessDriver, "isWriteable"), dirname($metaFile)) ){
      if(is_array(self::$metaCache) && count(self::$metaCache)){
        if(!isset(self::$fullMetaCache[$metaFile])){
          self::$fullMetaCache[$metaFile] = array();
        }
        if(!isset(self::$fullMetaCache[$metaFile][$fileKey])){
          self::$fullMetaCache[$metaFile][$fileKey] = array();
        }
        if(!isset(self::$fullMetaCache[$metaFile][$fileKey][$userId])){
          self::$fullMetaCache[$metaFile][$fileKey][$userId] = array();
        }
        self::$fullMetaCache[$metaFile][$fileKey][$userId] = self::$metaCache;
      }else{
        // CLEAN
        if(isset(self::$fullMetaCache[$metaFile][$fileKey][$userId])){
          unset(self::$fullMetaCache[$metaFile][$fileKey][$userId]);
        }
        if(isset(self::$fullMetaCache[$metaFile][$fileKey])
          && !count(self::$fullMetaCache[$metaFile][$fileKey])){
          unset(self::$fullMetaCache[$metaFile][$fileKey]);
        }
      }
      $fp = fopen($metaFile, "w");
      if($fp !== false){
        $data = json_encode(self::$fullMetaCache[$metaFile], JSON_PRETTY_PRINT);
        //serialize(self::$fullMetaCache[$metaFile])
        @fwrite($fp, $data, strlen($data));
        @fclose($fp);
      }
      if($scope == APP_METADATA_SCOPE_GLOBAL){
         Controller::applyHook("version.commit_file", array($metaFile, $node));
      }
    }
  }

  protected function upgradeDataFromMetaSerial($data){
    $new = array();
    foreach ($data as $fileKey => $fileData){
      $new[$fileKey] = array(APP_METADATA_SHAREDUSER => array( "users_meta" => $fileData ));
      $new["APP_METASTORE_UPGRADED"] = true;
    }
    return $new;
  }



}
