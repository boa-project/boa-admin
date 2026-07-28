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
namespace BoA\Plugins\Access\Dco;

use BoA\Core\Access\FileWrapperProvider;
use BoA\Core\Access\RecycleBinManager;
use BoA\Core\Access\UserSelection;
use BoA\Core\Exceptions\ApplicationException;
use BoA\Core\Http\Controller;
use BoA\Core\Http\HTMLWriter;
use BoA\Core\Http\XMLWriter;
use BoA\Core\Security\Credential;
use BoA\Core\Services\AuthService;
use BoA\Core\Services\ConfService;
use BoA\Core\Utils\Utils;
use BoA\Core\Utils\Text\SystemTextEncoding;
use BoA\Core\Xml\ManifestNode;
use BoA\Plugins\Core\Access\AbstractAccessDriver;
use BoA\Plugins\Core\Log\Logger;


defined('APP_EXEC') or die( 'Access not allowed');

/**
 * Plugin to access a filesystem. Most "FS" like driver (even remote ones)
 * extend this one.
 * @package APP_Plugins
 * @subpackage Access
 */

class DcoExplorer{
    /** @var DocAccessDriver The access driver implemented for Dco */
    private $_driver;

    function __construct($driver){
        $this->_driver = $driver;
    }

    public function getAll($dir, $page, $httpVars){        
        if(!isSet($dir) || $dir == "/") $dir = "";

        $options = array();
        $driver = $this->_driver;        
        $options["options"] = $this->parseLsOptions((isSet($httpVars["options"])?$httpVars["options"]:"a"));
        $startTime = microtime(true);
        if(isSet($httpVars["file"])){
            $options["file"] = Utils::decodeSecureMagic(Utils::arrayGet($httpVars, "file"));
        }
        $dir = Utils::securePath(SystemTextEncoding::magicDequote($dir));
        $path = $driver->urlBase;
        if($dir != ""){
            $path .= ($dir[0]=="/"?"":"/").$dir;
        }else{
            $path .= "/";
        }
        $nonPatchedPath = $path;
        if($driver->wrapperClassName == DcoAccessDriver::DEFAULT_ACCESSWRAPPER_CLASSNAME){
            $nonPatchedPath = DcoAccessWrapper::unPatchPathForBaseDir($path);
        }

        $options["dir"] = $dir;
        $options["path"] = $path;
        $options["nonPatchedPath"] = $nonPatchedPath;

        if ($dir == "") {
            $this->readRootPath($options, $page);
        }
        else{
            $this->readObjectContent($options, $page);
        }
        Logger::debug("LS Time : ".intval((microtime(true)-$startTime)*1000)."ms");
    }

    private function readRootPath($options, $page){
        $driver = $this->_driver;
        list( $objects, $totalPages, $crtPage, $countFiles ) = $this->listObjects($options, $page);
        $title_string = $driver->mess["access_dco.dco_title"];
        $contype_string = $driver->mess["access_dco.dco_contype"];
        $type_string = $driver->mess["access_dco.dco_type"];
        $author_string = $driver->mess["access_dco.dco_author"];
        $status_string = $driver->mess["access_dco.dco_status"] ?? "Status";
        XMLWriter::header();        
        XMLWriter::sendFilesListComponentConfig('<columns switchDisplayMode="detail" switchGridMode="filelist"><column messageString="'.$title_string.'" attributeName="APP_label" sortType="String"/><column messageString="'.$type_string.'" attributeName="type" sortType="String"/><column messageString="'.$author_string.'" attributeName="author" sortType="String"/><column messageString="'.$status_string.'" attributeName="status" sortType="String" additionalText="date:lastpublished"/></columns>');
        //<column messageString="'.$contype_string.'" attributeName="conexion_type" sortType="String"/>
        foreach ($objects as $dco){
            $dco->loadNodeInfo(false, false, "all");
            XmlWriter::renderManifestNode($dco);
        }
        $this->renderPagination($options, $totalPages, $crtPage, $countFiles);
        XMLWriter::close();
    }

