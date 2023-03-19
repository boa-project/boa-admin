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
 * @package    [App Plugins]
 * @category   [Meta]
 * @copyright  2017 BoA Project
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU Affero GPL v3 or later
 */
namespace BoA\Plugins\Meta\Lom;

use BoA\Core\Access\UserSelection;
use BoA\Core\Http\Controller;
use BoA\Core\Http\HTMLWriter;
use BoA\Core\Http\XMLWriter;
use BoA\Core\Plugins\Plugin;
use BoA\Core\Services\AuthService;
use BoA\Core\Services\ConfService;
use BoA\Core\Services\PluginsService;
use BoA\Core\Utils\Utils;
use BoA\Core\Utils\Text\SystemTextEncoding;
use BoA\Core\Xml\ManifestNode;
use BoA\Plugins\Access\Dco\DcoSpecProvider;

defined('APP_EXEC') or die( 'Access not allowed');

class LomMetaManager extends Plugin implements DcoSpecProvider {
    const PUBLISHED_STATUS = 'published';
    const INPROGRESS_STATUS = 'inprogress';
    const DIGITAL_RESOURCE_OBJECT = 'DIGITAL_RESOURCE_OBJECT';
    const META_PREFIX = 'meta_fields_';
    /**
     * @var AbstractAccessDriver
     */
    protected $accessDriver;
    /**
     * @var MetaStoreProvider
     */
    protected $metaStore;
    private $mess;

    public function init($options){
        $this->options = $options;
        // Do nothing
    }

    public function initMeta($accessDriver){
        $this->accessDriver = $accessDriver;
        parent::init($this->options);
    }

    protected function getMetaDefinition(){
        return array();
    }

    /**
     *
     * @param ManifestNode $node
     * @param bool $contextNode
     * @param bool $details
     * @return void
     */
    public function extractMeta(&$node, $contextNode = false, $details = false){
        $metaPath = $node->getUrl();
        if (is_dir($metaPath) && $node->APP_mime != 'dco') return;

        $this->mess = ConfService::getMessages();
        $isRoot = is_dir($metaPath);
        if ($isRoot){
            $metaPath .= "/.manifest";
        }
        else {
            $metaPath = dirname($metaPath)."/.".basename($metaPath).".manifest";
            $overlay = 'dro.png';
        }
        if (!file_exists($metaPath)) return;
        $content = file_get_contents($metaPath);
        $meta = json_decode($content);
        $metadata = array("lommetadata" => json_encode($meta->metadata));
        if ($meta->manifest && ($meta->manifest->type || $meta->manifest->is_a)){
            $metadata["lomtype"] = $meta->manifest->is_a;
        }

        if (!$isRoot){
            $metadata["status_id"] = $meta->manifest->status;
            $metadata["status"] = $this->mess["access_dco.".$meta->manifest->status];
            $metadata["lastupdated"] = $meta->manifest->lastupdated;
            if (!empty($meta->manifest->lastpublished)){
                $metadata["lastpublished"] = $meta->manifest->lastpublished;
            }
            else {
                $metadata["lastpublished"] = 0;
            }
        }
        $status = $meta->manifest->status;
        if ($status == self::PUBLISHED_STATUS){
            if (empty($meta->manifest->lastpublished) || $meta->manifest->lastpublished < $meta->manifest->lastupdated){
                $overlay = (isset($overlay)?$overlay.",":"")."alert.png";
            }
            else {
                $overlay = (isset($overlay)?$overlay.",":"")."ok.png";
            }

        }
        else if ($status == self::INPROGRESS_STATUS) {
            $overlay = (isset($overlay)?$overlay.",":"")."alert.png";
        }
        if (isset($overlay)){
            $metadata['overlay_icon'] = $overlay;
        }
        $node->mergeMetadata($metadata);
    }

    /**
     *
     * @param ManifestNode $oldFile
     * @param ManifestNode $newFile
     * @param Boolean $copy
     */
    public function updateMetaLocation($oldFile, $newFile = null, $copy = false){
        if($oldFile == null) return;
            if(!$copy && $this->metaStore->inherentMetaMove()) return;

        $oldMeta = $this->metaStore->retrieveMetadata($oldFile, "lom_meta", false, APP_METADATA_SCOPE_GLOBAL);
        if(!count($oldMeta)){
            return;
        }
        // If it's a move or a delete, delete old data
        if(!$copy){
            $this->metaStore->removeMetadata($oldFile, "lom_meta", false, APP_METADATA_SCOPE_GLOBAL);
        }
        // If copy or move, copy data.
        if($newFile != null){
            $this->metaStore->setMetadata($newFile, "lom_meta", $oldMeta, false, APP_METADATA_SCOPE_GLOBAL);
        }
    }

