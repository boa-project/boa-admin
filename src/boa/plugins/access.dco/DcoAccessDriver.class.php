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
use BoA\Core\Services\PluginsService;
use BoA\Core\Utils\Utils;
use BoA\Core\Utils\Text\SystemTextEncoding;
use BoA\Core\Xml\ManifestNode;
use BoA\Plugins\Core\Access\AbstractAccessDriver;
use BoA\Plugins\Access\Dco\DcoExplorer;
use BoA\Plugins\Core\Log\Logger;

defined('APP_EXEC') || die( 'Access not allowed');

if (!defined("DCOFOLDER_SUFFIX")) {
    define("DCOFOLDER_SUFFIX", "@boaproject.net");
}

/**
 * Plugin to access a filesystem. Most "FS" like driver (even remote ones)
 * extend this one.
 * @package APP_Plugins
 * @subpackage Access
 */
class DcoAccessDriver extends AbstractAccessDriver implements FileWrapperProvider
{
    const DEFAULT_ACCESSWRAPPER_CLASSNAME = "BoA\Plugins\Access\Dco\DcoAccessWrapper";
    /**
    * @var Repository
    */
    public $repository;
    public $driverConf;
    public $wrapperClassName;
    public $urlBase;
    public $metaPlugin;
    public $mess;
    private static $loadedUserBookmarks;
    private $_explorer;

    function initRepository(){
        if(is_array($this->pluginConf)){
            $this->driverConf = $this->pluginConf;
        }else{
            $this->driverConf = array();
        }
        if(isset($this->pluginConf["PROBE_REAL_SIZE"])){
            // PASS IT TO THE WRAPPER
            ConfService::setConf("PROBE_REAL_SIZE", $this->pluginConf["PROBE_REAL_SIZE"]);
        }
        if (empty($this->driverConf["DCOFOLDER_SUFFIX"])){
            $this->driverConf["DCOFOLDER_SUFFIX"] = DCOFOLDER_SUFFIX;
        }

        // Load repository custom suffix.
        $suffix = trim($this->repository->getOption("DCOFOLDER_SUFFIX"));
        if (!empty($suffix) && $suffix != '@' && strpos($suffix, '@') === 0) {
            $this->driverConf["DCOFOLDER_SUFFIX"] = $suffix;
        }

        $create = $this->repository->getOption("CREATE");
        $path = $this->repository->getOption("PATH");
        $recycle = $this->repository->getOption("RECYCLE_BIN");
        if($create == true){
            if(!is_dir($path)) @mkdir($path, 0755, true);
            if(!is_dir($path)){
                throw new ApplicationException("Cannot create root path for repository (".$this->repository->getDisplay()."). Please check repository configuration or that your folder is writeable!");
            }
            if($recycle!= "" && !is_dir($path."/".$recycle)){
                @mkdir($path."/".$recycle);
                if(!is_dir($path."/".$recycle)){
                    throw new ApplicationException("Cannot create recycle bin folder. Please check repository configuration or that your folder is writeable!");
                }
            }
            $dataTemplate = $this->repository->getOption("DATA_TEMPLATE");
            if(!empty($dataTemplate) && is_dir($dataTemplate) && !is_file($path."/.template")){
                $errs = array();$succ = array();
                $this->dircopy($dataTemplate, $path, $succ, $errs, false, false);
                touch($path."/.template");
            }
        }else{
            if(!is_dir($path)){
                throw new ApplicationException("Cannot find base path for your repository! Please check the configuration!");
            }
        }
        $wrapperData = $this->detectStreamWrapper(true);
        $this->wrapperClassName = $wrapperData["classname"];
        $this->urlBase = $wrapperData["protocol"]."://".$this->repository->getId();
        if($recycle != ""){
            RecycleBinManager::init($this->urlBase, "/".$recycle);
        }

        $metaSources = $this->repository->getOption("META_SOURCES");
        foreach ($metaSources as $key => $metaSource) {
            $parts = explode('.', $key);
            if ($parts[0] == 'meta'){
                $plugin = PluginsService::getInstance()->getPluginById($key);
                if (in_array('BoA\Plugins\Access\Dco\DcoSpecProvider', class_implements($plugin))){
                    $this->metaPlugin = $plugin;
                }
            }
        }

    }

    public function getResourceUrl($path){
        return $this->urlBase.$path;
    }

    public function getWrapperClassName(){
        return $this->wrapperClassName;
    }

    function redirectActionsToMethod(&$contribNode, $arrayActions, $targetMethod){
        $actionXpath=new \DOMXPath($contribNode->ownerDocument);
        foreach($arrayActions as $index => $value){
            $arrayActions[$index] = 'action[@name="'.$value.'"]/processing/serverCallback';
        }
        $procList = $actionXpath->query(implode(" | ", $arrayActions), $contribNode);
        foreach($procList as $node){
            $node->setAttribute("methodName", $targetMethod);
        }
    }

    function disableArchiveBrowsingContributions(&$contribNode){
        // Cannot use zip features on FTP !
        // Remove "compress" action
        $actionXpath=new \DOMXPath($contribNode->ownerDocument);
        $compressNodeList = $actionXpath->query('action[@name="compress"]', $contribNode);
        if(!$compressNodeList->length) return ;
        unset($this->actions["compress"]);
        $compressNode = $compressNodeList->item(0);
        $contribNode->removeChild($compressNode);
        // Disable "download" if selection is multiple
        $nodeList = $actionXpath->query('action[@name="download"]/gui/selectionContext', $contribNode);
        $selectionNode = $nodeList->item(0);
        $values = array("dir" => "false", "unique" => "true");
        foreach ($selectionNode->attributes as $attribute){
            if(isSet($values[$attribute->name])){
                $attribute->value = $values[$attribute->name];
            }
        }
        $nodeList = $actionXpath->query('action[@name="download"]/processing/clientListener[@name="selectionChange"]', $contribNode);
        $listener = $nodeList->item(0);
        $listener->parentNode->removeChild($listener);
        // Disable "Explore" action on files
        $nodeList = $actionXpath->query('action[@name="ls"]/gui/selectionContext', $contribNode);
        $selectionNode = $nodeList->item(0);
        $values = array("file" => "false", "allowedMimes" => "");
        foreach ($selectionNode->attributes as $attribute){
            if(isSet($values[$attribute->name])){
                $attribute->value = $values[$attribute->name];
            }
        }
    }

    protected  function getNodesDiffArray(){
        return array("REMOVE" => array(), "ADD" => array(), "UPDATE" => array());
    }

