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
 * Singleton class that manages all actions. Can be called directly using app.actionBar.
 */
Class.create("ActionsManager", {
    
    /**
     * Standard constructor
     * @param bUsersEnabled Boolen Whether users management is enabled or not
     */
    initialize: function(bUsersEnabled)
    {
        this._registeredKeys = new Hash();
        this._actions = new Hash();
        this.usersEnabled = bUsersEnabled;
        
        this.bgManager = new BackgroundManager(this);       
        this.subMenus = [];             
        this.actions = new Hash();
        this.defaultActions = new Hash();
        this.toolbars = new Hash();     
        document.observe("app:context_changed", function(event){
            window.setTimeout(function(){
                this.fireContextChange();
            }.bind(this), 0);           
        }.bind(this) );
        
        document.observe("app:selection_changed", function(event){
            window.setTimeout(function(){
                this.fireSelectionChange();
            }.bind(this), 0);
        }.bind(this) );
        
        document.observe("app:user_logged", function(event){
            if(event.memo && event.memo.getPreference){
                this.setUser(event.memo);
            }else{
                this.setUser(null);
            }
        }.bind(this));
        
    },  
    
    /**
     * Stores the currently logged user object
     * @param oUser User User instance
     */
    setUser: function(oUser)
    {   
        this.oUser = oUser;
        if(oUser != null && app  && oUser.id != 'guest' && oUser.getPreference('lang') != null 
            && oUser.getPreference('lang') != "" 
            && oUser.getPreference('lang') != app.currentLanguage
            && !oUser.lock
            )
        {
            app.loadI18NMessages(oUser.getPreference('lang'));
        }
    },
            
    /**
     * Filter the actions given the srcElement passed as arguments. 
     * @param srcElement String An identifier among selectionContext, genericContext, a webfx object id
     * @param ignoreGroups Array a list of groups to ignore
     * @returns Array
     */
    getContextActions: function(srcElement, ignoreGroups)
    {       
        var actionsSelectorAtt = 'selectionContext';
        if(srcElement.id && (srcElement.hasClassName('table_rows_container') ||  srcElement.hasClassName('selectable_div')))
        {
            actionsSelectorAtt = 'genericContext';
        }
        //else if(srcElement.id.substring(0,5)=='webfx')
        //{
        //  actionsSelectorAtt = 'directoryContext';
        //}
        var contextActions = new Array();
        var defaultGroup;
        var contextActionsGroup = {};
        this.actions.each(function(pair){
            var action = pair.value;
            if(!action.context.contextMenu) return;
            if(actionsSelectorAtt == 'selectionContext' && !action.context.selection) return;
            if(actionsSelectorAtt == 'directoryContext' && !action.context.dir) return;
            if(actionsSelectorAtt == 'genericContext' && action.context.selection) return;
            if(action.contextHidden || action.deny) return;
            $A(action.context.actionBarGroup.split(',')).each(function(barGroup){
                if(!contextActionsGroup[barGroup]){
                    contextActionsGroup[barGroup] = $A();
                }
            });
            var isDefault = false;
            if(actionsSelectorAtt == 'selectionContext'){
                // set default in bold
                var userSelection = app.getUserSelection();
                if(!userSelection.isEmpty()){
                    var defaultAction = 'file';
                    if(userSelection.isUnique() && (userSelection.hasDir() || userSelection.hasMime(['browsable_archive']))){
                        defaultAction = 'dir';
                    }
                    if(this.defaultActions.get(defaultAction) && action.options.name == this.defaultActions.get(defaultAction)){
                        isDefault = true;
                    }
                }
            }
            $A(action.context.actionBarGroup.split(',')).each(function(barGroup){
                var menuItem = {
                name:action.getKeyedText(),
                alt:action.options.title,
                action_id:action.options.name,
                image:resolveImageSource(action.options.src, '/images/actions/ICON_SIZE', 16),
                isDefault:isDefault,
                callback:function(e){this.apply();}.bind(action)
                };
                if(action.options.icon_class){
                    menuItem.icon_class = action.options.icon_class;
                }
                if(action.options.subMenu){
                    menuItem.subMenu = [];
                    if(action.subMenuItems.staticOptions){
                        menuItem.subMenu = action.subMenuItems.staticOptions;
                    }
                    if(action.subMenuItems.dynamicBuilder){
                        menuItem.subMenuBeforeShow = action.subMenuItems.dynamicBuilder;
                    }
                }
                //contextActions.push(menuItem);
                contextActionsGroup[barGroup].push(menuItem);
                if(isDefault){
                    defaultGroup = barGroup;
                }
            });
        }.bind(this));
        var first = true;
        contextActionsGroup = $H(contextActionsGroup);
        contextActionsGroup = contextActionsGroup.sortBy(function(p){
            if(defaultGroup && p.key == defaultGroup) return 'aaaa';
            return p.key;
        });
        contextActionsGroup.each(function(pair){
            if(!first){
                contextActions.push({separator:true});
            }
            if(ignoreGroups && ignoreGroups.indexOf(pair.key) != -1){
                return;
            }
            first = false;
            pair.value.each(function(mItem){
                contextActions.push(mItem);
            });
        });
        return contextActions;
    },
    
    /**
     * DEPRECATED, use getActionsForAppWidget instead!
     * @returns $A()
     */
    getInfoPanelActions:function(){
        var actions = $A([]);
        this.actions.each(function(pair){
            var action = pair.value;
            if(action.context.infoPanel && !action.deny) actions.push(action);
        });
        return actions;
    },
    
    /**
     * Generic method to get actions for a given component part.
     * @param appClassName String 
     * @param widgetId String
     * @returns $A()
     */
    getActionsForAppWidget:function(appClassName, widgetId){
        var actions = $A([]);
        this.actions.each(function(pair){
            var action = pair.value;
            if(action.context.appWidgets && (action.context.appWidgets.include(appClassName+'::'+widgetId)||action.context.appWidgets.include(appClassName)) && !action.deny) actions.push(action);
        });
        return actions;     
    },
    
    /**
     * Finds a default action and fires it.
     * @param defaultName String ("file", "dir", "dragndrop", "ctrldragndrop")
     */
    fireDefaultAction: function(defaultName){
        var actionName = this.defaultActions.get(defaultName); 
        if(actionName != null){
            arguments[0] = actionName;
            if(actionName == "ls"){
                var action = this.actions.get(actionName);
                if(action) action.enable(); // Force enable on default action
            }
            this.fireAction.apply(this, arguments);
        }
    },
    
    /**
     * Fire an action based on its name
     * @param buttonAction String The name of the action
     */
    fireAction: function (buttonAction) {       
        var action = this.actions.get(buttonAction);
        if(action != null) {
            var args = $A(arguments);
            args.shift();
            action.apply(args);
            return;
        }
    },
    
    /**
     * Registers an accesskey for a given action. 
     * @param key String The access key
     * @param actionName String The name of the action
     * @param optionnalCommand String An optionnal argument 
     * that will be passed to the action when fired.
     */
    registerKey: function(key, actionName, optionnalCommand){       
        if(optionnalCommand){
            actionName = actionName + "::" + optionnalCommand;
        }
        this._registeredKeys.set(key.toLowerCase(), actionName);
    },
    
    /**
     * Remove all registered keys.
     */
    clearRegisteredKeys: function(){
        this._registeredKeys = new Hash();
    },
    /**
     * Triggers an action by its access key.
     * @param event Event The key event (will be stopped)
     * @param keyName String A key name
     */
    fireActionByKey: function(event, keyName)
    {   
        if(this._registeredKeys.get(keyName) && !app.blockShortcuts)
        {
            if(this._registeredKeys.get(keyName).indexOf("::")!==false){
                var parts = this._registeredKeys.get(keyName).split("::");
                this.fireAction(parts[0], parts[1]);
            }else{
                this.fireAction(this._registeredKeys.get(keyName));
            }
            Event.stop(event);
        }
        return;
    },
    
    /**
     * Complex function called when drag'n'dropping. Basic checks of who is child of who.
     * @param fileName String The dragged element 
     * @param destDir String The drop target node path
     * @param destNodeName String The drop target node name
     * @param copy Boolean Copy or Move
     */
    applyDragMove: function(fileName, destDir, destNodeName, copy)
    {
        if((!copy && (!this.defaultActions.get('dragndrop') || this.getDefaultAction('dragndrop').deny)) ||
            (copy && (!this.defaultActions.get('ctrldragndrop')||this.getDefaultAction('ctrldragndrop').deny))){
            return;
        }
        if(fileName == null) fileNames = app.getUserSelection().getFileNames();
        else fileNames = [fileName];
        if(destNodeName != null)
        {
            // Check that dest is not a child of the source
            if(this.checkDestIsChildOfSource(fileNames, destNodeName)){
                app.displayMessage('ERROR', MessageHash[202]);
                return;
            }
        }
        // Check that dest is not the source it self
        for(var i=0; i<fileNames.length;i++)
        {
            if(fileNames[i] == destDir){
                if(destNodeName != null) app.displayMessage('ERROR', MessageHash[202]);
                 return;
            }
        }
        // Check that dest is not the direct parent of source, ie current rep!
        if(destDir == app.getContextNode().getPath()){
            if(destNodeName != null) app.displayMessage('ERROR', MessageHash[203]);
            return;
        }
        var connexion = new Connexion();
        if(copy){
            connexion.addParameter('get_action', this.defaultActions.get('ctrldragndrop'));
        }else{
            connexion.addParameter('get_action', this.defaultActions.get('dragndrop'));
        }
        if(fileName != null){
            connexion.addParameter('file', fileName);
        }else{
            for(var i=0; i<fileNames.length;i++){
                connexion.addParameter('file_'+i, fileNames[i]);
            }
        }
        connexion.addParameter('dest', destDir);
        connexion.addParameter('dir', app.getContextNode().getPath());      
        connexion.onComplete = function(transport){this.parseXmlMessage(transport.responseXML);}.bind(this);
        connexion.sendAsync();
    },
    
    /**
     * Get the action defined as default for a given default string
     * @param defaultName String
     * @returns Action
     */
    getDefaultAction : function(defaultName){
        if(this.defaultActions.get(defaultName)){
            return this.actions.get(this.defaultActions.get(defaultName));
        }
        return null;
    },
    
    /**
     * Detects whether a destination is child of the source 
     * @param srcNames String|Array One or many sources pathes
     * @param destNodeName String the destination
     * @returns Boolean
     */
    checkDestIsChildOfSource: function(srcNames, destNodeName)
    {
        if(typeof srcNames == "string"){
            srcNames = [srcNames];
        }
        var destNode = webFXTreeHandler.all[destNodeName];
        while(destNode.parentNode){
            for(var i=0; i<srcNames.length;i++){
                if(destNode.filename == srcNames[i]){               
                    return true;
                }
            }
            destNode = destNode.parentNode;
        }
        return false;
    },
        
    /**
     * Submits a form using Connexion class.
     * @param formName String The id of the form
     * @param post Boolean Whether to POST or GET
     * @param completeCallback Function Callback to be called on complete
     */
    submitForm: function(formName, post, completeCallback)
    {
        var connexion = new Connexion();
        if(post){
            connexion.setMethod('POST');
        }
        $(formName).getElements().each(function(fElement){
            var fValue = fElement.getValue();
            if(fElement.name == 'get_action' && fValue.substr(0,4) == 'http'){          
                fValue = getBaseName(fValue);
            }
            if(fElement.type == 'radio' && !fElement.checked) return;
            connexion.addParameter(fElement.name, fValue);
        });
        if(app.getContextNode()){
            connexion.addParameter('dir', app.getContextNode().getPath());
        }
        if(completeCallback){
            connexion.onComplete = completeCallback;
        }else{
            connexion.onComplete = function(transport){this.parseXmlMessage(transport.responseXML);}.bind(this) ;
        }
        connexion.sendAsync();
    },
    
    /**
     * Standard parser for server XML answers
     * @param xmlResponse DOMDocument 
     */
    parseXmlMessage: function(xmlResponse)
    {
        var messageBox = app.messageBox;
        if(xmlResponse == null || xmlResponse.documentElement == null) return;
        var childs = xmlResponse.documentElement.childNodes;    
        
        var reloadNodes = [];
        var error = false;
        
        for(var i=0; i<childs.length;i++)
        {
            if(childs[i].tagName == "message")
            {
                var messageTxt = "No message";
                if(childs[i].firstChild) messageTxt = childs[i].firstChild.nodeValue;
                app.displayMessage(childs[i].getAttribute('type'), messageTxt);
                if(childs[i].getAttribute('type') == 'ERROR') error = true;
            }
            else if(childs[i].tagName == "reload_instruction")
            {
                var obName = childs[i].getAttribute('object');
                if(obName == 'data')
                {
                    var node = childs[i].getAttribute('node');              
                    if(node){
                        reloadNodes.push(node);
                    }else{
                        var file = childs[i].getAttribute('file');
                        if(file){
                            app.getContextHolder().setPendingSelection(file);
                        }
                        reloadNodes.push(app.getContextNode());
                    }
                }
                else if(obName == 'repository_list')
                {
                    app.reloadRepositoriesList();
                }
            }
            else if(childs[i].nodeName == 'nodes_diff'){
                var dm = app.getContextHolder();
                var removes = XPathSelectNodes(childs[i], "remove/tree");
                var adds = XPathSelectNodes(childs[i], "add/tree");
                var updates = XPathSelectNodes(childs[i], "update/tree");
                if(removes && removes.length){
                    removes.each(function(r){
                        var p = r.getAttribute("filename");
                        var fake = new ManifestNode(p);
                        var n = fake.findInArbo(dm.getRootNode(), undefined);
                        if(n){
                            n.getParent().removeChild(n);
                        }
                    });
                }
                if(adds && adds.length && dm.getManifestNodeProvider().parseManifestNode){
                    adds.each(function(tree){
                        var newNode = dm.getManifestNodeProvider().parseManifestNode(tree);
                        var parentFake = new ManifestNode(getRepName(newNode.getPath()));
                        var parent = parentFake.findInArbo(dm.getRootNode(), undefined);
                        if(!parent && getRepName(newNode.getPath()) == "") parent = dm.getRootNode();
                        if(parent){
                            parent.addChild(newNode);
                            dm.setSelectedNodes([newNode], {});
                        }
                    });
                }
                if(updates && updates.length && dm.getManifestNodeProvider().parseManifestNode){
                    updates.each(function(tree){
                        var newNode = dm.getManifestNodeProvider().parseManifestNode(tree);
                        var original = newNode.getMetadata().get("original_path");
                        var newPath = newNode.getPath();
                        var newRepName = getRepName(newPath);
                        if(original && original != newPath
                            && getRepName(original) != newRepName){
                            // Node was really moved to another folder
                            var fake = new ManifestNode(original);
                            var n = fake.findInArbo(dm.getRootNode(), undefined);
                            if(n){
                                n.getParent().removeChild(n);
                            }
                            var parentFake = new ManifestNode(newRepName);
                            var parent = parentFake.findInArbo(dm.getRootNode(), undefined);
                            if(!parent && newRepName == "") parent = dm.getRootNode();
                            if(parent){
                                newNode.getMetadata().set("original_path", undefined);
                                parent.addChild(newNode);
                            }
                        }else{
                            var fake = new ManifestNode(original);
                            var n = fake.findInArbo(dm.getRootNode(), undefined);
                            if(n){
                                newNode._isLoaded = n._isLoaded;
                                n.replaceBy(newNode, "override");
                                dm.setSelectedNodes([n], {});
                            }
                        }
                        if (original && original != newPath){
                            document.fire('app:file_renamed', { old: original, new: newPath });
                        }
                    });
                }
            }
            else if(childs[i].tagName == "logging_result")
            {
                if(childs[i].getAttribute("secure_token")){
                    Connexion.SECURE_TOKEN = childs[i].getAttribute("secure_token");
                    var parts = window.appServerAccessPath.split("?secure_token");
                    window.appServerAccessPath = parts[0] + "?secure_token=" + Connexion.SECURE_TOKEN;
                    window._bootstrap.parameters.set('appServerAccess', window.appServerAccessPath);
                }
                if($("generic_dialog_box") && $("generic_dialog_box").down(".login_error")){
                    $("generic_dialog_box").down(".login_error").remove();
                }
                var result = childs[i].getAttribute('value');
                var errorId = false;
                if(result == '1')
                {
                    modal.setCloseValidation(null);
                    hideLightBox(true);
                    if(childs[i].getAttribute('remember_login') && childs[i].getAttribute('remember_pass')){
                        var login = childs[i].getAttribute('remember_login');
                        var pass = childs[i].getAttribute('remember_pass');
                        var remExp = parseInt(childs[i].getAttribute('remember_expires'), 10);
                        storeRememberData(login, pass, remExp > 0 ? remExp : undefined);
                    }
                    app.loadXmlRegistry();
                }
                else if(result == '0' || result == '-1'){
                    var errorId = 285;
                }
                else if(result == '2'){
                    app.loadXmlRegistry();
                }
                else if(result == '-2'){
                    var errorId = 285;
                }
                else if(result == '-3'){
                    var errorId = 366;
                }
                else if(result == '-4'){
                    var errorId = 386;
                }
                else if(result == '-5'){
                    clearRememberData();
                }
                else if(result == '-6'){
                    var errorId = 493;
                }
                
                if(errorId){
                    error = true;
                    if($("generic_dialog_box") && $("generic_dialog_box").visible() && $("generic_dialog_box").down("div.dialogLegend")){
                        $("generic_dialog_box").down("div.dialogLegend").insert({bottom:'<div class="login_error" style="background-color: #D33131;display: block;font-size: 9px;color: white;border-radius: 3px;padding: 2px 6px;">'+MessageHash[errorId]+'</div>'});
                        $("generic_dialog_box").shake();
                    }else{
                        alert(MessageHash[errorId]);
                    }
                }

            }else if(childs[i].tagName == "trigger_bg_action"){
                var name = childs[i].getAttribute("name");
                var messageId = childs[i].getAttribute("messageId");
                var parameters = new Hash();
                for(var j=0;j<childs[i].childNodes.length;j++){
                    var paramChild = childs[i].childNodes[j];
                    if(paramChild.tagName == 'param'){
                        parameters.set(paramChild.getAttribute("name"), paramChild.getAttribute("value"));
                    }
                }
                this.bgManager.queueAction(name, parameters, messageId);
                this.bgManager.next();
            }

        }
        if(reloadNodes.length){
            app.getContextHolder().multipleNodesReload(reloadNodes);
        }
        return !error;
    },
    
    /**
     * Spreads a selection change to all actions and to registered components 
     * by triggering app:actions_refreshed event.
     */
    fireSelectionChange: function(){
        var userSelection = null;
        if (app && app.getUserSelection()){
            userSelection = app.getUserSelection();
            if(userSelection.isEmpty()) userSelection = null;
        } 
        this.actions.each(function(pair){
            pair.value.fireSelectionChange(userSelection);
        });     
        document.fire("app:actions_refreshed");
    },
    
    /**
     * Spreads a context change to all actions and to registered components 
     * by triggering app:actions_refreshed event.
     */
    fireContextChange: function(){
        var crtNode;
        if(app && app.getContextNode()){ 
            var crtNode = app.getContextNode();
        }
        this.actions.each(function(pair){
        pair.value.fireContextChange(this.usersEnabled,
         this.oUser,
         crtNode);
        }.bind(this));
        document.fire("app:actions_refreshed");
    },
            
    /**
     * Remove all actions
     */
    removeActions: function(){
        this.actions.each(function(pair){
            pair.value.remove();
        });
        this.actions = new Hash();
        this.clearRegisteredKeys();
    },
    
    /**
     * Create actions from XML Registry
     * @param registry DOMDocument
     */
    loadActionsFromRegistry : function(registry){
        this.removeActions();       
        this.parseActions(registry);
        if(app && app.guiActions){
            app.guiActions.each(function(pair){
                var act = pair.value;
                this.registerAction(act);
            }.bind(this));
        }
        document.fire("app:actions_loaded", this.actions);
        this.fireContextChange();
        this.fireSelectionChange();
    },
    
    /**
     * Registers an action to this manager (default, accesskey).
     * @param action Action
     */
    registerAction : function(action){
        var actionName = action.options.name;
        this.actions.set(actionName, action);
        if(action.defaults){
            for(var key in action.defaults) this.defaultActions.set(key, actionName);
        }
        if(action.options.hasAccessKey){
            this.registerKey(action.options.accessKey, actionName);
        }
        if(action.options.specialAccessKey){
            this.registerKey("key_" + action.options.specialAccessKey, actionName);
        }
        action.setManager(this);
    },
    
    /**
     * Parse an XML action node and registers the action
     * @param documentElement DOMNode The node to parse
     */
    parseActions: function(documentElement){        
        actions = XPathSelectNodes(documentElement, "actions/action");
        for(var i=0;i<actions.length;i++){
            if(actions[i].nodeName != 'action') continue;
      if(actions[i].getAttribute('enabled') == 'false') continue;
            var newAction = new Action();
            newAction.createFromXML(actions[i]);
            this.registerAction(newAction);
        }
    },
    /**
     * Find an action by its name
     * @param actionName String
     * @returns Action
     */
    getActionByName : function(actionName){
        return this.actions.get(actionName);        
    },
    
    /**
     * Utilitary to get FlashVersion, should probably be removed from here!
     * @returns String
     */
    getFlashVersion: function()
    {
        if (!this.pluginVersion) {
            var x;
            if(navigator.plugins && navigator.mimeTypes.length){
                x = navigator.plugins["Shockwave Flash"];
                if(x && x.description) x = x.description;
            } else if (Prototype.Browser.IE){
                try {
                    x = new ActiveXObject("ShockwaveFlash.ShockwaveFlash");
                    x = x.GetVariable("$version");
                } catch(e){}
            }
            this.pluginVersion = (typeof(x) == 'string') ? parseInt(x.match(/\d+/)[0]) : 0;
        }
        return this.pluginVersion;
    }
});