    public function onGet($action, $httpVars, $fileVars){

        $mess = $this->mess = ConfService::getMessages();
        switch ($action) {
            case 'get_spec_by_id':
                $this->getSpecById($httpVars["spec_id"]);
                break;
            case 'get_specs_list':
                $this->loadSpecsAsJson();
                break;

            case 'mkdco':
                XMLWriter::header("output");
                $messtmp="";
                $dconame=Utils::decodeSecureMagic($httpVars["dirname"], APP_SANITIZE_HTML_STRICT);
                $dconame = substr($dirname, 0, ConfService::getCoreConf("NODENAME_MAX_LENGTH"));
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
                Logger::logAction("Create DCO", array("dir"=>$dir."/".$dirname));
                XMLWriter::close("output");
                break;

            default:
                break;
        }

    }

    public function onPost($action, $httpVars, $fileVars){
        $this->mess = ConfService::getMessages();
        switch ($action) {
            case "save_dcometa":
                $this->saveDcoMeta($action, $httpVars, $fileVars);
            break;
            case "convert_to_digital_resource":
                $this->convertToDigitalResource($action, $httpVars, $fileVars);
            break;
            case "publish_metadata":
                $this->publish();
        }
    }

    /* DcoSpecProvider Implementation */
    public function loadSpecs(){
        $specsPath = APP_DATA_PATH."/plugins/meta.lom/specs";

        if (!is_dir($specsPath)){
            mkdir($specsPath, 0755, true);
        }

        $list = new \stdClass();

        foreach(glob($specsPath."/*.xml") as $file){
            $xml = new \DOMDocument();
            $xml->load($file);
            $xpath = new \DOMXPath($xml);

            $id = $xpath->query("/spec/id");
            $name = $xpath->query("/spec/name");
            $list->{$id[0]->nodeValue} = $name[0]->nodeValue;
        }
        return $list;
    }

    public function getSpecById($id, $print=true){
        $specsPath = APP_DATA_PATH."/plugins/meta.lom/specs";

        if ($id === self::DIGITAL_RESOURCE_OBJECT) {
            $id = $this->options["dro_spec"];
        }

        $found = glob($specsPath."/".$id.".xml");
        if (count($found) > 0){
            $xml = new \DOMDocument();
            $xml->load($found[0]);
            if ($print){
                header('Content-Type: text/xml; charset=UTF-8');
                header('Cache-Control: no-cache');
                print ($xml->saveXML());
                return false;
            }
            return $xml;
        }
        return false;
    }

    public function initMetaFromSpec($dir, $specId){
        //Create metadata file based on specs defaults
        $spec = $this->getSpecById($specId, false);
        if (false === $spec){
            throw new \Exception("Unable to find DCO specification '{$specId}'");
        }
        $xpath = new \DOMXPath($spec);
        $fields = $xpath->query("/spec/fields");

        if ($fields == null) {
            throw new \Exception('Unable to load metadata setup');
        }

        $fields = $fields->item(0);
        $meta = $this->parseMetaToJson($fields);
        return $meta;
    }

    public function getMetaEditorClass(){
        return "LomMetaEditor";
    }

    private function loadSpecsAsJson(){
        $list = $this->loadSpecs();
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache');
        print(json_encode(array("LIST" => $list)));
    }

    private function parseParameters(&$repDef, &$options, $userId = null, $globalBinaries = false){
        Utils::parseStandardFormParameters($repDef, $options, $userId, "DCO_", ($globalBinaries?array():null));
    }