    private function listObjects($options, $page){
        $driver = $this->_driver;
        $threshold = $driver->repository->getOption("PAGINATION_THRESHOLD");
        if(!isSet($threshold) || intval($threshold) == 0) $threshold = 500;
        $limitPerPage = $driver->repository->getOption("PAGINATION_NUMBER");
        if(!isset($limitPerPage) || intval($limitPerPage) == 0) $limitPerPage = 200;

        $path = call_user_func(array($driver->wrapperClassName, "getRealFSReference"), Utils::arrayGet($options, "path"));
        $nonPatchedPath = Utils::arrayGet($options, "nonPatchedPath");
        $totalPages = null;
        $crtPage = null;

        if (array_key_exists("file", $options)){
            $entries = glob($path."/".Utils::arrayGet($options, "file")."/{.}manifest", GLOB_NOSORT|GLOB_BRACE);
            if (count($entries)){
                $node = $this->getDcoManifestNode(Utils::arrayGet($options, "path")."/".Utils::arrayGet($options, "file")."/.manifest"); //$entries[0]
                return array(array($node), $totalPages, $crtPage, 1);
            }
            return array(array(), $totalPages, $crtPage, 0);
        }

        $entries = glob($path."/*/{.}manifest", GLOB_NOSORT|GLOB_BRACE);
        $countFiles = count($entries);
        if($countFiles > $threshold){
            $offset = 0;
            $crtPage = 1;
            if(isSet($page)){
                $offset = (intval($page)-1)*$limitPerPage;
                $crtPage = $page;
            }
            $totalPages = floor($countFiles / $limitPerPage) + 1;
        }else{
            $offset = $limitPerPage = 0;
        }

        $objects = array();

        $cursor = 0;
        foreach ($entries as $entry){
            if($offset > 0 && $cursor < $offset){
                $cursor ++;
                continue;
            }
            if($limitPerPage > 0 && ($cursor - $offset) >= $limitPerPage) {
                break;
            }
            $dco = $this->getDcoManifestNode(Utils::arrayGet($options, "path")."/".basename(dirname($entry))."/.manifest");
            $objects[] = $dco;
            $cursor ++;
        }

        return [ $objects, $totalPages, $crtPage, $countFiles ];
    }

    public function getDcoManifestNode($manifestPath, $nonPatchedPath=null) {
        $json = $this->parseDcoManifest($manifestPath);
        $meta = $json->manifest;
        $meta["APP_mime"] = "dco";
        $meta["manifest"] = json_encode($json->manifest);
        $title = Utils::arrayGet($meta, "title");
        $node = new ManifestNode(dirname($manifestPath), $meta);
        $node->setLabel($title);
        return $node;
    }

    private function parseDcoManifest($manifestPath){
        $content = file_get_contents($manifestPath);
        $json = json_decode($content);
        $specs = (array)$this->_driver->metaPlugin->loadSpecs();
        $manifest = array();
        foreach (get_object_vars($json->manifest) as $key => $value){
            if ($key == "type"){
                $manifest[$key] = array_key_exists($value, $specs) ? $specs[$value] : $value;
                $manifest[$key."_id"] = $value;
            }
            else if ($key == 'status'){
                $manifest[$key] = $this->_driver->mess["access_dco.".$value];
                $manifest[$key."_id"] = $value;
            }
            else{
                $manifest[$key] = $value;
            }
        }
        $json->manifest = $manifest;
        return $json;
    }