    function switchAction($action, $httpVars, $fileVars){
        if(!isSet($this->actions[$action])) return;
        parent::accessPreprocess($action, $httpVars, $fileVars);
        $selection = new UserSelection();
        $dir = $httpVars["dir"] ?? "";
        if($this->wrapperClassName == self::DEFAULT_ACCESSWRAPPER_CLASSNAME){
            $dir = DcoAccessWrapper::patchPathForBaseDir($dir);
        }
        $dir = Utils::securePath($dir);
        if($action != "upload"){
            $dir = Utils::decodeSecureMagic($dir);
        }
        $selection->initFromHttpVars($httpVars);
        if(!$selection->isEmpty()){
            $this->filterUserSelectionToHidden($selection->getFiles());
        }
        $mess = $this->mess = ConfService::getMessages();

        $newArgs = RecycleBinManager::filterActions($action, $selection, $dir, $httpVars);
        if(isSet($newArgs["action"])) $action = $newArgs["action"];
        if(isSet($newArgs["dest"])) $httpVars["dest"] = SystemTextEncoding::toUTF8($newArgs["dest"]);//Re-encode!
        // FILTER DIR PAGINATION ANCHOR
        $page = null;
        if(isSet($dir) && strstr($dir, "%23")!==false){
            $parts = explode("%23", $dir);
            $dir = $parts[0];
            $page = $parts[1];
        }

        $pendingSelection = "";
        $logMessage = null;
        $reloadContextNode = false;

        switch($action)
        {
            //------------------------------------
            //  DOWNLOAD
            //------------------------------------
            case "download":
                Logger::logAction("Download", array("files"=>$selection));
                @set_error_handler(array("HTMLWriter", "javascriptErrorHandler"), E_ALL & ~ E_NOTICE);
                @register_shutdown_function("restore_error_handler");
                $zip = false;
                if($selection->isUnique()){
                    if(is_dir($this->urlBase.$selection->getUniqueFile())) {
                        $zip = true;
                        $base = basename($selection->getUniqueFile());
                        $dir .= "/".dirname($selection->getUniqueFile());
                    }else{
                        if(!file_exists($this->urlBase.$selection->getUniqueFile())){
                            throw new \Exception("Cannot find file!");
                        }
                    }
                    $node = $selection->getUniqueNode($this);
                }else{
                    $zip = true;
                }
                if($zip){
                    // Make a temp zip and send it as download
                    $loggedUser = AuthService::getLoggedUser();
                    $file = Utils::getAppTmpDir()."/".($loggedUser?$loggedUser->getId():"shared")."_".time()."tmpDownload.zip";
                    $zipFile = $this->makeZip($selection->getFiles(), $file, $dir);
                    if(!$zipFile) throw new ApplicationException("Error while compressing");
                    register_shutdown_function("unlink", $file);
                    $localName = ($base==""?"Files":$base).".zip";
                    $this->readFile($file, "force-download", $localName, false, false, true);
                }else{
                    $localName = "";
                    Controller::applyHook("dl.localname", array($this->urlBase.$selection->getUniqueFile(), &$localName, $this->wrapperClassName));
                    $this->readFile($this->urlBase.$selection->getUniqueFile(), "force-download", $localName);
                }
                if(isSet($node)){
                    Controller::applyHook("node.read", array(&$node));
                }
                break;

            case "prepare_chunk_dl" :

                $chunkCount = intval($httpVars["chunk_count"]);
                $fileId = $this->urlBase.$selection->getUniqueFile();
                $sessionKey = "chunk_file_".md5($fileId.time());
                $totalSize = $this->filesystemFileSize($fileId);
                $chunkSize = intval ( $totalSize / $chunkCount );
                $realFile  = call_user_func(array($this->wrapperClassName, "getRealFSReference"), $fileId, true);
                $chunkData = array(
                    "localname"   => basename($fileId),
                    "chunk_count" => $chunkCount,
                    "chunk_size"  => $chunkSize,
                    "total_size"  => $totalSize,
                    "file_id"     => $sessionKey
                );
                $_SESSION[$sessionKey] = array_merge($chunkData, array("file"=>$realFile));
                HTMLWriter::charsetHeader("application/json");
                print(json_encode($chunkData));

                $node = $selection->getUniqueNode($this);
                Controller::applyHook("node.read", array(&$node));

                break;

            case "download_chunk" :

                $chunkIndex = intval($httpVars["chunk_index"]);
                $chunkKey = $httpVars["file_id"];
                $sessData = $_SESSION[$chunkKey];
                $realFile = $sessData["file"];
                $chunkSize = $sessData["chunk_size"];
                $offset = $chunkSize * $chunkIndex;
                if($chunkIndex == $sessData["chunk_count"]-1){
                    // Compute the last chunk real length
                    $chunkSize = $sessData["total_size"] - ($chunkSize * ($sessData["chunk_count"]-1));
                    if(call_user_func(array($this->wrapperClassName, "isRemote"))){
                        register_shutdown_function("unlink", $realFile);
                    }
                }
                $this->readFile($realFile, "force-download", $sessData["localname"].".".sprintf("%03d", $chunkIndex+1), false, false, true, $offset, $chunkSize);

            break;

            case "compress" :
                    // Make a temp zip and send it as download
                    $loggedUser = AuthService::getLoggedUser();
                    if(isSet($httpVars["archive_name"])){
                        $localName = Utils::decodeSecureMagic($httpVars["archive_name"]);
                        $this->filterUserSelectionToHidden(array($localName));
                    }else{
                        $localName = (basename($dir)==""?"Files":basename($dir)).".zip";
                    }
                    $file = Utils::getAppTmpDir()."/".($loggedUser?$loggedUser->getId():"shared")."_".time()."tmpCompression.zip";
                    $zipFile = $this->makeZip($selection->getFiles(), $file, $dir);
                    if(!$zipFile) throw new ApplicationException("Error while compressing file $localName");
                    register_shutdown_function("unlink", $file);
                    $tmpFNAME = $this->urlBase.$dir."/".str_replace(".zip", ".tmp", $localName);
                    copy($file, $tmpFNAME);
                    try{
                        Controller::applyHook("node.before_create", array(new ManifestNode($tmpFNAME), filesize($tmpFNAME)));
                    }catch (\Exception $e){
                        @unlink($tmpFNAME);
                        throw $e;
                    }
                    @rename($tmpFNAME, $this->urlBase.$dir."/".$localName);
                    Controller::applyHook("node.change", array(null, new ManifestNode($this->urlBase.$dir."/".$localName), false));
                    //$reloadContextNode = true;
                    //$pendingSelection = $localName;
                    $newNode = new ManifestNode($this->urlBase.$dir."/".$localName);
                    if(!isset($nodesDiffs)) $nodesDiffs = $this->getNodesDiffArray();
                    $nodesDiffs["ADD"][] = $newNode;
            break;

            case "stat" :

                clearstatcache();
                $stat = @stat($this->urlBase.$selection->getUniqueFile());
                header("Content-type:application/json");
                if(!$stat){
                    print '{}';
                }else{
                    print json_encode($stat);
                }

            break;

            //------------------------------------
            //  ONLINE EDIT
            //------------------------------------
            case "get_content":

                $dlFile = $this->urlBase.$selection->getUniqueFile();
                Logger::logAction("Get_content", array("files"=>$selection));
                if(Utils::getStreamingMimeType(basename($dlFile))!==false){
                    $this->readFile($this->urlBase.$selection->getUniqueFile(), "stream_content");
                }else{
                    $this->readFile($this->urlBase.$selection->getUniqueFile(), "plain");
                }
                $node = $selection->getUniqueNode($this);
                Controller::applyHook("node.read", array(&$node));

                break;

            case "put_content":
                if(!isset($httpVars["content"])) break;
                // Load "code" variable directly from POST array, do not "securePath" or "sanitize"...
                $code = $httpVars["content"];
                $file = $selection->getUniqueFile($httpVars["file"]);
                Logger::logAction("Online Edition", array("file"=>$file));
                if(isSet($httpVars["encode"]) && $httpVars["encode"] == "base64"){
                    $code = base64_decode($code);
                }else{
                    $code = SystemTextEncoding::magicDequote($code);
                    $code=str_replace("&lt;","<",$code);
                }
                $fileName = $this->urlBase.$file;
                $currentNode = new ManifestNode($fileName);
                try{
                    Controller::applyHook("node.before_change", array(&$currentNode, strlen($code)));
                }catch(\Exception $e){
                    header("Content-Type:text/plain");
                    print $e->getMessage();
                    return;
                }
                if(!is_file($fileName) || !$this->isWriteable($fileName, "file")){
                    header("Content-Type:text/plain");
                    print((!$this->isWriteable($fileName, "file")?"1001":"1002"));
                    return ;
                }
                $fp=fopen($fileName,"w");
                fputs ($fp,$code);
                fclose($fp);
                clearstatcache(true, $fileName);
                Controller::applyHook("node.change", array($currentNode, $currentNode, false));
                header("Content-Type:text/plain");
                print($mess[115]);

            break;

            //------------------------------------
            //  COPY / MOVE
            //------------------------------------
            case "copy";
            case "move";

            //throw new ApplicationException("", 113);
                if($selection->isEmpty())
                {
                    throw new ApplicationException("", 113);
                }
                $success = $error = array();
                $dest = Utils::decodeSecureMagic($httpVars["dest"]);
                $this->filterUserSelectionToHidden(array($httpVars["dest"]));
                if($selection->inZip()){
                    // Set action to copy anycase (cannot move from the zip).
                    $action = "copy";
                    $this->extractArchive($dest, $selection, $error, $success);
                }else{
                    $move = ($action == "move" ? true : false);
                    if($move && isSet($httpVars["force_copy_delete"])){
                        $move = false;
                    }
                    $this->copyOrMove($dest, $selection->getFiles(), $error, $success, $move);

                }

                if(count($error)){
                    throw new ApplicationException(SystemTextEncoding::toUTF8(join("\n", $error)));
                }else {
                    if(isSet($httpVars["force_copy_delete"])){
                        $errorMessage = $this->delete($selection->getFiles(), $logMessages);
                        if($errorMessage) throw new ApplicationException(SystemTextEncoding::toUTF8($errorMessage));
                        Logger::logAction("Copy/Delete", array("files"=>$selection, "destination" => $dest));
                    }else{
                        Logger::logAction(($action=="move"?"Move":"Copy"), array("files"=>$selection, "destination"=>$dest));
                    }
                    $logMessage = join("\n", $success);
                }
                if(!isSet($nodesDiffs)) $nodesDiffs = $this->getNodesDiffArray();
                // Assume new nodes are correctly created
                $selectedItems = $selection->getFiles();
                foreach($selectedItems as $selectedPath){
                    $newPath = $this->urlBase.$dest ."/". basename($selectedPath);
                    $newNode = new ManifestNode($newPath);
                    $nodesDiffs["ADD"][] = $newNode;
                    if($action == "move") $nodesDiffs["REMOVE"][] = $selectedPath;
                }
                if(!(RecycleBinManager::getRelativeRecycle() ==$dest && $this->driverConf["HIDE_RECYCLE"] == true)){
                    //$reloadDataNode = $dest;
                }

            break;

            //------------------------------------
            //  DELETE
            //------------------------------------
            case "delete";

                if($selection->isEmpty())
                {
                    throw new ApplicationException("", 113);
                }
                $logMessages = array();
                $errorMessage = $this->delete($selection->getFiles(), $logMessages);
                if(count($logMessages))
                {
                    $logMessage = join("\n", $logMessages);
                }
                if($errorMessage) throw new ApplicationException(SystemTextEncoding::toUTF8($errorMessage));
                Logger::logAction("Delete", array("files"=>$selection));
                if(!isSet($nodesDiffs)) $nodesDiffs = $this->getNodesDiffArray();
                $nodesDiffs["REMOVE"] = array_merge($nodesDiffs["REMOVE"], $selection->getFiles());

            break;

            case "purge" :

                $pTime = intval($this->repository->getOption("PURGE_AFTER"));
                if($pTime > 0){
                    $purgeTime = intval($pTime)*3600*24;
                    $this->recursivePurge($this->urlBase, $purgeTime);
                }

            break;

            //------------------------------------
            //  RENAME
            //------------------------------------
            case "rename";

                $file = Utils::decodeSecureMagic($httpVars["file"]);
                $filename_new = Utils::decodeSecureMagic($httpVars["filename_new"]);
                $dest = null;
                if(isSet($httpVars["dest"])){
                    $dest = Utils::decodeSecureMagic($httpVars["dest"]);
                    $filename_new = "";
                }
                $this->filterUserSelectionToHidden(array($filename_new));
                $this->rename($file, $filename_new, $dest);
                $logMessage= SystemTextEncoding::toUTF8($file)." $mess[41] ".SystemTextEncoding::toUTF8($filename_new);
                //$reloadContextNode = true;
                //$pendingSelection = $filename_new;
                if(!isSet($nodesDiffs)) $nodesDiffs = $this->getNodesDiffArray();
                if($dest == null) $dest = dirname($file);
                $nodesDiffs["UPDATE"][$file] = new ManifestNode($this->urlBase.$dest."/".$filename_new);
                Logger::logAction("Rename", array("original"=>$file, "new"=>$filename_new));

            break;

            //------------------------------------
            //  CREER UN DCO / CREATE DCO
            //------------------------------------
            case 'get_meta_specs':
                if ($this->metaPlugin != null){
                    $specs = $this->metaPlugin->loadSpecs();
                    header('Content-Type: application/json; charset=UTF-8');
                    header('Cache-Control: no-cache');
                    print(json_encode(array("LIST" => $specs)));
                }
                break;

            case "editdco":
                $selection = new UserSelection();
                $selection->initFromHttpVars();
                $currentFile = $selection->getUniqueFile();
                $urlBase = $this->getResourceUrl($currentFile);
                Utils::parseStandardFormParameters($httpVars, $meta, null, "DCO_", array());

                $manifestNode = $this->getExplorer()->getDcoManifestNode($urlBase."/.manifest");

                $manifest = new \stdClass();
                $manifest->title = $meta["dcotitle"];
                $manifest->type = $meta["dcotype"];
                $contype = $meta["dcocontype"];
                $manifest->conexion_type = $contype["group_switch_value"];
                $manifest->url = $manifest->conexion_type == 'external'? $contype["externalurl"] : "";
                $manifest->version = $meta["version"];
                $manifest->author = $meta["author"];
                $manifest->status = $meta["status"];
                $manifest->id = $meta["dcoid"];
                $manifest->customicon = $meta["customicon"];
                $manifest->lastupdated = date('c'); //ISO format
                $this->updateManifest($urlBase, $manifest);
                $this->updateCustomIcon($urlBase, $manifestNode->customicon, $meta["customicon"]);
                $manifestNode = $this->getExplorer()->getDcoManifestNode($urlBase."/.manifest"); //Reload the node
                if(!isSet($nodesDiffs)) $nodesDiffs = $this->getNodesDiffArray();
                $nodesDiffs["UPDATE"][$currentFile] = $manifestNode;
                //HTMLWriter::charsetHeader("application/json");
                //print(json_encode($manifest));
                break;
            case "mkdco":
                $messtmp="";
                $id = $this->getUniqueId($dir); //$httpVars["dcotitle"]
                $dirname=$id;// Utils::decodeSecureMagic($id, APP_SANITIZE_HTML_STRICT);
                $dirname = substr($dirname, 0, ConfService::getCoreConf("NODENAME_MAX_LENGTH"));
                $this->filterUserSelectionToHidden(array($dirname));
                //Controller::applyHook("node.before_create", array(new ManifestNode($dir."/".$dirname), -2));
                $error = $this->mkDir($dir, $dirname);
                if(isSet($error)){
                    throw new ApplicationException($error);
                }
                //Create src and content directories
                $error = $this->mkDir($dir."/".$dirname, "src");
                if(isSet($error)){
                    throw new ApplicationException($error);
                }
                $error = $this->mkDir($dir."/".$dirname, "content");
                if(isSet($error)){
                    throw new ApplicationException($error);
                }
                //Create the Manifest file
                Utils::parseStandardFormParameters($httpVars, $meta, null, "DCO_", array());
                $manifest = new \stdClass();
                $manifest->title = $meta["dcotitle"];
                $manifest->type = $meta["dcotype"];
                $contype = $meta["dcocontype"];
                $manifest->conexion_type = $contype["group_switch_value"];
                $manifest->author = AuthService::getLoggedUser()->id;
                $manifest->url = $manifest->conexion_type == 'external'? $contype["externalurl"] : "";
                $manifest->status = $meta["status"];
                $manifest->version = "1.0";
                $manifest->id = $id;
                $manifest->customicon = $meta["customicon"];
                $metadata = array();
                if ($this->metaPlugin != null){
                    $metadata = $this->metaPlugin->initMetaFromSpec($dir."/".$dirname, $meta["dcotype"]);
                }
                $json = new \stdClass();
                $json->manifest = $manifest;
                $json->metadata = $metadata;
                $this->createManifest($dir."/".$dirname, $json);
                $this->updateCustomIcon($this->urlBase."/".$dirname, null, $meta["customicon"]);

                $messtmp.=$mess["access_dco.create.success.pre"]." '".SystemTextEncoding::toUTF8($manifest->title)."' ".$mess["access_dco.create.success.pos"]." ".$id;
                //if($dir=="") {$messtmp.="/";} else {$messtmp.= SystemTextEncoding::toUTF8($dir);}
                $logMessage = $messtmp;
                //$pendingSelection = $dirname;
                //$reloadContextNode = true;
                $newNode = $this->getExplorer()->getDcoManifestNode($this->urlBase.$dir."/".$dirname."/.manifest"); // new ManifestNode($this->urlBase.$dir."/".$dirname);
                if(!isSet($nodesDiffs)) $nodesDiffs = $this->getNodesDiffArray();
                array_push($nodesDiffs["ADD"], $newNode);
                Logger::logAction("Create DCO", array("dir"=>$dir."/".$dirname));

            break;

            //------------------------------------
            //  CREER UN REPERTOIRE / CREATE DIR
            //------------------------------------
            case "mkdir";

                $messtmp="";
                $dirname=Utils::decodeSecureMagic($httpVars["dirname"], APP_SANITIZE_HTML_STRICT);
                $dirname = substr($dirname, 0, ConfService::getCoreConf("NODENAME_MAX_LENGTH"));
                $this->filterUserSelectionToHidden(array($dirname));
                Controller::applyHook("node.before_create", array(new ManifestNode($dir."/".$dirname), -2));
                $error = $this->mkDir($dir, $dirname);
                if(isSet($error)){
                    throw new ApplicationException($error);
                }
                $messtmp.="$mess[38] ".SystemTextEncoding::toUTF8($dirname)." $mess[39] ";
                if($dir=="") {$messtmp.="/";} else {$messtmp.= SystemTextEncoding::toUTF8($dir);}
                $logMessage = $messtmp;
                //$pendingSelection = $dirname;
                //$reloadContextNode = true;
                $newNode = new ManifestNode($this->urlBase.$dir."/".$dirname);
                if(!isSet($nodesDiffs)) $nodesDiffs = $this->getNodesDiffArray();
                array_push($nodesDiffs["ADD"], $newNode);
                Logger::logAction("Create Dir", array("dir"=>$dir."/".$dirname));

            break;

            //------------------------------------
            //  CREER UN FICHIER / CREATE FILE
            //------------------------------------
            case "mkfile";

                $messtmp="";
                $filename=Utils::decodeSecureMagic($httpVars["filename"], APP_SANITIZE_HTML_STRICT);
                $filename = substr($filename, 0, ConfService::getCoreConf("NODENAME_MAX_LENGTH"));
                $this->filterUserSelectionToHidden(array($filename));
                $content = "";
                if(isSet($httpVars["content"])){
                    $content = $httpVars["content"];
                }
                $error = $this->createEmptyFile($dir, $filename, $content);
                if(isSet($error)){
                    throw new ApplicationException($error);
                }
                $messtmp.="$mess[34] ".SystemTextEncoding::toUTF8($filename)." $mess[39] ";
                if($dir=="") {$messtmp.="/";} else {$messtmp.=SystemTextEncoding::toUTF8($dir);}
                $logMessage = $messtmp;
                //$reloadContextNode = true;
                //$pendingSelection = $dir."/".$filename;
                Logger::logAction("Create File", array("file"=>$dir."/".$filename));
                $newNode = new ManifestNode($this->urlBase.$dir."/".$filename);
                if(!isSet($nodesDiffs)) $nodesDiffs = $this->getNodesDiffArray();
                array_push($nodesDiffs["ADD"], $newNode);

            break;

            //------------------------------------
            //  CHANGE FILE PERMISSION
            //------------------------------------
            case "chmod";

                $messtmp="";
                $files = $selection->getFiles();
                $changedFiles = array();
                $chmod_value = $httpVars["chmod_value"];
                $recursive = $httpVars["recursive"];
                $recur_apply_to = $httpVars["recur_apply_to"];
                foreach ($files as $fileName){
                    $error = $this->chmod($fileName, $chmod_value, ($recursive=="on"), ($recursive=="on"?$recur_apply_to:"both"), $changedFiles);
                }
                if(isSet($error)){
                    throw new ApplicationException($error);
                }
                //$messtmp.="$mess[34] ".SystemTextEncoding::toUTF8($filename)." $mess[39] ";
                $logMessage="Successfully changed permission to ".$chmod_value." for ".count($changedFiles)." files or folders";
                Logger::logAction("Chmod", array("dir"=>$dir, "filesCount"=>count($changedFiles)));
                if(!isSet($nodesDiffs)) $nodesDiffs = $this->getNodesDiffArray();
                $nodesDiffs["UPDATE"] = array_merge($nodesDiffs["UPDATE"], $selection->buildNodes($this));

            break;

            //------------------------------------
            //  UPLOAD
            //------------------------------------
            case "upload":

                Logger::debug("Upload Files Data", $fileVars);
                $destination=$this->urlBase.Utils::decodeSecureMagic($dir);
                Logger::debug("Upload inside", array("destination"=>$destination));
                if(!$this->isWriteable($destination))
                {
                    $errorCode = 412;
                    $errorMessage = "$mess[38] ".SystemTextEncoding::toUTF8($dir)." $mess[99].";
                    Logger::debug("Upload error 412", array("destination"=>$destination));
                    return array("ERROR" => array("CODE" => $errorCode, "MESSAGE" => $errorMessage));
                }
                foreach ($fileVars as $boxName => $boxData)
                {
                    if(substr($boxName, 0, 9) != "userfile_") continue;
                    $err = Utils::parseFileDataErrors($boxData);
                    if($err != null)
                    {
                        $errorCode = $err[0];
                        $errorMessage = $err[1];
                        break;
                    }
                    $userfile_name = $boxData["name"];
                    try{
                        $this->filterUserSelectionToHidden(array($userfile_name));
                    }catch (\Exception $e){
                        return array("ERROR" => array("CODE" => 411, "MESSAGE" => "Forbidden"));
                    }
                    $userfile_name=Utils::sanitize(SystemTextEncoding::fromPostedFileName($userfile_name), APP_SANITIZE_HTML_STRICT);
                    if(isSet($httpVars["urlencoded_filename"])){
                        $userfile_name = Utils::sanitize(SystemTextEncoding::fromUTF8(urldecode($httpVars["urlencoded_filename"])), APP_SANITIZE_HTML_STRICT);
                    }
                    Logger::debug("User filename ".$userfile_name);
                    $userfile_name = substr($userfile_name, 0, ConfService::getCoreConf("NODENAME_MAX_LENGTH"));
                    if(isSet($httpVars["auto_rename"])){
                        $userfile_name = self::autoRenameForDest($destination, $userfile_name);
                    }
                    try {
                        if(file_exists($destination."/".$userfile_name)){
                            Controller::applyHook("node.before_change", array(new ManifestNode($destination."/".$userfile_name), $boxData["size"]));
                        }else{
                            Controller::applyHook("node.before_create", array(new ManifestNode($destination."/".$userfile_name), $boxData["size"]));
                        }
                        Controller::applyHook("node.before_change", array(new ManifestNode($destination)));
                    }catch (\Exception $e){
                        $errorCode=507;
                        $errorMessage = $e->getMessage();
                        break;
                    }
                    if(isSet($boxData["input_upload"])){
                        try{
                            Logger::debug("Begining reading INPUT stream");
                            $input = fopen("php://input", "r");
                            $output = fopen("$destination/".$userfile_name, "w");
                            $sizeRead = 0;
                            while($sizeRead < intval($boxData["size"])){
                                $chunk = fread($input, 4096);
                                $sizeRead += strlen($chunk);
                                fwrite($output, $chunk, strlen($chunk));
                            }
                            fclose($input);
                            fclose($output);
                            Logger::debug("End reading INPUT stream");
                        }catch (\Exception $e){
                            $errorCode=411;
                            $errorMessage = $e->getMessage();
                            break;
                        }
                    }else{
                        $result = @move_uploaded_file($boxData["tmp_name"], "$destination/".$userfile_name);
                        if(!$result){
                            $realPath = call_user_func(array($this->wrapperClassName, "getRealFSReference"),"$destination/".$userfile_name);
                            $result = move_uploaded_file($boxData["tmp_name"], $realPath);
                        }
                        if (!$result)
                        {
                            $errorCode=411;
                            $errorMessage="$mess[33] ".$userfile_name;
                            break;
                        }
                    }
                    if(isSet($httpVars["appendto_urlencoded_part"])){
                        $appendTo = Utils::sanitize(SystemTextEncoding::fromUTF8(urldecode($httpVars["appendto_urlencoded_part"])), APP_SANITIZE_HTML_STRICT);
                        if(file_exists($destination ."/" . $appendTo)){
                            Logger::debug("Should copy stream from $userfile_name to $appendTo");
                            $partO = fopen($destination."/".$userfile_name, "r");
                            $appendF = fopen($destination ."/". $appendTo, "a+");
                            while(!feof($partO)){
                                $buf = fread($partO, 1024);
                                fwrite($appendF, $buf, strlen($buf));
                            }
                            fclose($partO);
                            fclose($appendF);
                            Logger::debug("Done, closing streams!");
                        }
                        @unlink($destination."/".$userfile_name);
                        $userfile_name = $appendTo;
                    }

                    $this->changeMode($destination."/".$userfile_name);
                    $createdNode = new ManifestNode($destination."/".$userfile_name);
                    Controller::applyHook("node.change", array(null, $createdNode, false));
                    $logMessage.="$mess[34] ".SystemTextEncoding::toUTF8($userfile_name)." $mess[35] $dir";
                    Logger::logAction("Upload File", array("file"=>SystemTextEncoding::fromUTF8($dir)."/".$userfile_name));
                }

                if(isSet($errorMessage)){
                    Logger::debug("Return error $errorCode $errorMessage");
                    return array("ERROR" => array("CODE" => $errorCode, "MESSAGE" => $errorMessage));
                }else{
                    Logger::debug("Return success");
                    return array("SUCCESS" => true, "CREATED_NODE" => $createdNode);
                }
                return ;

            break;

            case "lsync" :

                if(!ConfService::currentContextIsCommandLine()){
                    die("This command must be accessed via CLI only.");
                }
                $fromNode = null;
                $toNode = null;
                $copyOrMove = false;
                if(isSet($httpVars["from"])) {
                    $fromNode = new ManifestNode($this->urlBase.Utils::decodeSecureMagic($httpVars["from"]));
                }
                if(isSet($httpVars["to"])) {
                    $toNode = new ManifestNode($this->urlBase.Utils::decodeSecureMagic($httpVars["to"]));
                }
                if(isSet($httpVars["copy"]) && $httpVars["copy"] == "true"){
                    $copyOrMove = true;
                }
                Controller::applyHook("node.change", array($fromNode, $toNode, $copyOrMove));

            break;

            //------------------------------------
            //  XML LISTING
            //------------------------------------
            case "ls":
                $this->getExplorer()->getAll($dir, $page , $httpVars);
            break;

            //------------------------------------
            //  GET CUSTOM ICON
            //------------------------------------
            case "get_custom_dco_icon" :
                if(isSet($httpVars["tmp_file"])){
                    $file = Utils::getAppTmpDir()."/".Utils::securePath($httpVars["tmp_file"]);
                    if(isSet($file)){
                        header("Content-Type:image/png");
                        readfile($file);
                    }
                }else if(isSet($httpVars["binary_id"])){

                    $this->loadBinary(array(), $httpVars["binary_id"]);
                }
            break;

            //------------------------------------
            //  SAVE CUSTOM ICON
            //------------------------------------
            case "store_custom_dco_icon" :

                if(count($fileVars)){
                    $keys = array_keys($fileVars);
                    $boxData = $fileVars[$keys[0]];
                    $err = Utils::parseFileDataErrors($boxData);
                    if($err !== null){
                        Logger::debug($err);
                        return $err;
                    }else{
                        $rand = substr(md5(time()), 0, 6);
                        $tmp = $rand."-". $boxData["name"];
                        move_uploaded_file($boxData["tmp_name"], Utils::getAppTmpDir()."/". $tmp);
                    }
                }
                else {
                    Logger::debug("Not file vars", $fileVars);
                    return "Not file vars";
                }

                if(isSet($tmp) && file_exists(Utils::getAppTmpDir()."/".$tmp)) {
                    print('<script type="text/javascript">');
                    print('parent.formManagerHiddenIFrameSubmission("'.$tmp.'");');
                    print('</script>');
                }
                else {
                    $errorMessage = 'File not exist: [tmp_dir]/' . $tmp;
                    Logger::debug($errorMessage);
                    return $errorMessage;
                }

                break;

            //------------------------------------
            //  SET OBJECT ENTRY POINT
            //------------------------------------
            case 'set_entry_point':
                print($this->setObjectEntryPoint($httpVars["path"]));
                break;
        }

        $xmlBuffer = "";
        if(isset($logMessage) || isset($errorMessage))
        {
            $xmlBuffer .= XMLWriter::sendMessage((isSet($logMessage)?$logMessage:null), (isSet($errorMessage)?$errorMessage:null), false);
        }
        if($reloadContextNode){
            if(!isSet($pendingSelection)) $pendingSelection = "";
            $xmlBuffer .= XMLWriter::reloadDataNode("", $pendingSelection, false);
        }
        if(isSet($reloadDataNode)){
            $xmlBuffer .= XMLWriter::reloadDataNode($reloadDataNode, "", false);
        }
        if(isSet($nodesDiffs)){
            $xmlBuffer .= XMLWriter::writeNodesDiff($nodesDiffs, false);
        }

        return $xmlBuffer;
    }