    private function readSpecFieldToJson($specField, $specXpath, &$parent, $parambasename, $meta, $colrow=""){
        if (!$specField->hasAttribute("enabled") || !($specField->getAttribute("enabled") == "true")){
            return false;
        }
        $type = $specField->getAttribute("type");
        $translatable = $specField->getAttribute("translatable");
        $isContainer = $type == "container";
        if ($type == "composed" || $isContainer){
            $isCollection = $specField->getAttribute("collection") == "true";
            $paramprefix = $isContainer ? $parambasename : $parambasename."_".$specField->nodeName;
            if ($isCollection){
                if ($isContainer){
                    $target = &$parent;
                }
                else {
                    $target = array();
                }

                $itemsFound = true;
                $index = 0;
                while($itemsFound){
                    $colrow = $index>0?"_$index":"";
                    $newObj = array();
                    foreach ($specField->childNodes as $child) {
                        if ($child->nodeType != XML_ELEMENT_NODE) continue;
                        //if ($child->nodeType == XML_CDATA_SECTION_NODE) continue;
                        $itemsFound = $this->readSpecFieldToJson($child, $specXpath, $newObj, $paramprefix, $meta, $colrow);
                        if (!$itemsFound){
                            break;
                        }
                    }
                    if ($itemsFound){
                        $target[] = $newObj;
                        $index++;
                    }
                }
                if (!$isContainer && count($target)){
                    $parent[$specField->nodeName] = &$target;
                }
            }
            else{
                $newObj = array();
                foreach ($specField->childNodes as $child) {
                    if ($child->nodeType != XML_ELEMENT_NODE) continue;
                    //if ($child->nodeType == XML_CDATA_SECTION_NODE) continue;
                    $this->readSpecFieldToJson($child, $specXpath, $newObj, $paramprefix.$colrow, $meta);
                }
                if (count($newObj)){
                    $parent[$specField->nodeName] = $newObj;
                }
            }
        }
        else if ($type == "duration"){
            $segments = explode(" ", "years months days hours minutes seconds");
            $newObj = array();
            $ret = false;
            foreach ($segments as $segment) {
                $key = $parambasename."_".$specField->nodeName."_".$segment;
                if (array_key_exists($key, $meta)){
                    $newObj[$segment] = intval($meta[$key]);
                    $ret = true;
                }
            }
            if ($ret) {
                $parent[$specField->nodeName] = $newObj;
            }
            return $ret;
        }
        else if ($type == 'keywords'){
            $key = $parambasename."_".$specField->nodeName.$colrow;
            if (array_key_exists($key, $meta)){
                if ($translatable) {
                    $translations = $this->getTranslations($meta[$key]);
                    $value = array();
                    foreach (get_object_vars($translations) as $lang => $langValue) {
                        $value[$lang] = is_array($langValue) ? $langValue : array_map('trim', explode(',', $langValue));
                    }
                    $parent[$specField->nodeName] = $value;
                }
                else {
                    $parent[$specField->nodeName] = array_map('trim', explode(',', $meta[$key]));
                }
                return true;
            }
            return false;
        }
        else{
            $specType = $specXpath->query("//types/type[@name='$type']");
            if ($specType->length > 0){
                $newObj = array();
                $ret = false;
                foreach ($specType[0]->childNodes as $child) {
                    $key = $parambasename."_".$specField->nodeName."_".$child->nodeName.$colrow;
                    if (array_key_exists($key, $meta)){
                        $newObj[$child->nodeName] = $meta[$key];
                        $ret = true;
                    }
                }
                if ($ret){
                    $parent[$specField->nodeName] = $newObj;
                }
                return $ret;
            }
            $key = $parambasename."_".$specField->nodeName.$colrow;
            if (array_key_exists($key, $meta)){
                if ($translatable){
                    $parent[$specField->nodeName] = $this->getTranslations($meta[$key]);
                }
                else {
                    $parent[$specField->nodeName] = $meta[$key];
                }
                return true;
            }
            return false;
        }
    }

    private function getTranslations($value) {
        if ($value == null) return null;

        $translations = json_decode($value);
        if ($translations == null) {
            $translations = new \stdClass();
            $translations->none = $value;
        }
        return $translations;
    }