    private function readObjectContent($options, $page){
        $startTime = microtime(true);
        $driver = $this->_driver;
        $mess = $driver->mess;
        $dir = Utils::arrayGet($options, "dir");

        $lsOptions = Utils::arrayGet($options, "options");
        $uniqueFile = null;
        if (array_key_exists("file", $options)){
            $uniqueFile = Utils::arrayGet($options, "file");    
        }    

        $path = Utils::arrayGet($options, "path"); // $driver->urlBase.($dir!= ""?($dir[0]=="/"?"":"/").$dir:"");
        $nonPatchedPath = Utils::arrayGet($options, "nonPatchedPath"); // $path;
        $threshold = $driver->repository->getOption("PAGINATION_THRESHOLD");
        if(!isSet($threshold) || intval($threshold) == 0) $threshold = 500;
        $limitPerPage = $driver->repository->getOption("PAGINATION_NUMBER");
        if(!isset($limitPerPage) || intval($limitPerPage) == 0) $limitPerPage = 200;
        
        $countFiles = $this->countFiles($path, !Utils::arrayGet($lsOptions, "f"));
        $totalPages = null;
        $crtPage = null;
        if($countFiles > $threshold){
            if(isSet($uniqueFile)){
                $originalLimitPerPage = $limitPerPage;
                $offset = $limitPerPage = 0;
            }else{
                $offset = 0;
                $crtPage = 1;
                if(isSet($page)){
                    $offset = (intval($page)-1)*$limitPerPage;
                    $crtPage = $page;
                }
                $totalPages = floor($countFiles / $limitPerPage) + 1;
            }
        }else{
            $offset = $limitPerPage = 0;
        }

        $metaData = array();
        if(RecycleBinManager::recycleEnabled() && $dir == ""){
            $metaData["repo_has_recycle"] = "true";
        }
        
        if ("/".basename($path) === $dir){
            $parentManifestNode = $this->getDcoManifestNode($path."/.manifest"); //$entries[0]
        }
        else{
            $parentManifestNode = new ManifestNode($nonPatchedPath, $metaData);

            //Set a readable label for content and src folders
            if (preg_match('/^\/[^\/]+\/(content|src)$/', $dir, $matches)){
                $parentManifestNode->setLabel($driver->mess["access_dco.{$matches[1]}_string"]);
                $parentManifestNode->mergeMetadata(array("readonly" => true));
            }
        }

        $parentManifestNode->loadNodeInfo(false, true, (Utils::arrayGet($lsOptions, "l")?"all":"minimal"));
        Controller::applyHook("node.read", array(&$parentManifestNode));

        if(XMLWriter::$headerSent == "tree"){
            XMLWriter::renderManifestNode($parentManifestNode, false);
        }else{
            XMLWriter::renderManifestHeaderNode($parentManifestNode);
        }

        XMLWriter::sendFilesListComponentConfig('<columns switchGridMode="filelist">
                    <column messageId="1" attributeName="APP_label" sortType="StringDirFile" defaultWidth="48%"/>
                    <column messageId="2" attributeName="filesize" sortType="CellSorterValue" modifier="FilesList.prototype.partSizeCellRenderer" defaultWidth="9%"/>
                    <column messageId="3" attributeName="mimestring" sortType="String" defaultWidth="5%" defaultVisibilty="hidden"/>
                    <column messageId="4" attributeName="modiftime" sortType="MyDate" defaultWidth="19%"/>
                </columns>');

        $this->renderPagination($options, $totalPages, $crtPage, $countFiles);

        $cursor = 0;
        $handle = opendir($path);
        if(!$handle) {
            throw new ApplicationException("Cannot open dir ".$nonPatchedPath);
        }
        closedir($handle);
        $fullList = array("d" => array(), "z" => array(), "f" => array());
        $nodes = scandir($path);
        if(!empty(Utils::arrayGet($this->_driver->driverConf, "SCANDIR_RESULT_SORTFONC"))){
            usort($nodes, Utils::arrayGet($this->_driver->driverConf, "SCANDIR_RESULT_SORTFONC"));
        }
        //while(strlen($nodeName = readdir($handle)) > 0){
        foreach ($nodes as $nodeName){
            if($nodeName == "." || $nodeName == ".." || $nodeName == ".alternate") continue; //skip special directories including alternate folder
            if (preg_match("/^\\.(:?.*)\\.manifest(:?\\.published)?$/", $nodeName)) continue; //Skip manifest files
            if(isSet($uniqueFile) && $nodeName != $uniqueFile){
                $cursor ++;
                continue;
            }
            $isLeaf = "";
            if(!$driver->filterNodeName($path, $nodeName, $isLeaf, $lsOptions)){
                continue;
            }
            if(RecycleBinManager::recycleEnabled() && $dir == "" && "/".$nodeName == RecycleBinManager::getRecyclePath()){
                continue;
            }

            if($offset > 0 && $cursor < $offset){
                $cursor ++;
                continue;
            }

            if($limitPerPage > 0 && ($cursor - $offset) >= $limitPerPage) {
                break;
            }

            $currentFile = $nonPatchedPath."/".$nodeName;
            $meta = array();
            if($isLeaf != "") $meta = array("is_file" => ($isLeaf?"1":"0"));
            $node = new ManifestNode($currentFile, $meta);
            //Set a readable label for content and src folders
            if (preg_match('/^\/[^\/]+\/(content|src)$/', $node->getPath(), $matches)){ 
                $node->setLabel($driver->mess["access_dco.{$matches[1]}_string"]);
                $node->mergeMetadata(array("readonly" => true));
            }
            else{
                $node->setLabel($nodeName);
            }
            $node->loadNodeInfo(false, false, (Utils::arrayGet($lsOptions, "l")?"all":"minimal"));
            if(!empty($node->metaData["nodeName"]) && $node->metaData["nodeName"] != $nodeName){
                $node->setUrl($nonPatchedPath."/".$node->metaData["nodeName"]);
            }
            if(!empty($node->metaData["hidden"]) && $node->metaData["hidden"] === true){
                continue;
            }
            if(!empty($node->metaData["mimestring_id"]) && array_key_exists($node->metaData["mimestring_id"], $mess)){
                $node->mergeMetadata(array("mimestring" =>  $mess[$node->metaData["mimestring_id"]]));
            }
            if(isSet($originalLimitPerPage) && $cursor > $originalLimitPerPage){
                $node->mergeMetadata(array("page_position" => floor($cursor / $originalLimitPerPage) +1));
            }

            $nodeType = "d";
            if($node->isLeaf()){
                if(Utils::isBrowsableArchive($nodeName)) {
                    if(Utils::arrayGet($lsOptions, "f") && Utils::arrayGet($lsOptions, "z")){
                        $nodeType = "f";
                    }else{
                        $nodeType = "z";
                    }
                }
                else $nodeType = "f";
            }

            $fullList[$nodeType][$nodeName] = $node;
            $cursor ++;
            if(isSet($uniqueFile) && $nodeName != $uniqueFile){
                return;
            }
        }
        if(isSet($httpVars["recursive"]) && $httpVars["recursive"] == "true"){
            foreach(Utils::arrayGet($fullList, "d", array()) as $nodeDir){
                $this->switchAction("ls", array(
                    "dir" => SystemTextEncoding::toUTF8($nodeDir->getPath()),
                    "options"=> $httpVars["options"] ?? "a",
                    "recursive" => "true"
                ), array());
            }
        }else{
            array_map(array("BoA\Core\Http\XMLWriter", "renderManifestNode"), Utils::arrayGet($fullList, "d"));
        }
        array_map(array("BoA\Core\Http\XMLWriter", "renderManifestNode"), Utils::arrayGet($fullList, "z"));
        array_map(array("BoA\Core\Http\XMLWriter", "renderManifestNode"), Utils::arrayGet($fullList, "f"));

        // ADD RECYCLE BIN TO THE LIST
        if($dir == ""  && !$uniqueFile && RecycleBinManager::recycleEnabled() && Utils::arrayGet($driver->driverConf, "HIDE_RECYCLE") !== true)
        {
            $recycleBinOption = RecycleBinManager::getRelativeRecycle();
            if(file_exists($driver->urlBase.$recycleBinOption)){
                $recycleNode = new ManifestNode($driver->urlBase.$recycleBinOption);
                $recycleNode->loadNodeInfo();
                XMLWriter::renderManifestNode($recycleNode);
            }
        }

        Logger::debug("LS Time : ".intval((microtime(true)-$startTime)*1000)."ms");

        XMLWriter::close();
    }

    private function parseLsOptions($optionString){
        // LS OPTIONS : dz , a, d, z, all of these with or without l
        // d : directories
        // z : archives
        // f : files
        // => a : all, alias to dzf
        // l : list metadata
        $allowed = array("a", "d", "z", "f", "l");
        $lsOptions = array();
        foreach ($allowed as $key){
            if(strchr($optionString, $key)!==false){
                $lsOptions[$key] = true;
            }else{
                $lsOptions[$key] = false;
            }
        }
        if(Utils::arrayGet($lsOptions, "a")){
            $lsOptions["d"] = $lsOptions["z"] = $lsOptions["f"] = true;
        }
        return $lsOptions;
    }

    private function countFiles($dirName, $foldersOnly = false, $nonEmptyCheckOnly = false){
        $driver = $this->_driver;   
        $handle=@opendir($dirName);
        if($handle === false){
            throw new \Exception("Error while trying to open directory ".$dirName);
        }
        if($foldersOnly && !call_user_func(array($driver->wrapperClassName, "isRemote"))){
            closedir($handle);
            $path = call_user_func(array($driver->wrapperClassName, "getRealFSReference"), $dirName);
            $dirs = glob($path."/*", GLOB_ONLYDIR|GLOB_NOSORT);
            if($dirs === false) return 0;
            return count($dirs);
        }
        $count = 0;
        while (strlen($file = readdir($handle)) > 0)
        {
            if($file != "." && $file !=".." 
                && !(Utils::isHidden($file) && !($this->_driver->driverConf["SHOW_HIDDEN_FILES"] ?? false))){
                if($foldersOnly && is_file($dirName."/".$file)) continue;
                $count++;
                if($nonEmptyCheckOnly) break;
            }
        }
        closedir($handle);
        return $count;
    }

    private function renderPagination($options, $totalPages, $crtPage, $countFiles ){       
        $lsOptions = Utils::arrayGet($options, "options");
        if(isSet($totalPages) && isSet($crtPage)){
            XMLWriter::renderPaginationData(
                 $countFiles, 
                $crtPage, 
                $totalPages, 
                $this->countFiles(Utils::arrayGet($options, "path"), TRUE)
            ); 
            if(!Utils::arrayGet($lsOptions, "f")){
                XMLWriter::close();
                exit(1);
            }          
        }
    }
}