    /**
     * @param ManifestNode $node
     * @param bool $parentNode
     * @param bool $details
     * @return void
     */
    function loadObjectNodeInfo(&$node, $parentNode = false, $details = false){
        $mess = ConfService::getMessages();
        $meta = $node->metadata;
        $meta["is_file"] = "0";
        $meta["mimestring"] = $mess["access_dco.dco_mime"];
        $meta["mimestring_id"] = "access_dco.dco_mime";
        $meta["icon"] = "dco.png";
        $meta["meta_editor_class"] = $this->metaPlugin->getMetaEditorClass();
        $node->mergeMetadata($meta);
    }

    /**
     * @param ManifestNode $node
     * @param bool $parentNode
     * @param bool $details
     * @return void
     */
    function loadNodeInfo(&$node, $parentNode = false, $details = false){
        $nodeName = basename($node->getPath());
        $metaData = $node->metadata;

        //If it is an object then load specific meta, otherwise load folder or file info as usual
        if (isSet($metaData["APP_mime"]) && $metaData["APP_mime"] === "dco"){
            $this->loadObjectNodeInfo($node, $parentNode, $details);
            return;
        }

        if(!isSet($metaData["is_file"])){
            $isLeaf = is_file($node->getUrl()) || Utils::isBrowsableArchive($nodeName);
            $metaData["is_file"] = ($isLeaf?"1":"0");
        }else{
            $isLeaf = $metaData["is_file"] == "1" ? true : false;
        }
        $metaData["filename"] = $node->getPath();

        if(RecycleBinManager::recycleEnabled() && $node->getPath() == RecycleBinManager::getRelativeRecycle()){
            $mess = ConfService::getMessages();
            $recycleIcon = ($this->countFiles($node->getUrl(), false, true)>0?"trashcan_full.png":"trashcan.png");
            $metaData["icon"] = $recycleIcon;
            $metaData["mimestring"] = $mess[122];
            $node->setLabel($mess[122]);
            $metaData["APP_mime"] = "recycle";
        }else{
            $mimeData = Utils::mimeData($node->getUrl(), !$isLeaf);
            $metaData["mimestring_id"] = $mimeData[0]; //Utils::mimetype($node->getUrl(), "type", !$isLeaf);
            $metaData["icon"] = $mimeData[1]; //Utils::mimetype($nodeName, "image", !$isLeaf);
            if($metaData["icon"] == "folder.png"){
                $metaData["openicon"] = "folder_open.png";
            }
            if(!$isLeaf){
                $metaData["APP_mime"] = "APP_folder";
            }
        }

        $metaData["file_group"] = @filegroup($node->getUrl()) || "unknown";
        $metaData["file_owner"] = @fileowner($node->getUrl()) || "unknown";
        $crtPath = $node->getPath();
        $vRoots = $this->repository->listVirtualRoots();
        if(!empty($crtPath)){
            if(!@$this->isWriteable($node->getUrl())){
               $metaData["readonly"] = "true";
            }
            if(isSet($vRoots[ltrim($crtPath, "/")])){
                $metaData["readonly"] = $vRoots[ltrim($crtPath, "/")]["right"] == "r" ? "true" : "false";
            }
        }else{
            if(count($vRoots)) {
                $metaData["readonly"] = "true";
            }
        }

        if (!isset($metaData["readonly"]) || !$metaData["readonly"]){
            $fPerms = @fileperms($node->getUrl());
            if($fPerms !== false){
                $fPerms = substr(decoct( $fPerms ), ($isLeaf?2:1));
            }else{
                $fPerms = '0000';
            }
        }
        else{
            $fPerms = '0000';
        }
        $metaData["file_perms"] = $fPerms;
        $datemodif = $this->date_modif($node->getUrl());
        $metaData["modiftime"] = ($datemodif ? $datemodif : "0");
        $metaData["bytesize"] = 0;
        if($isLeaf){
            $metaData["bytesize"] = $this->filesystemFileSize($node->getUrl());
        }
        $metaData["filesize"] = Utils::roundSize($metaData["bytesize"]);
        if(Utils::isBrowsableArchive($nodeName)){
            $metaData["APP_mime"] = "browsable_archive";
        }

        if($details == "minimal"){
            $miniMeta = array(
                "is_file" => $metaData["is_file"],
                "filename" => $metaData["filename"],
                "bytesize" => $metaData["bytesize"],
                "modiftime" => $metaData["modiftime"],
            );
            $node->mergeMetadata($miniMeta);
        }else{
            $node->mergeMetadata($metaData);
        }
    }