    private function saveDcoMeta($actionName, $httpVars, $fileVars){
        if(!isSet($this->actions[$actionName])) return;
        if(is_a($this->accessDriver, "demoAccessDriver")){
            throw new \Exception("Write actions are disabled in demo mode!");
        }
        $repo = $this->accessDriver->repository;
        $user = AuthService::getLoggedUser();
        if(!AuthService::usersEnabled() && $user!=null && !$user->canWrite($repo->getId())){
            throw new \Exception("You have no right on this action.");
        }
        $selection = new UserSelection();
        $selection->initFromHttpVars();
        $currentFile = $selection->getUniqueFile();
        $urlBase = $this->accessDriver->getResourceUrl($currentFile);
        //$node = new ManifestNode($urlBase);
        $meta = array();
        $this->parseParameters($httpVars, $meta, null, true);
        $spec_id = $httpVars["spec_id"];
        $unique = $httpVars["mode"] == 'single';
        $data = $this->createUpdateManifest($currentFile, $meta, $spec_id, $unique);

        //Controller::applyHook("node.meta_change", array($node));
        HTMLWriter::charsetHeader("application/json");
        echo isSet($data)?$data:"{}";
    }

    private function createUpdateManifest($currentFile, $meta, $spec_id, $unique){
        $spec = $this->getSpecById($spec_id, false);
        $xpath = new \DOMXPath($spec);
        $categories = $xpath->query("/spec/fields/*[@type='category']");

        if (is_array($meta)) {
            $metaobject = array();
            foreach($categories as $category){
                $object = array();
                foreach ($category->childNodes as $field){
                    if ($field->nodeType != XML_ELEMENT_NODE) continue;
                    $this->readSpecFieldToJson($field, $xpath, $object, self::META_PREFIX.$category->nodeName, $meta, "");
                }
                if (count($object)){
                    $metaobject[$category->nodeName] = $object;
                }
            }
        }
        else {
            $metaobject = $meta;
        }

        $isRoot = is_dir($this->accessDriver->urlBase.$currentFile);
        $target = $isRoot?$currentFile."/.manifest":
            dirname($currentFile)."/.".basename($currentFile).".manifest";

        $target = $this->accessDriver->urlBase.$target;
        $json = (file_exists($target) ? json_decode(file_get_contents($target)) : new \stdClass());
        $fp = fopen($target, "w");
        if($fp !== false){
            if (!isset($json->manifest)) {
                $json->manifest = new \stdClass();
                $json->manifest->title = basename($currentFile);
                $json->manifest->type = $spec_id;
                $json->manifest->status = self::INPROGRESS_STATUS;
                $json->manifest->id = $currentFile;
            }
            if (!isset($json->manifest->is_a)){
                $json->manifest->is_a = $isRoot?'dco':'dro';
            }
            $json->manifest->lastupdated = date('c');
            if (!isset($json->manifest->lastpublished)) {
                $json->manifest->lastpublished = 0;
            }
            $json->manifest->lastupdated = date('c');
            $json->metadata = $unique ? $metaobject : $this->mergeMetadata($json->metadata, $metaobject);
            $data = json_encode($json);
            @fwrite($fp, $data, strlen($data));
            @fclose($fp);
            return $data;
        }
    }

    private function mergeMetadata($basemeta, $metaobject){
        foreach ($metaobject as $key => $value) {
            if (is_array($value) && is_object($basemeta->$key)) {
                $this->mergeMetadata($basemeta->$key, $value);
            }
            else {
                $basemeta->$key = $value;
            }
        }
        return $basemeta;
    }

    private function publish(){
        $repo = $this->accessDriver->repository;
        $user = AuthService::getLoggedUser();
        if(!AuthService::usersEnabled() && $user!=null && !$user->canWrite($repo->getId())){
            throw new \Exception("You have no right on this action.");
        }

        $selection = new UserSelection();
        $selection->initFromHttpVars();
        $currentFile = $selection->getUniqueFile();
        $urlBase = $this->accessDriver->getResourceUrl($currentFile);

        $isRoot = is_dir($this->accessDriver->urlBase.$currentFile);
        $manifestPath = $isRoot?$currentFile."/.manifest":
            dirname($currentFile)."/.".basename($currentFile).".manifest";

        $manifestPath = $this->accessDriver->urlBase.$manifestPath;
        $json = json_decode(file_get_contents($manifestPath));
        $json->manifest->status = self::PUBLISHED_STATUS;
        $json->manifest->lastpublished = date('c');
        $fp = fopen($manifestPath, "w");
        if($fp !== false){
            $json->manifest->lastupdated = date('c');
            if (!isset($json->manifest->is_a)){
                $json->manifest->is_a = $isRoot?'dco':'dro';
            }
            $data = json_encode($json);
            @fwrite($fp, $data, strlen($data));
            @fclose($fp);
        }
        //Create a published version of the meta
        copy($manifestPath, $manifestPath.".published");

        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache');
        $manifest = $json->manifest;
        $specs = (array)$this->loadSpecs();
        $type = array_key_exists($manifest->type, $specs) ? $specs[$manifest->type] : $manifest->type;
        $manifest->type_id = $manifest->type;
        $manifest->type = $type;
        $manifest->status_id = $manifest->status;
        $manifest->status = $this->mess["access_dco.".$manifest->status];
        print(json_encode($manifest));
    }

