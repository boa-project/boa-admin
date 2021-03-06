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

/**
 * Full container of the data tree. Contains the SelectionModel as well.
 */
Class.create("DataModel", {

    _currentRep: undefined, 
    _bEmpty: undefined,
    _bUnique: false,
    _bFile: false,
    _bDir: false,
    _isRecycle: false,
    
    _pendingContextPath:null, 
    _pendingSelection:null,
    _selectionSource : {}, // fake object
    
    _rootNode : null,

    _globalEvents : true,

    /**
     * Constructor
     */
    initialize: function(localEvents){
        this._currentRep = '/';
        this._selectedNodes = $A([]);
        this._bEmpty = true;
        if(localEvents) this._globalEvents = false;
    },
    
    /**
     * Sets the data source that will feed the nodes with children.
     * @param iManifestNodeProvider IManifestNodeProvider 
     */
    setManifestNodeProvider : function(iManifestNodeProvider){
        this._iManifestNodeProvider = iManifestNodeProvider;
    },

    /**
     * Return the current data source provider
     * @return IManifestNodeProvider
     */
    getManifestNodeProvider : function(){
        return this._iManifestNodeProvider;
    },

    /**
     * Changes the current context node.
     * @param node ManifestNode Target node, either an existing one or a fake one containing the target part.
     * @param forceReload Boolean If set to true, the node will be reloaded even if already loaded.
     */
    requireContextChange : function(node, forceReload){
        if(node == null) return;
        var path = node.getPath();
        if((path == "" || path == "/") && node != this._rootNode){
            node = this._rootNode;
        }
        if(node.getMetadata().get('paginationData') && node.getMetadata().get('paginationData').get('new_page') 
            && node.getMetadata().get('paginationData').get('new_page') != node.getMetadata().get('paginationData').get('current')){
                var paginationPage = node.getMetadata().get('paginationData').get('new_page');
                forceReload = true;         
        }
        if(node != this._rootNode && (!node.getParent() || node.fake)){
            // Find in arbo or build fake arbo
            var fakeNodes = [];
            node = node.findInArbo(this._rootNode, fakeNodes);
            if(fakeNodes.length){
                var firstFake = fakeNodes.shift();
                firstFake.observeOnce("first_load", function(e){                    
                    this.requireContextChange(node);
                }.bind(this));
                firstFake.observeOnce("error", function(message){
                    app.displayMessage("ERROR", message);
                    firstFake.notify("node_removed");
                    var parent = firstFake.getParent();
                    parent.removeChild(firstFake);
                    delete(firstFake);
                    this.requireContextChange(parent);
                }.bind(this) );
                this.publish("context_loading");
                firstFake.load(this._iManifestNodeProvider);
                return;
            }
        }       
        node.observeOnce("loaded", function(){
            this.setContextNode(node, true);            
            this.publish("context_loaded");
            if(this.getPendingSelection()){
                var selPath = node.getPath() + (node.getPath() == "/" ? "" : "/" ) +this.getPendingSelection();
                var selNode =  node.findChildByPath(selPath);
                if(selNode) {
                    this.setSelectedNodes([selNode], this);
                }else{
                    if(node.getMetadata().get("paginationData") && arguments.length < 3){
                        var newPage;
                        var currentPage = node.getMetadata().get("current");
                        this.loadPathInfoSync(selPath, function(foundNode){
                            newPage = foundNode.getMetadata().get("page_position");
                        });
                        if(newPage && newPage != currentPage){
                            node.getMetadata().get("paginationData").set("new_page", newPage);
                            this.requireContextChange(node, true, true);
                            return;
                        }
                    }
                }
                this.clearPendingSelection();
            }
        }.bind(this));
        node.observeOnce("error", function(message){
            app.displayMessage("ERROR", message);
            this.publish("context_loaded");
        }.bind(this));
        this.publish("context_loading");
        try{
            if(forceReload){
                if(paginationPage){
                    node.getMetadata().get('paginationData').set('current', paginationPage);
                }
                node.reload(this._iManifestNodeProvider);
            }else{
                node.load(this._iManifestNodeProvider);
            }
        }catch(e){
            this.publish("context_loaded");
        }
    },

    requireNodeReload: function(nodeOrPath){
        if(Object.isString(nodeOrPath)){
            nodeOrPath = new ManifestNode(nodeOrPath);
        }
        var onComplete = null;
        if(this._selectedNodes.length) {
            var found = false;
            this._selectedNodes.each(function(node){
                if(node.getPath() == nodeOrPath.getPath()) found = node;
            });
            if(found){
                // TODO : MAKE SURE SELECTION IS OK AFTER RELOAD
                this._selectedNodes = this._selectedNodes.without(found);
                this.publish("selection_changed", this);
                onComplete = function(newNode){
                    this._selectedNodes.push(newNode);
                    this._selectionSource = {};
                    this.publish("selection_changed", this);
                }.bind(this);
            }
        }
        this._iManifestNodeProvider.refreshNodeAndReplace(nodeOrPath, onComplete);
    },

    loadPathInfoSync: function (path, callback){
        this._iManifestNodeProvider.loadLeafNodeSync(new ManifestNode(path), callback);
    },

    /**
     * Sets the root of the data store
     * @param rootNode ManifestNode The parent node
     */
    setRootNode : function(rootNode){
        this._rootNode = rootNode;
        this._rootNode.setRoot();
        this._rootNode.observe("child_added", function(c){
                //console.log(c);
        });
        this.publish("root_node_changed", this._rootNode);
        this.setContextNode(this._rootNode);
    },
    
    /**
     * Gets the current root node
     * @returns ManifestNode
     */
    getRootNode : function(){
        return this._rootNode;
    },
    
    /**
     * Sets the current context node
     * @param dataNode ManifestNode
     * @param forceEvent Boolean If set to true, event will be triggered even if the current node is already the same.
     */
    setContextNode : function(dataNode, forceEvent){
        if(this._contextNode && this._contextNode == dataNode && this._currentRep  == dataNode.getPath() && !forceEvent){
            return; // No changes
        }
        this._contextNode = dataNode;
        this._currentRep = dataNode.getPath();
        this.publish("context_changed", dataNode);
    },

    /**
     *
     */
    publish:function(eventName, optionalData){
        var args = $A(arguments).slice(1);
        //args.unshift(this);
        if(this._globalEvents){
            args.unshift("app:"+eventName);
            document.fire.apply(document, args);
        }else{
            if(args.length){
                args = [eventName, {memo:args[0]}];
            }else{
                args.unshift(eventName);
            }
            //args.unshift(eventName);
            this.notify.apply(this,args);
        }
    },

    /**
     * Get the current context node
     * @returns ManifestNode
     */
    getContextNode : function(){
        return this._contextNode;
    },
    
    /**
     * After a copy or move operation, many nodes may have to be reloaded
     * This function tries to reload them in the right order and if necessary.
     * @param nodes ManifestNodes[] An array of nodes
     */
    multipleNodesReload : function(nodes){
        nodes = $A(nodes);
        for(var i=0;i<nodes.length;i++){
            var nodePathOrNode = nodes[i];
            var node;
            if(Object.isString(nodePathOrNode)){
                node = new ManifestNode(nodePathOrNode);    
                if(node.getPath() == this._rootNode.getPath()) node = this._rootNode;
                else node = node.findInArbo(this._rootNode, []);
            }else{
                node = nodePathOrNode;
            }
            nodes[i] = node;        
        }
        var children = $A([]);
        nodes.sort(function(a,b){
            if(a.isParentOf(b)){
                children.push(b);
                return -1;
            }
            if(a.isChildOf(b)){
                children.push(a);
                return +1;
            }
            return 0;
        });
        children.each(function(c){
            nodes = nodes.without(c);
        });
        nodes.each(this.queueNodeReload.bind(this));
        this.nextNodeReloader();
    },
    
    /**
     * Add a node to the queue of nodes to reload.
     * @param node ManifestNode
     */
    queueNodeReload : function(node){
        if(!this.queue) this.queue = [];
        if(node){
            this.queue.push(node);
        }
    },
    
    /**
     * Queue processor for the nodes to reload
     */
    nextNodeReloader : function(){
        if(!this.queue.length) {
            window.setTimeout(function(){
                this.publish("context_changed", this._contextNode);
            }.bind(this), 200);
            return;
        }
        var next = this.queue.shift();
        var observer = this.nextNodeReloader.bind(this);
        next.observeOnce("loaded", observer);
        next.observeOnce("error", observer);
        if(next == this._contextNode || next.isParentOf(this._contextNode)){
            this.requireContextChange(next, true);
        }else{
            next.reload(this._iManifestNodeProvider);
        }
    },
    
    /**
     * Sets an array of nodes to be selected after the context is (re)loaded
     * @param selection ManifestNode[]
     */
    setPendingSelection : function(selection){
        this._pendingSelection = selection;
    },
    
    /**
     * Gets the array of nodes to be selected after the context is (re)loaded
     * @returns ManifestNode[]
     */
    getPendingSelection : function(){
        return this._pendingSelection;
    },
    
    /**
     * Clears the nodes to be selected
     */
    clearPendingSelection : function(){
        this._pendingSelection = null;
    },
    
    /**
     * Set an array of nodes as the current selection
     * @param dataNodes ManifestNode[] The nodes to select
     * @param source String The source of this selection action
     */
    setSelectedNodes : function(dataNodes, source){
        if(!source){
            this._selectionSource = {};
        }else{
            this._selectionSource = source;
        }
        dataNodes = $A(dataNodes).without(this._rootNode);
        this._selectedNodes = $A(dataNodes);
        this._bEmpty = ((dataNodes && dataNodes.length)?false:true);
        this._bFile = this._bDir = this._isRecycle = false;
        if(!this._bEmpty)
        {
            this._bUnique = ((dataNodes.length == 1)?true:false);
            for(var i=0; i<dataNodes.length; i++)
            {
                var selectedNode = dataNodes[i];
                if(selectedNode.isLeaf()) this._bFile = true;
                else this._bDir = true;
                if(selectedNode.isRecycle()) this._isRecycle = true;
            }
        }
        this.publish("selection_changed", this);
    },
    
    /**
     * Gets the currently selected nodes
     * @returns ManifestNode[]
     */
    getSelectedNodes : function(){
        return this._selectedNodes;
    },
    
    /**
     * Gets the source of the last selection action
     * @returns String
     */
    getSelectionSource : function(){
        return this._selectionSource;
    },

    /**
     * Manually sets the source of the selection
     * @param object
     */
    setSelectionSource : function(object){
        this._selectionSource = object;
    },

    /**
     * DEPRECATED
     */
    getSelectedItems : function(){
        throw new Error("Deprecated : use getSelectedNodes() instead");
    },
    
    /**
     * Select all the children of the current context node
     */
    selectAll : function(){
        this.setSelectedNodes(this._contextNode.getChildren(), "dataModel");
    },
    
    /**
     * Whether the selection is empty
     * @returns Boolean
     */
    isEmpty : function (){
        return (this._selectedNodes?(this._selectedNodes.length==0):true);
    },

    hasReadOnly : function(){
        var test = false;
        this._selectedNodes.each(function(node){
            if(node.hasMetadataInBranch("readonly", "true")) {
                test = true;
                throw $break;
            }
        });
        return test;
    },

    /**
     * Whether the selection is unique
     * @returns Boolean
     */
    isUnique : function (){
        return this._bUnique;
    },
    
    /**
     * Whether the selection has a file selected.
     * Should be hasLeaf
     * @returns Boolean
     */
    hasFile : function (){
        return this._bFile;
    },
    
    /**
     * Whether the selection has a dir selected
     * @returns Boolean
     */
    hasDir : function (){
        return this._bDir;
    },
            
    /**
     * Whether the current context is the recycle bin
     * @returns Boolean
     */
    isRecycle : function (){
        return this._isRecycle;
    },
    
    /**
     * DEPRECATED. Should use getCurrentNode().getPath() instead.
     * @returns String
     */
    getCurrentRep : function (){
        return this._currentRep;
    },
    
    /**
     * Whether the selection has more than one node selected
     * @returns Boolean
     */
    isMultiple : function(){
        if(this._selectedNodes && this._selectedNodes.length > 1) return true;
        return false;
    },
    
    /**
     * Whether the selection has a file with one of the mimes
     * @param mimeTypes Array Array of mime types
     * @returns Boolean
     */
    hasMime : function(mimeTypes){
        if(mimeTypes.length==1 && mimeTypes[0] == "*") return true;
        var has = false;
        mimeTypes.each(function(mime){
            if(has) return;
            has = this._selectedNodes.any(function(node){
                return (getMimeType(node) == mime);
            });
        }.bind(this) );
        return has;
    },
    
    /**
     * Get all selected filenames as an array.
     * @param separator String Is a separator, will return a string joined
     * @returns Array|String
     */
    getFileNames : function(separator){
        if(!this._selectedNodes.length)
        {
            alert('Please select a file!');
            return false;
        }
        var tmp = new Array(this._selectedNodes.length);
        for(i=0;i<this._selectedNodes.length;i++)
        {
            tmp[i] = this._selectedNodes[i].getPath();
        }
        if(separator){
            return tmp.join(separator);
        }else{
            return tmp;
        }
    },
    
    /**
     * Get all the filenames of the current context node children
     * @param separator String If passed, will join the array as a string
     * @return Array|String
     */
    getContextFileNames : function(separator){
        var allItems = this._contextNode.getChildren();
        if(!allItems.length)
        {       
            return false;
        }
        var names = $A([]);
        for(i=0;i<allItems.length;i++)
        {
            names.push(getBaseName(allItems[i].getPath()));
        }
        if(separator){
            return names.join(separator);
        }else{
            return names;
        }
    },
    
    /**
     * Whether the context node has a child with this basename
     * @param newFileName String The name to check
     * @returns Boolean
     */
    fileNameExists: function(newFileName, local)
    {
        if(local){
            var test = this._contextNode.getPath() + "/" + newFileName;
            return this._contextNode.getChildren().detect(function(c){
                return c.getPath() == test;
            });
        }else{
            var nodeExists = false;
            this.loadPathInfoSync(this._contextNode.getPath() + "/" + newFileName, function(foundNode){
                nodeExists = true;
            });
            return nodeExists;
        }

    },

    applyCheckHook : function(node){
        "use strict";
        var conn = new Connexion();
        conn.setParameters(new Hash({
            get_action : "apply_check_hook",
            file       : node.getPath(),
            hook_name  : "before_create",
            hook_arg   : node.getMetadata().get("filesize") || -1
        }));
        var result;
        conn.onComplete = function(transport){
            result = app.actionBar.parseXmlMessage(transport.responseXML);
        };
        conn.sendSync();
        if(result === false){
            throw new Error("Check failed" + error);
        }
    },
    
    /**
     * Gets the first name of the current selection
     * @returns String
     */
    getUniqueFileName : function(){ 
        if(this.getFileNames().length) return this.getFileNames()[0];
        return null;    
    },
    
    /**
     * Gets the first node of the selection, or Null
     * @returns ManifestNode
     */
    getUniqueNode : function(){
        if(this._selectedNodes.length){
            return this._selectedNodes[0];
        }
        return null;
    },
    
    /**
     * DEPRECATED
     */
    getUniqueItem : function(){
        throw new Error("getUniqueItem is deprecated, use getUniqueNode instead!");
    },

    /**
     * DEPRECATED
     */
    getItem : function(i) {
        throw new Error("getItem is deprecated, use getNode instead!");
    },
    
    /**
     * Gets a node from the current selection
     * @param i Integer the node index
     * @returns ManifestNode
     */
    getNode : function(i) {
        return this._selectedNodes[i];
    },
    
    /**
     * Will add the current selection nodes as serializable data to the element passed : 
     * either as hidden input elements if it's a form, or as query parameters if it's an url
     * @param oFormElement HTMLForm The form
     * @param sUrl String An url to complete
     * @returns String
     */
    updateFormOrUrl : function (oFormElement, sUrl){
        // CLEAR FROM PREVIOUS ACTIONS!
        if(oFormElement)    
        {
            $(oFormElement).getElementsBySelector("input").each(function(element){
                if(element.name.indexOf("file_") != -1 || element.name=="file") element.value = "";
            });
        }
        // UPDATE THE 'DIR' FIELDS
        if(oFormElement && oFormElement.rep) oFormElement.rep.value = this._currentRep;
        sUrl += '&dir='+encodeURIComponent(this._currentRep);
        
        // UPDATE THE 'file' FIELDS
        if(this.isEmpty()) return sUrl;
        var fileNames = this.getFileNames();
        if(this.isUnique())
        {
            sUrl += '&'+'file='+encodeURIComponent(fileNames[0]);
            if(oFormElement) this._addHiddenField(oFormElement, 'file', fileNames[0]);
        }
        else
        {
            for(var i=0;i<fileNames.length;i++)
            {
                sUrl += '&'+'file_'+i+'='+encodeURIComponent(fileNames[i]);
                if(oFormElement) this._addHiddenField(oFormElement, 'file_'+i, fileNames[i]);
            }
        }
        return sUrl;
    },
    
    _addHiddenField : function(oFormElement, sFieldName, sFieldValue){
        if(oFormElement[sFieldName]) oFormElement[sFieldName].value = sFieldValue;
        else{
            var field = document.createElement('input');
            field.type = 'hidden';
            field.name = sFieldName;
            field.value = sFieldValue;
            oFormElement.appendChild(field);
        }
    }
});