    /**
     * Test if userSelection is containing a hidden file, which should not be the case!
     * @param UserSelection $files
     */
    function filterUserSelectionToHidden($files){
        foreach ($files as $file){
            $file = basename($file);
            if(Utils::isHidden($file) && !$this->driverConf["SHOW_HIDDEN_FILES"]){
                throw new \Exception("Forbidden");
            }
            if($this->filterFile($file) || $this->filterFolder($file)){
                throw new \Exception("Forbidden");
            }
        }
    }

    function filterNodeName($nodePath, $nodeName, &$isLeaf, $lsOptions){
        $isLeaf = (is_file($nodePath."/".$nodeName) || Utils::isBrowsableArchive($nodeName));
        if(Utils::isHidden($nodeName) && !$this->driverConf["SHOW_HIDDEN_FILES"]){
            return false;
        }
        $nodeType = "d";
        if($isLeaf){
            if(Utils::isBrowsableArchive($nodeName)) $nodeType = "z";
            else $nodeType = "f";
        }
        if(!$lsOptions[$nodeType]) return false;
        if($nodeType == "d"){
            if(RecycleBinManager::recycleEnabled()
                && $nodePath."/".$nodeName == RecycleBinManager::getRecyclePath()){
                    return false;
                }
            return !$this->filterFolder($nodeName);
        }else{
            if($nodeName == "." || $nodeName == "..") return false;
            if(RecycleBinManager::recycleEnabled()
                && $nodePath == RecycleBinManager::getRecyclePath()
                && $nodeName == RecycleBinManager::getCacheFileName()){
                return false;
            }
            return !$this->filterFile($nodeName);
        }
    }