    private function hasChildElements($node){
        if (!$node->hasChildNodes()) return false;

        foreach ($node->childNodes as $child) {
            if ($child->nodeType == XML_ELEMENT_NODE) return true;
        }
        return false;
    }

    private function parseMetaToJson($node){
        if ($this->hasChildElements($node)){
            $keys = array();

            if ($node->getAttribute('collection') === 'true' &&
                $node->getAttribute('fixed') === 'true'){
                $defaultString = $node->getAttribute('default');
                $default = array();
                if ($defaultString != null && $defaultString != ""){
                    $default = json_decode($defaultString);
                }
                foreach($default as $item){
                    $entry = array();
                    foreach(get_object_vars($item) as $key => $value){
                        $entry[$key] = $value->default;
                    }
                    $keys[] = $entry;
                }
                return $keys;
            }

            foreach ($node->childNodes as $childNode) {
                if ($childNode->nodeType == XML_TEXT_NODE) continue;
                if ($childNode->nodeType == XML_CDATA_SECTION_NODE) continue;
                $output = $this->parseMetaToJson($childNode);
                if ((is_array($output) && count($output) <= 0) ||
                    (is_string($output) && $output == null)
                    ){
                    continue; //Ignore empty results
                }
                $keys[$childNode->nodeName] = $output;
            }
            return $keys;
        }
        else {
            if ($node->hasAttribute("enabled") && $node->getAttribute("enabled") == "true"){
                $output = ($node->hasAttribute("defaultValue") ? $node->getAttribute("defaultValue") : "");
            }
            else
                $output = null;
        }
        return $output;
    }

    private function convertToDigitalResource($action, $httpVars, $fileVars){
        if(!isSet($this->actions[$action])) return;

        HTMLWriter::charsetHeader("application/json");
        header("Cache-Control: no-cache");
        try {
            if(is_a($this->accessDriver, "demoAccessDriver")){
                throw new \Exception("Write actions are disabled in demo mode!");
            }
            $repo = $this->accessDriver->repository;
            $user = AuthService::getLoggedUser();
            if(!AuthService::usersEnabled() && $user!=null && !$user->canWrite($repo->getId())){
                throw new \Exception("You have no right on this action.");
            }

            ob_start();
            ob_implicit_flush(true);

            $data = array("status" => "SCANNING", "processed" => 0,  "of" => 0);
            $this->partialJsonOutput($data);
            ob_flush();

            $dir = Utils::securePath(SystemTextEncoding::magicDequote($dir));
            $selection = new UserSelection();
            $selection->initFromHttpVars();
            $dir = $selection->getUniqueFile();
            $dir = Utils::securePath(SystemTextEncoding::magicDequote($dir));
            $rel_path = ($dir!= ""?($dir[0]=="/"?"":"/").$dir:"");
            $path = $this->accessDriver->urlBase.$rel_path;
            $path = call_user_func(array($this->accessDriver->wrapperClassName, "getRealFSReference"), $path);
            $rootpath = call_user_func(array($this->accessDriver->wrapperClassName, "getRealFSReference"), $this->accessDriver->urlBase);
            $recursively = $httpVars["recursively"];

            $pmeta = $this->getParentMeta($path, $rootpath);

            $all = $this->getFiles($path, preg_match('/true/i', $recursively));

            $data["status"] = "PROCESSING";
            $data["of"] = count($all);
            $this->partialJsonOutput($data);
            ob_flush();

            $start_time = microtime(true);
            $data["converted"] = 0;
            foreach($all as $file) {
                if ($this->assignDroMetadata($file, str_replace($rootpath, '', $file), $pmeta)) {
                    $data["converted"]++;
                }
                $data["processed"]++;
                $elapsed = (microtime(true) - $start_time) * 1000;
                if ($elapsed > 1000) {
                    $this->partialJsonOutput($data);
                    $start_time = microtime(true);
                }
            }

            $data["status"] = "COMPLETED";
            $data["processed"] = $data["of"];
            $this->partialJsonOutput($data);
        }
        catch(\Exception $e)
        {
            $result = array("status" => "FAILED", "message" => $e->getMessage());
            echo json_encode($result);
        }
    }