    function filterFile($fileName){
        $pathParts = pathinfo($fileName);
        if(array_key_exists("HIDE_FILENAMES", $this->driverConf) && !empty($this->driverConf["HIDE_FILENAMES"])){
            if(!is_array($this->driverConf["HIDE_FILENAMES"])) {
                $this->driverConf["HIDE_FILENAMES"] = explode(",",$this->driverConf["HIDE_FILENAMES"]);
            }
            foreach ($this->driverConf["HIDE_FILENAMES"] as $search){
                if(strcasecmp($search, $pathParts["basename"]) == 0) return true;
            }
        }
        if(array_key_exists("HIDE_EXTENSIONS", $this->driverConf) && !empty($this->driverConf["HIDE_EXTENSIONS"])){
            if(!is_array($this->driverConf["HIDE_EXTENSIONS"])) {
                $this->driverConf["HIDE_EXTENSIONS"] = explode(",",$this->driverConf["HIDE_EXTENSIONS"]);
            }
            if(isset($pathParts["extension"])) {
                foreach ($this->driverConf["HIDE_EXTENSIONS"] as $search){
                    if(strcasecmp($search, $pathParts["extension"]) == 0) return true;
                }
            }
        }
        return false;
    }

    function filterFolder($folderName, $compare = "equals"){
        if(array_key_exists("HIDE_FOLDERS", $this->driverConf) && !empty($this->driverConf["HIDE_FOLDERS"])){
            if(!is_array($this->driverConf["HIDE_FOLDERS"])) {
                $this->driverConf["HIDE_FOLDERS"] = explode(",",$this->driverConf["HIDE_FOLDERS"]);
            }
            foreach ($this->driverConf["HIDE_FOLDERS"] as $search){
                if($compare == "equals" && strcasecmp($search, $folderName) == 0) return true;
                if($compare == "contains" && strpos($folderName, "/".$search) !== false) return true;
            }
        }
        return false;
    }

    // This is used to catch exception while downloading
    function download_exception_handler($exception){

    }

    function readFile($filePathOrData, $headerType="plain", $localName="", $data=false, $gzip=null, $realfileSystem=false, $byteOffset=-1, $byteLength=-1){
        if($gzip === null){
            $gzip = ConfService::getCoreConf("GZIP_COMPRESSION");
        }
        if(!$realfileSystem && $this->wrapperClassName == self::DEFAULT_ACCESSWRAPPER_CLASSNAME){
            $originalFilePath = $filePathOrData;
            $filePathOrData = DcoAccessWrapper::patchPathForBaseDir($filePathOrData);
        }
        session_write_close();

        restore_error_handler();
        restore_exception_handler();

        set_exception_handler(array($this, 'download_exception_handler'));
        set_error_handler(array($this, 'download_exception_handler'));
        // required for IE, otherwise Content-disposition is ignored
        if(ini_get('zlib.output_compression')) {
         Utils::safeIniSet('zlib.output_compression', 'Off');
        }

        $isFile = !$data && !$gzip;
        if($byteLength == -1){
            if($data){
                $size = strlen($filePathOrData);
            }else if ($realfileSystem){
                $size = sprintf("%u", filesize($filePathOrData));
            }else{
                $size = $this->filesystemFileSize($filePathOrData);
            }
        }else{
            $size = $byteLength;
        }
        if($gzip && ($size > ConfService::getCoreConf("GZIP_LIMIT") || !function_exists("gzencode") || @strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') === FALSE)){
            $gzip = false; // disable gzip
        }

        $localName = ($localName=="" ? basename((isSet($originalFilePath)?$originalFilePath:$filePathOrData)) : $localName);
        if($headerType == "plain")
        {
            header("Content-type:text/plain");
        }
        else if($headerType == "image")
        {
            header("Content-Type: ".Utils::getImageMimeType(basename($filePathOrData))."; name=\"".$localName."\"");
            header("Content-Length: ".$size);
            header('Cache-Control: public');
        }
        else
        {
            /*
            if(preg_match('/ MSIE /',$_SERVER['HTTP_USER_AGENT']) || preg_match('/ WebKit /',$_SERVER['HTTP_USER_AGENT'])){
                $localName = str_replace("+", " ", urlencode(SystemTextEncoding::toUTF8($localName)));
            }
            */
            if ($isFile) {
                header("Accept-Ranges: 0-$size");
                Logger::debug("Sending accept range 0-$size");
            }

            // Check if we have a range header (we are resuming a transfer)
            if ( isset($_SERVER['HTTP_RANGE']) && $isFile && $size != 0 )
            {
                if($headerType == "stream_content"){
                    if(extension_loaded('fileinfo')  && $this->wrapperClassName == self::DEFAULT_ACCESSWRAPPER_CLASSNAME){
                        $fInfo = new fInfo( FILEINFO_MIME );
                        $realfile = call_user_func(array($this->wrapperClassName, "getRealFSReference"), $filePathOrData);
                        $mimeType = $fInfo->file( $realfile);
                        $splitChar = explode(";", $mimeType);
                        $mimeType = trim($splitChar[0]);
                        Logger::debug("Detected mime $mimeType for $realfile");
                    }else{
                        $mimeType = Utils::getStreamingMimeType(basename($filePathOrData));
                    }
                    header('Content-type: '.$mimeType);
                }
                // multiple ranges, which can become pretty complex, so ignore it for now
                $ranges = explode('=', $_SERVER['HTTP_RANGE']);
                $offsets = explode('-', $ranges[1]);
                $offset = floatval($offsets[0]);

                $length = floatval($offsets[1]) - $offset;
                if (!$length) $length = $size - $offset;
                if ($length + $offset > $size || $length < 0) $length = $size - $offset;
                Logger::debug('Content-Range: bytes ' . $offset . '-' . $length . '/' . $size);
                header('HTTP/1.1 206 Partial Content');
                header('Content-Range: bytes ' . $offset . '-' . ($offset + $length) . '/' . $size);

                header("Content-Length: ". $length);
                $file = fopen($filePathOrData, 'rb');
                fseek($file, 0);
                $relOffset = $offset;
                while ($relOffset > 2.0E9)
                {
                    // seek to the requested offset, this is 0 if it's not a partial content request
                    fseek($file, 2000000000, SEEK_CUR);
                    $relOffset -= 2000000000;
                    // This works because we never overcome the PHP 32 bit limit
                }
                fseek($file, $relOffset, SEEK_CUR);

                while(ob_get_level()) ob_end_flush();
                $readSize = 0.0;
                $bufferSize = 1024 * 8;
                while (!feof($file) && $readSize < $length && connection_status() == 0)
                {
                    Logger::debug("dl reading $readSize to $length", $_SERVER["HTTP_RANGE"]);
                    echo fread($file, $bufferSize);
                    $readSize += $bufferSize;
                    flush();
                }

                fclose($file);
                return;
            } else
            {
                if($gzip){
                    $gzippedData = ($data?gzencode($filePathOrData,9):gzencode(file_get_contents($filePathOrData), 9));
                    $size = strlen($gzippedData);
                }
                HTMLWriter::generateAttachmentsHeader($localName, $size, $isFile, $gzip);
                if($gzip){
                    print $gzippedData;
                    return;
                }
            }
        }

        if($data){
            print($filePathOrData);
        }else{
            if($this->pluginConf["USE_XSENDFILE"] && $this->wrapperClassName == self::DEFAULT_ACCESSWRAPPER_CLASSNAME){
                if(!$realfileSystem) $filePathOrData = DcoAccessWrapper::getRealFSReference($filePathOrData);
                $filePathOrData = str_replace("\\", "/", $filePathOrData);
                header("X-Sendfile: ".SystemTextEncoding::toUTF8($filePathOrData));
                header("Content-type: application/octet-stream");
                header('Content-Disposition: attachment; filename="' . basename($filePathOrData) . '"');
                return;
            }
            $stream = fopen("php://output", "a");
            if($realfileSystem){
                Logger::debug("realFS!", array("file"=>$filePathOrData));
                $fp = fopen($filePathOrData, "rb");
                if($byteOffset != -1){
                    fseek($fp, $byteOffset);
                }
                $sentSize = 0;
                $readChunk = 4096;
                while (!feof($fp)) {
                    if( $byteLength != -1 &&  ($sentSize + $readChunk) >= $byteLength){
                        // compute last chunk and break after
                        $readChunk = $byteLength - $sentSize;
                        $break = true;
                    }
                    $data = fread($fp, $readChunk);
                    $dataSize = strlen($data);
                    fwrite($stream, $data, $dataSize);
                    $sentSize += $dataSize;
                    if(isSet($break)){
                        break;
                    }
                }
                fclose($fp);
            }else{
                call_user_func(array($this->wrapperClassName, "copyFileInStream"), $filePathOrData, $stream);
            }
            fflush($stream);
            fclose($stream);
        }
    }

    function countFiles($dirName, $foldersOnly = false, $nonEmptyCheckOnly = false){
        $handle=@opendir($dirName);
        if($handle === false){
            throw new \Exception("Error while trying to open directory ".$dirName);
        }
        if($foldersOnly && !call_user_func(array($this->wrapperClassName, "isRemote"))){
            closedir($handle);
            $path = call_user_func(array($this->wrapperClassName, "getRealFSReference"), $dirName);
            $dirs = glob($path."/*", GLOB_ONLYDIR|GLOB_NOSORT);
            if($dirs === false) return 0;
            return count($dirs);
        }
        $count = 0;
        while (strlen($file = readdir($handle)) > 0)
        {
            if($file != "." && $file !=".."
                && !(Utils::isHidden($file) && !$this->driverConf["SHOW_HIDDEN_FILES"])){
                if($foldersOnly && is_file($dirName."/".$file)) continue;
                $count++;
                if($nonEmptyCheckOnly) break;
            }
        }
        closedir($handle);
        return $count;
    }

    function date_modif($file)
    {
        $tmp = @filemtime($file) ?? 0;
        return $tmp;// date("d,m L Y H:i:s",$tmp);
    }

    function changeMode($filePath)
    {
        $chmodValue = $this->repository->getOption("CHMOD_VALUE");
        if(isSet($chmodValue) && $chmodValue != "")
        {
            $chmodValue = octdec(ltrim($chmodValue, "0"));
            call_user_func(array($this->wrapperClassName, "changeMode"), $filePath, $chmodValue);
        }
    }

    function filesystemFileSize($filePath){
        $bytesize = "-";
        $bytesize = @filesize($filePath);
        if(method_exists($this->wrapperClassName, "getLastRealSize")){
            $last = call_user_func(array($this->wrapperClassName, "getLastRealSize"));
            if($last !== false){
                $bytesize = $last;
            }
        }
        if($bytesize < 0){
            $bytesize = sprintf("%u", $bytesize);
        }

        return $bytesize;
    }

    /**
     * Extract an archive directly inside the dest directory.
     *
     * @param string $destDir
     * @param UserSelection $selection
     * @param array $error
     * @param array $success
     */
    function extractArchive($destDir, $selection, &$error, &$success){
        require_once(APP_VENDOR_FOLDER."/pclzip/pclzip.lib.php");
        $zipPath = $selection->getZipPath(true);
        $zipLocalPath = $selection->getZipLocalPath(true);
        if(strlen($zipLocalPath)>1 && $zipLocalPath[0] == "/") $zipLocalPath = substr($zipLocalPath, 1)."/";
        $files = $selection->getFiles();
        $newFiles = array();
        $realZipFile = call_user_func(array($this->wrapperClassName, "getRealFSReference"), $this->urlBase.$zipPath);
        $archive = new \PclZip($realZipFile);
        $content = $archive->listContent();
        foreach ($files as $key => $item){// Remove path
            $item = substr($item, strlen($zipPath));
            if($item[0] == "/") $item = substr($item, 1);
            foreach ($content as $zipItem){
                $fileName = $zipItem["stored_filename"];
                if($fileName == $item || $fileName == $item."/"){
                    $files[$key] = $fileName;
                    break;
                }else{
                    unset($files[$key]);
                }
                if(substr_count(substr($fileName, 0, strlen($fileName) - 2), "/") == 0) {
                    array_push($newFiles, $zipItem["stored_filename"]);
                }
            }
        }
        Logger::debug("Archive", $files);
        $realDestination = call_user_func(array($this->wrapperClassName, "getRealFSReference"), $this->urlBase.$destDir);
        Logger::debug("Extract", array($realDestination, $realZipFile, $files, $zipLocalPath));
        if(count($files) == 0) {
            $result = $archive->extract(
                                    PCLZIP_OPT_PATH, $realDestination,
                                    PCLZIP_OPT_REMOVE_PATH, $zipLocalPath);

           $selection->setFiles($newFiles);
        } else {
            $result = $archive->extract(PCLZIP_OPT_BY_NAME, $files,
                                    PCLZIP_OPT_PATH, $realDestination."/".$zipLocalPath,
                                    PCLZIP_OPT_REMOVE_PATH, $zipLocalPath);
        }
        if($result <= 0){
            $error[] = $archive->errorInfo(true);
        }else{
            $mess = ConfService::getMessages();
            $success[] = sprintf($mess[368], basename($zipPath), $destDir);
        }
    }

    function copyOrMove($destDir, $selectedFiles, &$error, &$success, $move = false)
    {
        Logger::debug("CopyMove", array("dest"=>$destDir, "selection" => $selectedFiles));
        $mess = ConfService::getMessages();
        if(!$this->isWriteable($this->urlBase.$destDir))
        {
            $error[] = $mess[38]." ".$destDir." ".$mess[99];
            return ;
        }

        foreach ($selectedFiles as $selectedFile)
        {
            if($move && !$this->isWriteable(dirname($this->urlBase.$selectedFile)))
            {
                $error[] = "\n".$mess[38]." ".dirname($selectedFile)." ".$mess[99];
                continue;
            }
            $this->copyOrMoveFile($destDir, $selectedFile, $error, $success, $move);
        }
    }

    function renameAction($actionName, $httpVars)
    {
        $filePath = SystemTextEncoding::fromUTF8($httpVars["file"]);
        $newFilename = SystemTextEncoding::fromUTF8($httpVars["filename_new"]);
        return $this->rename($filePath, $newFilename);
    }

    function rename($filePath, $filename_new, $dest = null)
    {
        $nom_fic=basename($filePath);
        $mess = ConfService::getMessages();
        $filename_new=Utils::sanitize(SystemTextEncoding::magicDequote($filename_new), APP_SANITIZE_HTML_STRICT);
        $filename_new = substr($filename_new, 0, ConfService::getCoreConf("NODENAME_MAX_LENGTH"));
        $old=$this->urlBase."/$filePath";
        if(!$this->isWriteable($old))
        {
            throw new ApplicationException($mess[34]." ".$nom_fic." ".$mess[99]);
        }
        if($dest == null) $new=dirname($old)."/".$filename_new;
        else $new = $this->urlBase.$dest;
        if($filename_new=="" && $dest == null)
        {
            throw new ApplicationException("$mess[37]");
        }
        if(file_exists($new))
        {
            throw new ApplicationException("$filename_new $mess[43]");
        }
        if(!file_exists($old))
        {
            throw new ApplicationException($mess[100]." $nom_fic");
        }
        $oldNode = new ManifestNode($old);
        Controller::applyHook("node.before_path_change", array(&$oldNode));
        self::myRename($old, $new);
        Controller::applyHook("node.change", array($oldNode, new ManifestNode($new), false));
    }

    public static function autoRenameForDest($destination, $fileName){
        if(!is_file($destination."/".$fileName)) return $fileName;
        $i = 1;
        $ext = "";
        $name = "";
        $split = explode(".", $fileName);
        if(count($split) > 1){
            $ext = ".".$split[count($split)-1];
            array_pop($split);
            $name = join(".", $split);
        }else{
            $name = $fileName;
        }
        while (is_file($destination."/".$name."-$i".$ext)) {
            $i++; // increment i until finding a non existing file.
        }
        return $name."-$i".$ext;
    }

    function mkDir($crtDir, $newDirName)
    {
        $currentNodeDir = new ManifestNode($this->urlBase.$crtDir);
        Controller::applyHook("node.before_change", array(&$currentNodeDir));

        $mess = ConfService::getMessages();
        if($newDirName=="")
        {
            return "$mess[37]";
        }
        if(file_exists($this->urlBase."$crtDir/$newDirName"))
        {
            return "$mess[40]";
        }
        if(!$this->isWriteable($this->urlBase."$crtDir"))
        {
            return $mess[38]." $crtDir ".$mess[99];
        }

        $dirMode = 0775;
        $chmodValue = $this->repository->getOption("CHMOD_VALUE");
        if(isSet($chmodValue) && $chmodValue != "")
        {
            $dirMode = octdec(ltrim($chmodValue, "0"));
            if ($dirMode & 0400) $dirMode |= 0100; // User is allowed to read, allow to list the directory
            if ($dirMode & 0040) $dirMode |= 0010; // Group is allowed to read, allow to list the directory
            if ($dirMode & 0004) $dirMode |= 0001; // Other are allowed to read, allow to list the directory
        }
        $old = umask(0);
        mkdir($this->urlBase."$crtDir/$newDirName", $dirMode);
        umask($old);
        $newNode = new ManifestNode($this->urlBase.$crtDir."/".$newDirName);
        Controller::applyHook("node.change", array(null, $newNode, false));
        return null;
    }

    function createEmptyFile($crtDir, $newFileName, $content = "")
    {
        if(($content == "") && preg_match("/\.html$/",$newFileName)||preg_match("/\.htm$/",$newFileName)){
            $content = "<html>\n<head>\n<title>New Document - Created By BoA</title>\n<meta http-equiv=\"Content-Type\" content=\"text/html; charset=iso-8859-1\">\n</head>\n<body bgcolor=\"#FFFFFF\" text=\"#000000\">\n\n</body>\n</html>\n";
            Controller::applyHook("node.before_create", array(new ManifestNode($this->urlBase.$crtDir."/".$newFileName), strlen($content)));
        }
        Controller::applyHook("node.before_change", array(new ManifestNode($this->urlBase.$crtDir)));
        $mess = ConfService::getMessages();
        if($newFileName=="")
        {
            return "$mess[37]";
        }
        if(file_exists($this->urlBase."$crtDir/$newFileName"))
        {
            return "$mess[71]";
        }
        if(!$this->isWriteable($this->urlBase."$crtDir"))
        {
            return "$mess[38] $crtDir $mess[99]";
        }
        $fp=fopen($this->urlBase."$crtDir/$newFileName","w");
        if($fp)
        {

            if($content != ""){
                fputs($fp, $content);
            }
            $this->changeMode($this->urlBase."$crtDir/$newFileName");
            fclose($fp);
            $newNode = new ManifestNode($this->urlBase."$crtDir/$newFileName");
            Controller::applyHook("node.change", array(null, $newNode, false));
            return null;
        }
        else
        {
            return "$mess[102] $crtDir/$newFileName (".$fp.")";
        }
    }

    function delete($selectedFiles, &$logMessages)
    {
        $mess = ConfService::getMessages();
        foreach ($selectedFiles as $selectedFile)
        {
            if($selectedFile == "" || $selectedFile == DIRECTORY_SEPARATOR)
            {
                return $mess[120];
            }
            $fileToDelete=$this->urlBase.$selectedFile;
            if(!file_exists($fileToDelete))
            {
                $logMessages[]=$mess[100]." ".SystemTextEncoding::toUTF8($selectedFile);
                continue;
            }
            $this->deldir($fileToDelete);
            if(is_dir($fileToDelete))
            {
                $logMessages[]="$mess[38] ".SystemTextEncoding::toUTF8($selectedFile)." $mess[44].";
            }
            else
            {
                $logMessages[]="$mess[34] ".SystemTextEncoding::toUTF8($selectedFile)." $mess[44].";
            }
            Controller::applyHook("node.change", array(new ManifestNode($fileToDelete)));
        }
        return null;
    }

    function copyOrMoveFile($destDir, $srcFile, &$error, &$success, $move = false)
    {
        $mess = ConfService::getMessages();
        $destFile = $this->urlBase.$destDir."/".basename($srcFile);
        $realSrcFile = $this->urlBase.$srcFile;
        if(!file_exists($realSrcFile))
        {
            $error[] = $mess[100].$srcFile;
            return ;
        }
        if(!$move){
            Controller::applyHook("node.before_create", array(new ManifestNode($destFile), filesize($realSrcFile)));
        }
        if(dirname($realSrcFile)==dirname($destFile))
        {
            if($move){
                $error[] = $mess[101];
                return ;
            }else{
                $base = basename($srcFile);
                $i = 1;
                if(is_file($realSrcFile)){
                    $dotPos = strrpos($base, ".");
                    if($dotPos>-1){
                        $radic = substr($base, 0, $dotPos);
                        $ext = substr($base, $dotPos);
                    }
                }
                // auto rename file
                $i = 1;
                $newName = $base;
                while (file_exists($this->urlBase.$destDir."/".$newName)) {
                    $suffix = "-$i";
                    if(isSet($radic)) $newName = $radic . $suffix . $ext;
                    else $newName = $base.$suffix;
                    $i++;
                }
                $destFile = $this->urlBase.$destDir."/".$newName;
            }
        }
        if(!is_file($realSrcFile))
        {
            $errors = array();
            $succFiles = array();
            if($move){
                Controller::applyHook("node.before_path_change", array(new ManifestNode($realSrcFile)));
                if(file_exists($destFile)) $this->deldir($destFile);
                $res = self::myRename($realSrcFile, $destFile);
            }else{
                $dirRes = $this->dircopy($realSrcFile, $destFile, $errors, $succFiles);
            }
            if(count($errors) || (isSet($res) && $res!==true))
            {
                $error[] = $mess[114];
                return ;
            }else{
                Controller::applyHook("node.change", array(new ManifestNode($realSrcFile), new ManifestNode($destFile), !$move));
            }
        }
        else
        {
            if($move){
                Controller::applyHook("node.before_path_change", array(new ManifestNode($realSrcFile)));
                if(file_exists($destFile)) unlink($destFile);
                $res = self::myRename($realSrcFile, $destFile);
                Controller::applyHook("node.change", array(new ManifestNode($realSrcFile), new ManifestNode($destFile), false));
            }else{
                try{
                    if(call_user_func(array($this->wrapperClassName, "isRemote"))){
                        $src = fopen($realSrcFile, "r");
                        $dest = fopen($destFile, "w");
                        if($dest !== false){
                            while (!feof($src)) {
                                stream_copy_to_stream($src, $dest, 4096);
                            }
                            fclose($dest);
                        }
                        fclose($src);
                    }else{
                        copy($realSrcFile, $destFile);
                    }
                    $this->changeMode($destFile);
                    Controller::applyHook("node.change", array(new ManifestNode($realSrcFile), new ManifestNode($destFile), true));
                }catch (\Exception $e){
                    $error[] = $e->getMessage();
                    return ;
                }
            }
        }

        if($move)
        {
            // Now delete original
            // $this->deldir($realSrcFile); // both file and dir
            $messagePart = $mess[74]." ".SystemTextEncoding::toUTF8($destDir);
            if(RecycleBinManager::recycleEnabled() && $destDir == RecycleBinManager::getRelativeRecycle())
            {
                RecycleBinManager::fileToRecycle($srcFile);
                $messagePart = $mess[123]." ".$mess[122];
            }
            if(isset($dirRes))
            {
                $success[] = $mess[117]." ".SystemTextEncoding::toUTF8(basename($srcFile))." ".$messagePart." (".SystemTextEncoding::toUTF8($dirRes)." ".$mess[116].") ";
            }
            else
            {
                $success[] = $mess[34]." ".SystemTextEncoding::toUTF8(basename($srcFile))." ".$messagePart;
            }
        }
        else
        {
            if(RecycleBinManager::recycleEnabled() && $destDir == "/".$this->repository->getOption("RECYCLE_BIN"))
            {
                RecycleBinManager::fileToRecycle($srcFile);
            }
            if(isSet($dirRes))
            {
                $success[] = $mess[117]." ".SystemTextEncoding::toUTF8(basename($srcFile))." ".$mess[73]." ".SystemTextEncoding::toUTF8($destDir)." (".SystemTextEncoding::toUTF8($dirRes)." ".$mess[116].")";
            }
            else
            {
                $success[] = $mess[34]." ".SystemTextEncoding::toUTF8(basename($srcFile))." ".$mess[73]." ".SystemTextEncoding::toUTF8($destDir);
            }
        }

    }

    // A function to copy files from one directory to another one, including subdirectories and
    // nonexisting or newer files. Function returns number of files copied.
    // This function is PHP implementation of Windows xcopy  A:\dir1\* B:\dir2 /D /E /F /H /R /Y
    // Syntaxis: [$number =] dircopy($sourcedirectory, $destinationdirectory [, $verbose]);
    // Example: $num = dircopy('A:\dir1', 'B:\dir2', 1);

    function dircopy($srcdir, $dstdir, &$errors, &$success, $verbose = false, $convertSrcFile = true)
    {
        $num = 0;
        //$verbose = true;
        $recurse = array();
        if(!is_dir($dstdir)) mkdir($dstdir);
        if($curdir = opendir($srcdir))
        {
            while($file = readdir($curdir))
            {
                if($file != '.' && $file != '..')
                {
                    $srcfile = $srcdir . "/" . $file;
                    $dstfile = $dstdir . "/" . $file;
                    if(is_file($srcfile))
                    {
                        if(is_file($dstfile)) $ow = filemtime($srcfile) - filemtime($dstfile); else $ow = 1;
                        if($ow > 0)
                        {
                            try {
                                if($convertSrcFile) $tmpPath = call_user_func(array($this->wrapperClassName, "getRealFSReference"), $srcfile);
                                else $tmpPath = $srcfile;
                                if($verbose) echo "Copying '$tmpPath' to '$dstfile'...";
                                copy($tmpPath, $dstfile);
                                $success[] = $srcfile;
                                $num ++;
                                $this->changeMode($dstfile);
                            }catch (\Exception $e){
                                $errors[] = $srcfile;
                            }
                        }
                    }
                    else{
                        $recurse[] = array("src" => $srcfile, "dest"=> $dstfile);
                    }
                }
            }
            closedir($curdir);
            foreach($recurse as $rec){
                if($verbose) echo "Dircopy $srcfile";
                $num += $this->dircopy($rec["src"], $rec["dest"], $errors, $success, $verbose, $convertSrcFile);
            }
        }
        return $num;
    }

    function simpleCopy($origFile, $destFile)
    {
        return copy($origFile, $destFile);
    }

    public function isWriteable($dir, $type="dir")
    {
        if(isSet($this->pluginConf["USE_POSIX"]) && $this->pluginConf["USE_POSIX"] == true && extension_loaded('posix')){
            $real = call_user_func(array( $this->wrapperClassName, "getRealFSReference"), $dir);
            return posix_access($real, POSIX_W_OK);
        }
        return is_writable($dir);
    }

    function deldir($location)
    {
        if(is_dir($location))
        {
            Controller::applyHook("node.before_path_change", array(new ManifestNode($location)));
            $all=opendir($location);
            while ($file=readdir($all))
            {
                if (is_dir("$location/$file") && $file !=".." && $file!=".")
                {
                    $this->deldir("$location/$file");
                    if(file_exists("$location/$file")){
                        rmdir("$location/$file");
                    }
                    unset($file);
                }
                elseif (!is_dir("$location/$file"))
                {
                    if(file_exists("$location/$file")){
                        self::myUnlink("$location/$file");
                    }
                    unset($file);
                }
            }
            closedir($all);
            rmdir($location);
        }
        else
        {
            if(file_exists("$location")) {
                Controller::applyHook("node.before_path_change", array(new ManifestNode($location)));
                $test = @unlink("$location");
                if(!$test) throw new \Exception("Cannot delete file ".$location);
            }
        }
        if(basename(dirname($location)) == $this->repository->getOption("RECYCLE_BIN"))
        {
            // DELETING FROM RECYCLE
            RecycleBinManager::deleteFromRecycle($location);
        }
    }

    /**
     * Change file permissions
     *
     * @param String $path
     * @param String $chmodValue
     * @param Boolean $recursive
     * @param String $nodeType "both", "file", "dir"
     */
    function chmod($path, $chmodValue, $recursive=false, $nodeType="both", &$changedFiles=[])
    {
        $realValue = octdec(ltrim($chmodValue, "0"));
        if(is_file($this->urlBase.$path)){
            if($nodeType=="both" || $nodeType=="file"){
                call_user_func(array($this->wrapperClassName, "changeMode"), $this->urlBase.$path, $realValue);
                $changedFiles[] = $path;
            }
        }else{
            if($nodeType=="both" || $nodeType=="dir"){
                call_user_func(array($this->wrapperClassName, "changeMode"), $this->urlBase.$path, $realValue);
                $changedFiles[] = $path;
            }
            if($recursive){
                $handler = opendir($this->urlBase.$path);
                while ($child=readdir($handler)) {
                    if($child == "." || $child == "..") continue;
                    // do not pass realValue or it will be re-decoded.
                    $this->chmod($path."/".$child, $chmodValue, $recursive, $nodeType, $changedFiles);
                }
                closedir($handler);
            }
        }
    }

    /**
     * @param String $from
     * @param String $to
     * @param Boolean $copy
     */
    function nodeChanged(&$from, &$to, $copy = false){
        $fromNode = $toNode = null;
        if($from != null) $fromNode = new ManifestNode($this->urlBase.$from);
        if($to != null) $toNode = new ManifestNode($this->urlBase.$to);
        Controller::applyHook("node.change", array($fromNode, $toNode, $copy));
    }

    /**
     * @param String $node
     */
    function nodeWillChange($node, $newSize = null){
        if($newSize != null){
            Controller::applyHook("node.before_change", array(new ManifestNode($this->urlBase.$node), $newSize));
        }else{
            Controller::applyHook("node.before_path_change", array(new ManifestNode($this->urlBase.$node)));
        }
    }

    /**
     * @var DcoAccessDriver
     */
    public static $filteringDriverInstance;
    /**
     * @return zipfile
     */
    function makeZip ($src, $dest, $basedir)
    {
        @set_time_limit(0);
        require_once(APP_VENDOR_FOLDER."/pclzip/pclzip.lib.php");
        $filePaths = array();
        foreach ($src as $item){
            $realFile = call_user_func(array($this->wrapperClassName, "getRealFSReference"), $this->urlBase."/".$item);
            $realFile = Utils::securePath($realFile);
            $basedir = trim(dirname($realFile));
            if(basename($item) == ""){
                $filePaths[] = array(PCLZIP_ATT_FILE_NAME => $realFile);
            }else{
                $filePaths[] = array(PCLZIP_ATT_FILE_NAME => $realFile,
                                    PCLZIP_ATT_FILE_NEW_SHORT_NAME => basename($item));
            }
        }
        Logger::debug("Pathes", $filePaths);
        Logger::debug("Basedir", array($basedir));
        self::$filteringDriverInstance = $this;
        $archive = new \PclZip($dest);
        $vList = $archive->create($filePaths, PCLZIP_OPT_REMOVE_PATH, $basedir, PCLZIP_OPT_NO_COMPRESSION, PCLZIP_OPT_ADD_TEMP_FILE_ON, PCLZIP_CB_PRE_ADD, array($this, 'zipPreAddCallback'));
        if(!$vList){
            throw new \Exception("Zip creation error : ($dest) ".$archive->errorInfo(true));
        }
        self::$filteringDriverInstance = null;
        return $vList;
    }

    function recursivePurge($dirName, $purgeTime){

        $handle=opendir($dirName);
        $count = 0;
        while (strlen($file = readdir($handle)) > 0)
        {
            if($file == "" || $file == ".."  || Utils::isHidden($file) ){
                continue;
            }
            if(is_file($dirName."/".$file)){
                $time = filemtime($dirName."/".$file);
                $docAge = time() - $time;
                if( $docAge > $purgeTime){
                    $node = new ManifestNode($dirName."/".$file);
                    Controller::applyHook("node.before_path_change", array($node));
                    self::myUnlink($dirName."/".$file);
                    Controller::applyHook("node.change", array($node));
                    Logger::logAction("Purge", array("file" => $dirName."/".$file));
                    print(" - Purging document : ".$dirName."/".$file."\n");
                }
            }else{
                $this->recursivePurge($dirName."/".$file, $purgeTime);
            }
        }
        closedir($handle);

    }

    /** The publiclet URL making */
    function makePublicletOptions($filePath, $password, $expire, $downloadlimit, $repository)
    {
        $data = array(
            "DRIVER"=>$repository->getAccessType(),
            "OPTIONS"=>NULL,
            "FILE_PATH"=>$filePath,
            "ACTION"=>"download",
            "EXPIRE_TIME"=>$expire ? (time() + $expire * 86400) : 0,
            "DOWNLOAD_LIMIT"=>$downloadlimit ? $downloadlimit : 0,
            "PASSWORD"=>$password
        );
        return $data;
    }

    function makeSharedRepositoryOptions($httpVars, $repository){
        $newOptions = array(
            "PATH" => $repository->getOption("PATH").Utils::decodeSecureMagic($httpVars["file"]),
            "CREATE" => false,
            "RECYCLE_BIN" => "",
            "DEFAULT_RIGHTS" => "");
        if($repository->getOption("USE_SESSION_CREDENTIALS")===true){
            $newOptions["ENCODED_CREDENTIALS"] = Credential::getEncodedCredentialString();
        }
        return $newOptions;
    }

    function zipPreAddCallback($value, $header){
        if(DcoAccessDriver::$filteringDriverInstance == null) return true;
        $search = $header["filename"];
        return !(DcoAccessDriver::$filteringDriverInstance->filterFile($search)
        || DcoAccessDriver::$filteringDriverInstance->filterFolder($search, "contains"));
    }

    function renameBookmark($oldNode, $newNode){
        if ($oldNode == null) return;
        $loggedUser = AuthService::getLoggedUser();
        if ($loggedUser == null) return;

        $oldpath = $oldNode->getPath();
        if ($loggedUser->removeBookmark($oldpath)){
            $loggedUser->addBookmark($newNode->getPath());
        }
    }


    /****
    Utilitary methods
    ****/
    private static function myUnlink($path){
        $dirname = dirname($path);
        $filename = basename($path);
        unlink($file);
        if (file_exists("$dirname/.$filename.manifest")){
            unlink("$dirname/.$filename.manifest");
        }
    }

    private static function myRename($old, $new){
        $res = rename($old,$new);
        //Rename metadata
        $old_metadata = dirname($old)."/.".basename($old).".manifest";
        $new_metadata = dirname($new)."/.".basename($new).".manifest";
        $title = basename($new);
        self::renameMetadataFile($old_metadata, $new_metadata, $title);
        //Rename published metadata
        self::renameMetadataFile($old_metadata.".published", $new_metadata.".published", $title);
        return $res;
    }

    private static function renameMetadataFile($filename, $newFilename, $title){
        if (file_exists($filename)){
            rename($filename, $newFilename);
            $meta = json_decode(file_get_contents($newFilename));
            $meta->manifest->title = $title;
            self::saveJsonFile($newFilename, $meta);
        }
    }

    private function getExplorer(){
        if (is_null($this->_explorer)){
            require_once(APP_PLUGINS_FOLDER."/access.dco/DcoExplorer.class.php");
            $this->_explorer = new DcoExplorer($this);
        }
        return $this->_explorer;
    }

    private function updateManifest($dir, $manifest){
        $filename="/.manifest";
        if (!isset($manifest->lastupdated)) $manifest->lastupdated = date('c');
        $meta = json_decode(file_get_contents($dir.$filename));
        $manifest->is_a = 'dco';
        $meta->manifest = $manifest;
        self::saveJsonFile($dir.$filename, $meta);
    }

    private static function saveJsonFile($path, $json){
        $fp = fopen($path, "w");
        if($fp !== false){
            $content = json_encode($json);
            @fwrite($fp, $content, strlen($content));
            @fclose($fp);
        }
    }

    private function createManifest($dir, $json){
        $filename="/.manifest";
        $json->manifest->lastupdated = date('c');
        $json->is_a = 'dco';
        $content = json_encode($json);
        $error = $this->createEmptyFile($dir, $filename, $content);
        if(isSet($error)){
            throw new ApplicationException($error);
        }
    }

    private function updateCustomIcon($path, $oldIconId, $newIconId){
        if ($oldIconId == $newIconId) return; //There is nothing to do.

        if ($oldIconId != null) { //need to delete oldIconId
            $targetPath = $path."/src/".$oldIconId;
            @unlink($targetPath);
        }

        if ($newIconId != null) { //need to save the newIconId
            $targetPath = $path."/src/".$newIconId;
            $iconFile = fopen($targetPath, "wb");
            ConfService::getConfStorageImpl()->loadBinary(array(), $newIconId, $iconFile);
            fclose($iconFile);
            ConfService::getConfStorageImpl()->deleteBinary(array(), $newIconId);
        }
    }

    private function getUniqueId($dir){
        $uid = GUID();
        $suffix = $this->driverConf["DCOFOLDER_SUFFIX"];
        while (file_exists($this->urlBase.$dir."/".$uid.$suffix)) $uid = GUID();
        return $uid.$suffix;
    }

    private function setObjectEntryPoint($path){
        if (!file_exists($this->urlBase . $path)){
            Logger::logAction("ERROR : setObjectEntryPoint : Invalid Path : " . $path, "");
            return $mess["access_dco.set_entry_point_invalid_path"];
        }

        $parts = explode('/', $path);
        if (count($parts) < 3 || $parts[2] !== 'content'){
            Logger::logAction("ERROR : setObjectEntryPoint : Path is not a content file : " . $path, "");
            return $mess["access_dco.set_entry_point_not_content"];
        }

        $object_id = implode('/', array_slice($parts, 0, 2));
        $manifestPath = $this->urlBase . $object_id . "/.manifest";
        if (!file_exists($manifestPath)){
            Logger::logAction("ERROR : setObjectEntryPoint : Manifest file not found : " . $path, "");
            return $mess["access_dco.set_entry_point_manifest_error"];
        }

        $json = json_decode(file_get_contents($manifestPath));
        if (!$json || $json === null){
            Logger::logAction("ERROR : setObjectEntryPoint : Corrupted manifest file : " . $path, "");
            return $mess["access_dco.set_entry_point_manifest_error"];
        }

        array_splice($parts, 0, 3);
        $path = implode('/', $parts);
        $json->manifest->entrypoint = $path;
        self::saveJsonFile($manifestPath, $json);

        $manifestPath .=".published";
        //If object is already published, then update the preview icon there also
        if (file_exists($manifestPath)){
            $json = json_decode(file_get_contents($manifestPath));
            $json->manifest->entrypoint = $path;
            self::saveJsonFile($manifestPath, $json);
        }
        return "SUCCESS";
    }

}