    private function getParentMeta($dir, $rootpath){
        $path = dirname($dir);
        $root = "";
        $i = 0;
        do {
            if (file_exists("$path/.manifest")){
                $fmanifest = "$path/.manifest";
                break;
            }
            $path = dirname($path);
        } while($path != $rootpath && $i++<100);

        $content = file_get_contents($fmanifest);
        $manifest = json_decode($content);
        return $manifest->metadata;

        /*$meta = array();
        $this->readJsonMeta($manifest->manifest->type, $metadata, $meta);
        return $meta;*/
    }

    /*private function readJsonMeta($specId, $json, &$meta){
        $spec = $this->getSpecById($specId, false);
        if (false === $spec){
            throw new \Exception("Unable to find DCO specification '{$specId}'");
        }
        $xpath = new \DOMXPath($spec);
        $fields = $xpath->query("/spec/fields");

        if ($fields == null) {
            throw new \Exception('Unable to load metadata setup');
        }
        $fields = $fields->item(0);
        return $this->parseJsonMetaToArray($fields, $json, $meta, trim(self::META_PREFIX, '_'));
    }

    private function parseJsonMetaToArray($node, $json, &$meta, $prefix){
        if ($this->hasChildElements($node)){
            foreach ($node->childNodes as $childNode) {
                if ($childNode->nodeType == XML_TEXT_NODE) continue;
                if ($childNode->nodeType == XML_CDATA_SECTION_NODE) continue;
                if (!isset($json->{$childNode->nodeName})) continue;
                $this->parseJsonMetaToArray($childNode, $json->{$childNode->nodeName}, $meta, $prefix."_".$childNode->nodeName);
            }
        }
        else {
            if ($node->nodeName == 'duration') {
                foreach (get_object_vars($json) as $key => $value) {
                    $meta[$prefix.'_'.$key] = $value;
                }
            }
            else {
                $translatable = $node->getAttribute("translatable");
                $meta[$prefix] = $json;
            }
        }
    }*/

    private function assignDroMetadata($path, $relpath, $pmeta){
        $filename = basename($path);
        $dir = dirname($path);
        $manifest = "$dir/.$filename.manifest";
        if (file_exists($manifest)) return false; //There is already a manifest, so the file already has metadata
        $meta = clone $pmeta;
        $meta = $this->mergeMetadata($meta, $this->getFileMeta($path));
        $this->createUpdateManifest($relpath, $meta, $this->options["dro_spec"], true);
        return true;
    }

    private function getFileMeta($path){
        $fileinfo = pathinfo($path);
        $meta = array("general" => array());

        $spec = $this->getSpecById($this->options["dro_spec"], false);
        $xpath = new \DOMXPath($spec);
        $field = $xpath->query("/spec/fields/general/title")->item(0);

        if ($field) {
            $translatable = $field->getAttribute("translatable");
            $value = str_replace('_', ' ', $fileinfo['filename']);
            $meta["general"]["title"] = $translatable ? $this->getTranslations($value) : $value;
        }
        $field = $xpath->query("/spec/fields/general/description")->item(0);
        if ($field) {
            $translatable = $field->getAttribute("translatable");
            $value = '';
            $meta["general"]["description"] = $translatable ? $this->getTranslations($value) : $value;
        }
        return $meta;
    }

    private function getFiles($dir, $recursively){
        $entries = scandir($dir);
        $files = array();
        foreach ($entries as $entry) {
            if (preg_match('/^(\.\.?|\..*(?<=\.)(manifest|published|metadata))$/', $entry)) continue;
            $fullname = $dir."/".$entry;
            if (is_file($fullname)) {
                $files[] = $fullname;
            }
            else if ($recursively) {
                $files = array_merge($files, $this->getFiles($fullname, $recursively));
            }
        }
        return $files;
    }

    private function partialJsonOutput($output){
        echo "\\n" . json_encode($output);
        ob_flush();
    }
}
