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
 * This is the main JavaScript class instantiated by AppBoostrap at startup.
 */
Class.create("App", {

    blockEditorShortcuts : false,
    blockShortcuts : false,
    blockNavigation : false,

	/**
	 * Constructor.
	 * @param loadRep String A base folder to load after initialization is complete
	 * @param usersEnabled Boolean Whether users management is enabled or not
	 * @param loggedUser String Already logged user. 
	 */
	initialize: function(loadRep, usersEnabled, loggedUser)
	{	
		this._initLoadRep = loadRep;
		this._initObj = true ;
		this.usersEnabled = usersEnabled;
		this._initLoggedUser = loggedUser;
		this._contextHolder = new DataModel();
		this._contextHolder.setManifestNodeProvider(new RemoteNodeProvider());
		this._focusables = [];
		this._registry = null;
		this._resourcesRegistry = {};
		this._initDefaultDisp = 'list';
		this.histCount=0;
		this._guiComponentsConfigs = new Hash();
		this.appTitle = window._bootstrap.parameters.get("customWording").title || "System";
	},
	
	/**
	 * Real initialisation sequence. Will Trigger the whole GUI building.
	 * Event app:loaded is fired at the end.
	 */
	init:function(){
		document.observe("app:registry_loaded", function(){
			this.refreshExtensionsRegistry();
			this.logXmlUser(this._registry);
            if(this.user){
                var repId = this.user.getActiveRepository();
                var repList = this.user.getRepositoriesList();
                var repositoryObject = repList.get(repId);
                if(repositoryObject) repositoryObject.loadResources();
            }
			if(this.guiLoaded) {
				this.refreshTemplateParts();
				this.refreshGuiComponentConfigs();
			} else {
				document.observe("app:gui_loaded", function(){
					this.refreshTemplateParts();
					this.refreshGuiComponentConfigs();
				}.bind(this));
			}
            this.loadActiveRepository();
		}.bind(this));

		modal.setLoadingStepCounts(5);
        if(window._bootstrap.parameters.get("PRELOADED_REGISTRY")){
            this._registry = parseXml(window._bootstrap.parameters.unset("PRELOADED_REGISTRY")).documentElement;
            modal.updateLoadingProgress('XML Registry loaded');
        }else{
            this.loadXmlRegistry(true);
        }
		this.initTemplates();
		modal.initForms();
		this.initObjects();
		window.setTimeout(function(){
			document.fire('app:loaded');
		}, 200);		
	},
	/**
	 * Loads the XML Registry, an image of the application in its current state
	 * sent by the server.
	 * @param sync Boolean Whether to send synchronously or not.
	 * @param xPath String An XPath to load only a subpart of the registry
	 */
	loadXmlRegistry : function(sync, xPath){
		var connexion = new Connexion();
		connexion.onComplete = function(transport){
			if(transport.responseXML == null || transport.responseXML.documentElement == null) return;
			if(transport.responseXML.documentElement.nodeName == "registry"){
				this._registry = transport.responseXML.documentElement;
				modal.updateLoadingProgress('XML Registry loaded');
				if(!sync) {
					//console.log('firing registry_loaded');
					document.fire("app:registry_loaded", this._registry);
				}
			}else if(transport.responseXML.documentElement.nodeName == "registry_part"){
				this.refreshXmlRegistryPart(transport.responseXML.documentElement);
			}
		}.bind(this);
		connexion.addParameter('get_action', 'get_xml_registry');
		if(xPath){
			connexion.addParameter('xPath', xPath);
		}
		if(sync){
			connexion.sendSync();		
		}else{
			connexion.sendAsync();
		}
	},

	/**
	 * Inserts a document fragment retrieved from server inside the full tree.
	 * The node must contains the xPath attribute to locate it inside the registry.
	 * Event app:registry_part_loaded is triggerd once this is done.
	 * @param documentElement DOMNode
	 */
	refreshXmlRegistryPart : function(documentElement){
		var xPath = documentElement.getAttribute("xPath");
		var existingNode = XPathSelectSingleNode(this._registry, xPath);
		if(existingNode && existingNode.parentNode){
			var parentNode = existingNode.parentNode;
			parentNode.removeChild(existingNode);
			if(documentElement.firstChild){
				parentNode.appendChild(documentElement.firstChild.cloneNode(true));
			}
		}else if(xPath.indexOf("/") > -1){
			// try selecting parentNode
			var parentPath = xPath.substring(0, xPath.lastIndexOf("/"));
			var parentNode = XPathSelectSingleNode(this._registry, parentPath);
			if(parentNode && documentElement.firstChild){
				//parentNode.ownerDocument.importNode(documentElement.firstChild);
				parentNode.appendChild(documentElement.firstChild.cloneNode(true));
			}			
		}else{
			if(documentElement.firstChild) this._registry.appendChild(documentElement.firstChild.cloneNode(true));
		}
		document.fire("app:registry_part_loaded", xPath);		
	},
	
	/**
	 * Initialize GUI Objects
	 */
	initObjects: function(){

		/*********************
		/* STANDARD MECHANISMS
		/*********************/
		this.contextMenu = new Proto.Menu({
		  selector: '', // context menu will be shown when element with class name of "contextmenu" is clicked
		  className: 'menu desktop', // this is a class which will be attached to menu container (used for css styling)
		  menuItems: [],
		  fade:false,
		  zIndex:2000
		});
		var protoMenu = this.contextMenu;		
		protoMenu.options.beforeShow = function(e){
			this.options.lastElement = Event.element(e);
			this.options.menuItems = app.actionBar.getContextActions(Event.element(e), ["inline"]);
			this.refreshList();
		}.bind(protoMenu);
		protoMenu.options.beforeHide = function(e){
			this.options.lastElement = null;
		}.bind(protoMenu);
		document.observe("app:actions_refreshed", function(){
			if(this.options.lastElement){
				this.options.menuItems = app.actionBar.getContextActions(this.options.lastElement, ["inline"]);
				this.refreshList();
			}			
		}.bind(protoMenu));
		
		this.actionBar = new ActionsManager(this.usersEnabled);
		if(this._registry){
			this.actionBar.loadActionsFromRegistry(this._registry);
		}
		document.observe("app:registry_loaded", function(event){
      if(Prototype.Browser.IE) ResourcesManager.prototype.loadAutoLoadResources(event.memo);
			this.actionBar.loadActionsFromRegistry(event.memo);
		}.bind(this) );
				
		if(!Prototype.Browser.WebKit && !Prototype.Browser.IE){
			this.history = new Proto.History(function(hash){
				this.goTo(this.historyHashToPath(hash));
			}.bind(this));
			document.observe("app:context_changed", function(event){
				this.updateHistory(this.getContextNode().getPath());
			}.bind(this));
		}else{
			document.observe("app:context_changed", function(event){
				var path = this.getContextNode().getPath();
				document.title = this.appTitle + ' - '+(getBaseName(path)?getBaseName(path):'/');
			}.bind(this));
		}
		document.observe("app:context_changed", function(event){
			if(this.skipLsHistory || !this.user || !this.user.getActiveRepository()) return;			
			window.setTimeout(function(){
				var data = this.user.getPreference("ls_history", true) || {};
				data = new Hash(data);
				data.set(this.user.getActiveRepository(), this.getContextNode().getPath());
				this.user.setPreference("ls_history", data, true);
				this.user.savePreference("ls_history");
			}.bind(this), 100 );
		}.bind(this) );
		modal.updateLoadingProgress('Actions Initialized');
		
		this.activityMonitor = new ActivityMonitor(
			window._bootstrap.parameters.get('session_timeout'), 
			window._bootstrap.parameters.get('client_timeout'), 
			window._bootstrap.parameters.get('client_timeout_warning'));
		  
		/*********************
		/* USER GUI
		/*********************/
		this.guiLoaded = false;
		this.buildGUI($(window._bootstrap.parameters.get('MAIN_ELEMENT')));
		document.fire("app:before_gui_load");
		// Rewind components creation!
		if(this.guiCompRegistry){
            this.initAppWidgets(this.guiCompRegistry);
		}
		this.guiLoaded = true;
		document.fire("app:gui_loaded");
		modal.updateLoadingProgress('GUI Initialized');
		this.initTabNavigation();
		this.blockShortcuts = false;
		this.blockNavigation = false;
		modal.updateLoadingProgress('Navigation loaded');
		

		this.tryLogUserFromCookie();
		document.fire("app:registry_loaded", this._registry);		
	},

    initAppWidgets : function(compRegistry){
        var lastInst;
        if(compRegistry.length){
            for(var i=compRegistry.length;i>0;i--){
                var el = compRegistry[i-1];
                var appId = el.appId;
                compRegistry[i-1] = new el['appClass'](el.node, el.appOptions);
                window[appId] = compRegistry[i-1];
                lastInst = compRegistry[i-1];
            }
            if(lastInst){
                lastInst.resize();
            }
            for(var j=0;j<compRegistry.length;j++){
                var obj = compRegistry[j];
                if(Class.objectImplements(obj, "IFocusable")){
                    obj.setFocusBehaviour();
                    this._focusables.push(obj);
                }
                if(Class.objectImplements(obj, "IContextMenuable")){
                    obj.setContextualMenu(this.contextMenu);
                }
                if(Class.objectImplements(obj, "IActionProvider")){
                    if(!this.guiActions) this.guiActions = new Hash();
                    this.guiActions.update(obj.getActions());
                }
            }
        }
    },

	/**
	 * Builds the GUI based on the XML definition (template)
	 * @param domNode
	 */
	buildGUI : function(domNode, compRegistry){
		if(domNode.nodeType != 1) return;
		if(!this.guiCompRegistry) this.guiCompRegistry = $A([]);
        if(!compRegistry){
            compRegistry = this.guiCompRegistry;
        }
		domNode = $(domNode);
		var appClassName = domNode.readAttribute("appClass") || "";
		var appClass = Class.getByName(appClassName);
		var appId = domNode.readAttribute("id") || "";
		var appOptions = {};
		if(domNode.readAttribute("appOptions")){
            try{
                appOptions = domNode.readAttribute("appOptions").evalJSON();
            }catch(e){
                alert("Error while parsing JSON for GUI template part " + appId + "!");
            }
		}
		if(appClass && appId && Class.objectImplements(appClass, "IAppWidget")){
			compRegistry.push({appId:appId, node:domNode, appClass:appClass, appOptions:appOptions});
		}		
		$A(domNode.childNodes).each(function(node){
			this.buildGUI(node, compRegistry);
		}.bind(this) );
	},
	
	/**
	 * Parses a client_configs/template_part node
	 */
	refreshTemplateParts : function(){
		var parts = XPathSelectNodes(this._registry, "client_configs/template_part");
		var toUpdate = {};
		if(!this.templatePartsToRestore){
			this.templatePartsToRestore = $A();
		}
		for(var i=0;i<parts.length;i++){
            if(parts[i].getAttribute("theme") && parts[i].getAttribute("theme") != window._bootstrap.parameters.get("theme")){
                continue;
            }
			var appId = parts[i].getAttribute("appId");
			var appClassName = parts[i].getAttribute("appClass");
			var appOptionsString = parts[i].getAttribute("appOptions");
            var cdataContent = "";
            if(parts[i].firstChild && parts[i].firstChild.nodeType == 4 && parts[i].firstChild.nodeValue != ""){
                cdataContent = parts[i].firstChild.nodeValue;
            }
			
			var appClass = Class.getByName(appClassName);
			if(appClass && appId && Class.objectImplements(appClass, "IAppWidget")){				
				toUpdate[appId] = [appClass, appClassName, appOptionsString, cdataContent];
				this.templatePartsToRestore = this.templatePartsToRestore.without(appId);
			}
		}
        var futurePartsToRestore = $A(Object.keys(toUpdate));
		this.templatePartsToRestore.each(function(key){
			var part = this.findOriginalTemplatePart(key);
            if(part){
                var appClassName = part.getAttribute("appClass");
                var appOptionsString = part.getAttribute("appOptions");
                var cdataContent = part.innerHTML;
                var appClass = Class.getByName(appClassName);
                toUpdate[key] = [appClass, appClassName, appOptionsString, cdataContent];
            }
		}.bind(this));
		
		for(var id in toUpdate){
			this.refreshGuiComponent(id, toUpdate[id][0], toUpdate[id][1], toUpdate[id][2], toUpdate[id][3]);
		}
		this.templatePartsToRestore = futurePartsToRestore;
	},
	
	/**
	 * Applies a template_part by removing existing components at this location
	 * and recreating new ones.
	 * @param appId String The id of the DOM anchor
	 * @param appClass IAppWidget A widget class
	 * @param appOptions Object A set of options that may have been decoded from json.
	 */
	refreshGuiComponent:function(appId, appClass, appClassName, appOptionsString, cdataContent){
		if(!window[appId]) return;
		// First destroy current component, unregister actions, etc.			
		var oldObj = window[appId];
		if(oldObj.__className == appClassName && oldObj.__appOptionsString == appOptionsString){
			return;
		}
		var appOptions = {};
		if(appOptionsString){
			appOptions = appOptionsString.evalJSON();			
		}
		if(Class.objectImplements(oldObj, "IFocusable")){
			this._focusables = this._focusables.without(oldObj);
		}
		if(Class.objectImplements(oldObj, "IActionProvider")){
			oldObj.getActions().each(function(act){
				this.guiActions.unset(act.key);// = this.guiActions.without(act);
			}.bind(this) );
		}
		if(oldObj.htmlElement) var anchor = oldObj.htmlElement;
		oldObj.destroy();

        if(cdataContent && anchor){
            anchor.insert(cdataContent);
            var compReg = $A();
            $A(anchor.children).each(function(el){
                this.buildGUI(el, compReg);
            }.bind(this));
            if(compReg.length) this.initAppWidgets(compReg);
        }
		var obj = new appClass($(appId), appOptions);
		if(Class.objectImplements(obj, "IFocusable")){
			obj.setFocusBehaviour();
			this._focusables.push(obj);
		}
		if(Class.objectImplements(obj, "IContextMenuable")){
			obj.setContextualMenu(this.contextMenu);
		}
		if(Class.objectImplements(obj, "IActionProvider")){
			if(!this.guiActions) this.guiActions = new Hash();
			this.guiActions.update(obj.getActions());
		}
        if($(appId).parentNode && $(appId).parentNode.paneObject && $(appId).parentNode.paneObject.scanChildrenPanes){
            $(appId).parentNode.paneObject.scanChildrenPanes($(appId).parentNode.paneObject.htmlElement);
        }

            obj.__appOptionsString = appOptionsString;
		
		window[appId] = obj;
		obj.resize();
		delete(oldObj);
	},
	
	/**
	 * Spreads a client_configs/component_config to all gui components.
	 * It will be the mission of each component to check whether its for him or not.
	 */
	refreshGuiComponentConfigs : function(){
        this._guiComponentsConfigs = $H();
		var nodes = XPathSelectNodes(this._registry, "client_configs/component_config");
		if(!nodes.length) return;
		for(var i=0;i<nodes.length;i++){
			this.setGuiComponentConfig(nodes[i]);
		}
	},
	
	/**
	 * Apply the componentConfig to the AppObject of a node
	 * @param domNode IAppWidget
	 */
	setGuiComponentConfig : function(domNode){
		var className = domNode.getAttribute("className");
		var classId = domNode.getAttribute("classId") || null;
		var classConfig = new Hash();
		if(classId){
			classConfig.set(classId, domNode);
		}else{
			classConfig.set('all', domNode);
		}
        var cumul = this._guiComponentsConfigs.get(className);
        if(!cumul) cumul = $A();
		cumul.push(classConfig);
        this._guiComponentsConfigs.set(className, cumul);
		document.fire("app:component_config_changed", {className:className, classConfig:classConfig});
	},

    getGuiComponentConfigs : function(className){
        return this._guiComponentsConfigs.get(className);
    },

	/**
	 * Try reading the cookie and sending it to the server
	 */
	tryLogUserFromCookie : function(){
		var connexion = new Connexion();
		var rememberData = retrieveRememberData();
		if(rememberData!=null){
			connexion.addParameter('get_action', 'login');
			connexion.addParameter('userid', rememberData.user);
			connexion.addParameter('password', rememberData.pass);
			connexion.addParameter('cookie_login', 'true');
			connexion.onComplete = function(transport){
                hideLightBox();
                this.actionBar.parseXmlMessage(transport.responseXML);
            }.bind(this);
			connexion.sendSync();
		}
	},
			
	/**
	 * Translate the XML answer to a new User object
	 * @param documentElement DOMNode The user fragment
	 * @param skipEvent Boolean Whether to skip the sending of app:user_logged event.
	 */
	logXmlUser: function(documentElement, skipEvent){
		this.user = null;
		var userNode = XPathSelectSingleNode(documentElement, "user");
		if(userNode){
			var userId = userNode.getAttribute('id');
			var children = userNode.childNodes;
			if(userId){ 
				this.user = new User(userId, children);
			}
		}
		if(!skipEvent){
			document.fire("app:user_logged", this.user);
		}
	},
		
	
	/**
	 * Find the current repository (from the current user) and load it. 
	 */
	loadActiveRepository : function(){
		var repositoryObject = new Repository(null);
		if(this.user != null)
		{
            var repId = this.user.getActiveRepository();
			var repList = this.user.getRepositoriesList();			
			repositoryObject = repList.get(repId);
			if(!repositoryObject){
                if(this.user.lock){
                    this.actionBar.loadActionsFromRegistry(this._registry);
                    window.setTimeout(function(){
                        this.actionBar.fireAction(this.user.lock);
                    }.bind(this), 50);
                    return;
                }
                alert("No active repository found for user!");
			}
			if(this.user.getPreference("pending_folder") && this.user.getPreference("pending_folder") != "-1"){
				this._initLoadRep = this.user.getPreference("pending_folder");
				this.user.setPreference("pending_folder", "-1");
				this.user.savePreference("pending_folder");
			}else if(this.user.getPreference("ls_history", true)){
				var data = new Hash(this.user.getPreference("ls_history", true));
				this._initLoadRep = data.get(repId);
			}
		}
		this.loadRepository(repositoryObject);		
		if(repList && repId){
			document.fire("app:repository_list_refreshed", {list:repList,active:repId});
		}else{
			document.fire("app:repository_list_refreshed", {list:false,active:false});
		}		
	},
	
	/**
	 * Refresh the repositories list for the current user
	 */
	reloadRepositoriesList : function(){
		if(!this.user) return;
		document.observeOnce("app:registry_part_loaded", function(event){
			if(event.memo != "user/repositories") return;
			this.logXmlUser(this._registry, true);
			repId = this.user.getActiveRepository();
			repList = this.user.getRepositoriesList();
			document.fire("app:repository_list_refreshed", {list:repList,active:repId});			
		}.bind(this));
		this.loadXmlRegistry(false, "user/repositories");
	},
	
	/**
	 * Load a Repository instance
	 * @param repository Repository
	 */
	loadRepository: function(repository){
		
		if(this.repositoryId != null && this.repositoryId == repository.getId()){
			return;
		}
		
		repository.loadResources();
		var repositoryId = repository.getId();		
		var	newIcon = repository.getIcon(); 
				
		this.skipLsHistory = true;
		
		var providerDef = repository.getNodeProviderDef();
		if(providerDef != null){
			var provider = eval('new '+providerDef.name+'()');
			if(providerDef.options){
				provider.initProvider(providerDef.options);
			}
			this._contextHolder.setManifestNodeProvider(provider);
			var rootNode = new ManifestNode("/", false, repository.getLabel(), newIcon, provider);
		}else{
			var rootNode = new ManifestNode("/", false, repository.getLabel(), newIcon);
			// Default
			this._contextHolder.setManifestNodeProvider(new RemoteNodeProvider());
		}
		this._contextHolder.setRootNode(rootNode);
		this.repositoryId = repositoryId;
		
		/*
		if(this._initObj) { 
			rootNode.load();
			this._initObj = null ;
		}
		*/
		
		if(this._initLoadRep){
			if(this._initLoadRep != "" && this._initLoadRep != "/"){
				var copy = this._initLoadRep.valueOf();
				this._initLoadRep = null;
				rootNode.observeOnce("first_load", function(){
						setTimeout(function(){
                            this.goTo(copy);
                            this.skipLsHistory = false;
						}.bind(this), 1000);
				}.bind(this));
			}else{
				this.skipLsHistory = false;
			}
		}else{
			this.skipLsHistory = false;
		}
		
		rootNode.load();
	},

	/**
	 * Check whether a path exists by using the "stat" action.
	 * THIS SHOULD BE DELEGATED TO THE NODEPROVIDER.
	 * @param dirName String The path to check
	 * @returns Boolean
	 */
	pathExists : function(dirName){
		var connexion = new Connexion();
		connexion.addParameter("get_action", "stat");
		connexion.addParameter("file", dirName);
		var result = false;
		connexion.onComplete = function(transport){
			if(transport.responseJSON && transport.responseJSON.mode) result = true;
		}.bind(this);
		connexion.sendSync();		
		return result;
	},
	
	/**
	 * Require a context change to the given path
	 * @param nodeOrPath ManifestNode|String A node or a path
     * @param leaf ManifestNode|String path to the leaf item to be selected
	 */
	goTo: function(nodeOrPath){
        var path;
		if(Object.isString(nodeOrPath)){
			path = nodeOrPath
		}else{
			path = nodeOrPath.getPath();
            if(nodeOrPath.getMetadata().get("repository_id") != undefined && nodeOrPath.getMetadata().get("repository_id") != this.repositoryId){
                if(app.user){
                    app.user.setPreference("pending_folder", nodeOrPath.getPath());
                }
                this.triggerRepositoryChange(nodeOrPath.getMetadata().get("repository_id"));
                return;
            }
		}

        var gotoNode;
        if(path == "" || path == "/") {
            gotoNode = new ManifestNode("/");
            this._contextHolder.requireContextChange(gotoNode);
            return;
        }
        window.setTimeout(function(){

            this._contextHolder.loadPathInfoSync(path, function(foundNode){
                if(foundNode.isLeaf()) {
                    this._contextHolder.setPendingSelection(getBaseName(path));
                    gotoNode = new ManifestNode(getRepName(path));
                }else{
                    gotoNode = foundNode;
                }
            }.bind(this));
    		this._contextHolder.requireContextChange(gotoNode);

        }.bind(this), 0);
	},
	
	/**
	 * Change the repository of the current user and reload list and current.
	 * @param repositoryId String Id of the new repository
	 */
	triggerRepositoryChange: function(repositoryId){		
		document.fire("app:trigger_repository_switch");
		var connexion = new Connexion();
		connexion.addParameter('get_action', 'switch_repository');
		connexion.addParameter('repository_id', repositoryId);
		oThis = this;
		connexion.onComplete = function(transport){
			this.repositoryId = null;
			this.loadXmlRegistry();
		}.bind(this);
		var root = this._contextHolder.getRootNode();
		if(root){
			this.skipLsHistory = true;
			root.clear();			
		}
		connexion.sendAsync();
	},

	/**
	 * Find Extension initialisation nodes (activeCondition, onInit, etc), parses 
	 * the XML and execute JS. 
	 * @param xmlNode DOMNode The extension node
	 * @param extensionDefinition Object Information already collected about this extension
	 * @returns Boolean
	 */
	initExtension : function(xmlNode, extensionDefinition){
		var activeCondition = XPathSelectSingleNode(xmlNode, 'processing/activeCondition');
		if(activeCondition && activeCondition.firstChild){
			try{
				var func = new Function(activeCondition.firstChild.nodeValue.strip());
				if(func() === false) return false;
			}catch(e){}
		}
		if(xmlNode.nodeName == 'editor'){
			Object.extend(extensionDefinition, {
				openable : (xmlNode.getAttribute("openable") == "true"?true:false),
				previewProvider: (xmlNode.getAttribute("previewProvider")=="true"?true:false),
				order: (xmlNode.getAttribute("order")?parseInt(xmlNode.getAttribute("order")):0),
				formId : xmlNode.getAttribute("formId") || null,				
				text : MessageHash[xmlNode.getAttribute("text")],
				title : MessageHash[xmlNode.getAttribute("title")],
				icon : xmlNode.getAttribute("icon"),
				icon_class : xmlNode.getAttribute("iconClass"),
				editorClass : xmlNode.getAttribute("className"),
				mimes : $A(xmlNode.getAttribute("mimes").split(",")),
				write : (xmlNode.getAttribute("write") && xmlNode.getAttribute("write")=="true"?true:false)
			});
		}else if(xmlNode.nodeName == 'uploader'){
            var th = window._bootstrap.parameters.get('theme');
			var clientForm = XPathSelectSingleNode(xmlNode, 'processing/clientForm[@theme="'+th+'"]');
            if(!clientForm){
                clientForm = XPathSelectSingleNode(xmlNode, 'processing/clientForm');
            }
			if(clientForm && clientForm.firstChild && clientForm.getAttribute('id'))
			{
				extensionDefinition.formId = clientForm.getAttribute('id');
				if(!$('all_forms').select('[id="'+clientForm.getAttribute('id')+'"]').length){
					$('all_forms').insert(clientForm.firstChild.nodeValue);
				}
			}
			var extensionOnInit = XPathSelectSingleNode(xmlNode, 'processing/extensionOnInit');
			if(extensionOnInit && extensionOnInit.firstChild){
				try{eval(extensionOnInit.firstChild.nodeValue);}catch(e){}
			}
			var dialogOnOpen = XPathSelectSingleNode(xmlNode, 'processing/dialogOnOpen');
			if(dialogOnOpen && dialogOnOpen.firstChild){
				extensionDefinition.dialogOnOpen = dialogOnOpen.firstChild.nodeValue;
			}
			var dialogOnComplete = XPathSelectSingleNode(xmlNode, 'processing/dialogOnComplete');
			if(dialogOnComplete && dialogOnComplete.firstChild){
				extensionDefinition.dialogOnComplete = dialogOnComplete.firstChild.nodeValue;
			}
		}
		return true;
	},
	
	/**
	 * Refresh the currently active extensions
	 * Extensions are editors and uploaders for the moment.
	 */
	refreshExtensionsRegistry : function(){
		this._extensionsRegistry = {"editor":$A([]), "uploader":$A([])};
		var extensions = XPathSelectNodes(this._registry, "plugins/editor|plugins/uploader");
		for(var i=0;i<extensions.length;i++){
			var extensionDefinition = {
				id : extensions[i].getAttribute("id"),
				xmlNode : extensions[i],
				resourcesManager : new ResourcesManager()				
			};
			this._resourcesRegistry[extensionDefinition.id] = extensionDefinition.resourcesManager;
            var resourceNodes = XPathSelectNodes(extensions[i], "client_settings/resources|dependencies|clientForm");
			for(var j=0;j<resourceNodes.length;j++){
				var child = resourceNodes[j];
				extensionDefinition.resourcesManager.loadFromXmlNode(child);
			}
			if(this.initExtension(extensions[i], extensionDefinition)){
				this._extensionsRegistry[extensions[i].nodeName].push(extensionDefinition);
			}
		}
		ResourcesManager.prototype.loadAutoLoadResources(this._registry);
	},
	
	getPluginConfigs : function(pluginQuery){
		var properties = XPathSelectNodes(this._registry, 'plugins/'+pluginQuery+'/plugin_configs/property | plugins/core[@id="core.'+pluginQuery+'"]/plugin_configs/property | plugins/plugin[@id="core.'+pluginQuery+'"]/plugin_configs/property');
		var configs = $H();
		for(var i = 0; i<properties.length; i++){
			var propNode = properties[i];
			configs.set(propNode.getAttribute("name"), propNode.firstChild.nodeValue.evalJSON());
		}
		return configs;
	},

    hasPluginOfType : function(type, name){
        if(name == null){
            var node = XPathSelectSingleNode(this._registry, 'plugins/plugin[contains(@id, "'+type+'.")] | plugins/' + type + '[@id]');
        }else{
            var node = XPathSelectSingleNode(this._registry, 'plugins/plugin[@id="'+type+'.'+name+'"] | plugins/' + type + '[@id="'+type+'.'+name+'"]');
        }
        if(node) return true;
        return false;
    },

	/**
	 * Find the currently active extensions by type
	 * @param extensionType String "editor" or "uploader"
	 * @returns $A()
	 */
	getActiveExtensionByType : function(extensionType){
		var exts = $A();
		return this._extensionsRegistry[extensionType];
	},
	
	/**
	 * Find a given editor by its id
	 * @param editorId String
	 * @returns AbstractEditor
	 */
	findEditorById : function(editorId){
		return this._extensionsRegistry.editor.detect(function(el){return(el.id == editorId);});
	},
	
	/**
	 * Find Editors that can handle a given mime type
	 * @param mime String
	 * @returns AbstractEditor[]
	 */
	findEditorsForMime : function(mime, restrictToPreviewProviders){
		var editors = $A([]);
		var checkWrite = false;
		if(this.user != null && !this.user.canWrite()){
			checkWrite = true;
		}
		this._extensionsRegistry.editor.each(function(el){
			if(el.mimes.include(mime) || el.mimes.include('*')) {
				if(restrictToPreviewProviders && !el.previewProvider) return;
				if(!checkWrite || !el.write) editors.push(el);
			}
		});
		if(editors.length && editors.length > 1){
			editors = editors.sortBy(function(ed){
				return ed.order||0;
			});
		}
		return editors;
	},
	
	/**
	 * Trigger the load method of the resourcesManager.
	 * @param resourcesManager ResourcesManager
	 */
	loadEditorResources : function(resourcesManager){
		var registry = this._resourcesRegistry;
		resourcesManager.load(registry);
	},
	
	/**
	 * Inserts the main template in the GUI.
	 */
	initTemplates:function(passedTarget){
		if(!this._registry) return;
		var tNodes = XPathSelectNodes(this._registry, "client_configs/template");
		for(var i=0;i<tNodes.length;i++){
			var target = tNodes[i].getAttribute("element");
            var themeSpecific = tNodes[i].getAttribute("theme");
            if(themeSpecific && window._bootstrap.parameters.get("theme") && window._bootstrap.parameters.get("theme") != themeSpecific){
                continue;
            }
			if($(target) || $$(target).length || passedTarget){
				if($(target)) target = $(target);
				else target = $$(target)[0];
				if(passedTarget) target = passedTarget;
				var position = tNodes[i].getAttribute("position");
				var obj = {}; obj[position] = tNodes[i].firstChild.nodeValue;
				target.insert(obj);
				obj[position].evalScripts();
			}
		}		
		modal.updateLoadingProgress('Html templates loaded');	
	},
		
	findOriginalTemplatePart : function(appId){
		var tmpElement = new Element("div", {style:"display:none;"});
		$$("body")[0].insert(tmpElement);
		this.initTemplates(tmpElement);
		var tPart = tmpElement.down('[id="'+appId+'"]');
        if(tPart) tPart = tPart.clone(true);
		tmpElement.remove();
		return tPart;
	},
	
	/**
	 * Trigger a simple download
	 * @param url String
	 */
    triggerDownload: function(url){
        document.location.href = url;
    },

    /**
     * Reload all messages from server and trigger updateI18nTags
     * @param newLanguage String
     */
	loadI18NMessages: function(newLanguage){
		var connexion = new Connexion();
		connexion.addParameter('get_action', 'get_i18n_messages');
		connexion.addParameter('lang', newLanguage);
		connexion.onComplete = function(transport){
			if(transport.responseText){
				var result = transport.responseText.evalScripts();
				MessageHash = result[0];
				for(var key in MessageHash){
					MessageHash[key] = MessageHash[key].replace("\\n", "\n");
				}
				this.updateI18nTags();
				if(this.guiActions){
					this.guiActions.each(function(pair){
						pair.value.refreshFromI18NHash();
					});
				}
				this.loadXmlRegistry();
				this.fireContextRefresh();
				this.currentLanguage = newLanguage;
			}
		}.bind(this);
		connexion.sendSync();
	},
	
	/**
	 * Search all message_id tags and update their value
	 */
	updateI18nTags: function(){
		var messageTags = $$('[message_id]');		
		messageTags.each(function(tag){	
			var messageId = tag.getAttribute("message_id");
			try{
				tag.update(MessageHash[messageId]);
			}catch(e){}
		});
	},
	
	/**
	 * Trigger a captcha image
	 * @param seedInputField HTMLInput The seed value
	 * @param existingCaptcha HTMLImage An image (optional)
	 * @param captchaAnchor HTMLElement Where to insert the image if created.
	 * @param captchaPosition String Position.insert() possible key.
	 */
	loadSeedOrCaptcha : function(seedInputField, existingCaptcha, captchaAnchor, captchaPosition){
		var connexion = new Connexion();
		connexion.addParameter("get_action", "get_seed");
		connexion.onComplete = function(transport){
			if(transport.responseJSON){
				seedInputField.value = transport.responseJSON.seed;
				var src = window.appServerAccessPath + '&get_action=get_captcha&sid='+Math.random();
				var refreshSrc = resourcesFolder + '/images/actions/16/reload.png';
				if(existingCaptcha){
					existingCaptcha.src = src;
				}else{
					var insert = {};
					var string = '<div class="main_captcha_div" style="padding-top: 4px;"><div class="dialogLegend" message_id="389">'+MessageHash[389]+'</div>';
					string += '<div class="captcha_container"><img id="captcha_image" align="top" src="'+src+'" width="170" height="80"><img align="top" style="cursor:pointer;" id="captcha_refresh" src="'+refreshSrc+'" with="16" height="16"></div>';
					string += '<div class="SF_element">';
					string += '		<div class="SF_label" message_id="390">'+MessageHash[390]+'</div> <div class="SF_input"><input type="text" class="dialogFocus dialogEnterKey" style="width: 100px; padding: 0px;" name="captcha_code"></div>';
					string += '</div>';
					string += '<div style="clear:left;margin-bottom:7px;"></div></div>';
					insert[captchaPosition] = string;
					captchaAnchor.insert(insert);
					modal.refreshDialogPosition();
					modal.refreshDialogAppearance();
					$('captcha_refresh').observe('click', function(){
						$('captcha_image').src = window.appServerAccessPath + '&get_action=get_captcha&sid='+Math.random();
					});
				}
			}else{
				seedInputField.value = transport.responseText;
				if(existingCaptcha){
					existingCaptcha.up('.main_captcha_div').remove();
					modal.refreshDialogPosition();
					modal.refreshDialogAppearance();
				}
			}
		};
		connexion.sendSync();		
	},
			
	/**
	 * Updates the browser history
	 * @param path String Path
	 */
	updateHistory: function(path){
		if(this.history) this.history.historyLoad(this.pathToHistoryHash(path));
	},
	
	/**
	 * Translate the path to a history step. Return the count.
	 * @param path String
	 * @returns Integer
	 */
	pathToHistoryHash: function(path){
		document.title = this.appTitle + ' - '+(getBaseName(path)?getBaseName(path):'/');
		if(!this.pathesHash){
			this.pathesHash = new Hash();
			this.histCount = -1;
		}
		var foundKey;
		this.pathesHash.each(function(pair){
			if(pair.value == path) foundKey = pair.key;
		});
		if(foundKey != undefined) return foundKey;
	
		this.histCount++;
		this.pathesHash.set(this.histCount, path);
		return this.histCount;
	},
	
	/**
	 * Reverse operation
	 * @param hash Integer
	 * @returns String
	 */
	historyHashToPath: function(hash){
		if(!this.pathesHash) return "/";
		var path = this.pathesHash.get(hash);
		if(path == undefined) return "/";
		return path;
	},	

	/**
	 * Accessor for updating the datamodel context
	 * @param contextNode ManifestNode
	 * @param selectedNodes ManifestNode[]
	 * @param selectionSource String
	 */
	updateContextData : function(contextNode, selectedNodes, selectionSource){
		if(contextNode){
			this._contextHolder.requireContextChange(contextNode);
		}
		if(selectedNodes){
			this._contextHolder.setSelectedNodes(selectedNodes, selectionSource);
		}
	},
	
	/**
	 * @returns DataModel
	 */
	getContextHolder : function(){
		return this._contextHolder;
	},
	
	/**
	 * @returns ManifestNode
	 */
	getContextNode : function(){
		return this._contextHolder.getContextNode() || new ManifestNode("");
	},
	
	/**
	 * @returns DataModel
	 */
	getUserSelection : function(){
		return this._contextHolder;
	},		
	
	/**
	 * Accessor for datamodel.requireContextChange()
	 */
	fireContextRefresh : function(){
		this.getContextHolder().requireContextChange(this.getContextNode(), true);
	},
	
	/**
	 * Accessor for datamodel.requireContextChange()
	 */
	fireNodeRefresh : function(nodePathOrNode){
		this.getContextHolder().requireNodeReload(nodePathOrNode);
	},
	
	/**
	 * Accessor for datamodel.requireContextChange()
	 */
	fireContextUp : function(){
		if(this.getContextNode().isRoot()) return;
		this.updateContextData(this.getContextNode().getParent());
	},
	
	/**
	 * @returns DOMDocument
	 */
	getXmlRegistry : function(){
		return this._registry;
	},	
	
	/**
	 * Utility 
	 * @returns Boolean
	 */
	cancelCopyOrMove: function(){
		this.actionBar.treeCopyActive = false;
		//hideLightBox();
		return false;
	},
		
	/**
	 * Blocks all access keys
	 */
	disableShortcuts: function(){
		this.blockShortcuts = true;
	},
	
	/**
	 * Unblocks all access keys
	 */
	enableShortcuts: function(){
		this.blockShortcuts = false;
	},
	
	/**
	 * blocks all tab keys
	 */
	disableNavigation: function(){
		this.blockNavigation = true;
	},
	
	/**
	 * Unblocks all tab keys
	 */
	enableNavigation: function(){
		this.blockNavigation = false;
	},

    disableAllKeyBindings : function(){
       this.blockNavigation = this.blockShortcuts = this.blockEditorShortcuts = true;
    },

    enableAllKeyBindings : function(){
       this.blockNavigation = this.blockShortcuts = this.blockEditorShortcuts = false;
    },

	/**
	 * Unblocks all access keys
	 */	
	getActionBar: function(){
		return this.actionBar;
	},
	
	/**
	 * Display an information or error message to the user 
	 * @param messageType String ERROR or SUCCESS
	 * @param message String the message
	 */	
	displayMessage: function(messageType, message){
		var urls = parseUrl(message);
		if(urls.length && this.user && this.user.repositories){
			urls.each(function(match){
				var repo = this.user.repositories.get(match.host);
				if(!repo) return;
				message = message.replace(match.url, repo.label+":" + match.path + match.file);
			}.bind(this));
		}
		modal.displayMessage(messageType, message);
	},
	
	/**
	 * Focuses on a given widget
	 * @param object IFocusable
	 */
	focusOn : function(object){
		this._focusables.each(function(obj){
			if(obj != object) obj.blur();
		});
		object.focus();
	},
	
	/**
	 * Blur all widgets
	 */
	blurAll : function(){
		this._focusables.each(function(f){
			if(f.hasFocus) this._lastFocused = f;
			f.blur();
		}.bind(this) );
	},	
	
	/**
	 * Find last focused IFocusable and focus it!
	 */
	focusLast : function(){
		if(this._lastFocused) this.focusOn(this._lastFocused);
	},
	
	/**
	 * Create a Tab navigation between registerd IFocusable
	 */
	initTabNavigation: function(){
		// ASSIGN OBSERVER
		Event.observe(document, "keydown", function(e)
		{			
			if(e.keyCode == Event.KEY_TAB)
			{
				if(this.blockNavigation) return;
                var objects = [];
                $A(this._focusables).each(function(el){
                    if((!el.htmlElement || el.htmlElement.visible())){
                        objects.push(el);
                    }
                });
                var shiftKey = e['shiftKey'];
				var foundFocus = false;
				for(i=0; i<objects.length;i++)
				{
					if(objects[i].hasFocus)
					{
						objects[i].blur();
						var nextIndex;
						if(shiftKey)
						{
							if(i>0) nextIndex=i-1;
							else nextIndex = (objects.length) - 1;
						}
						else
						{
							if(i<objects.length-1)nextIndex=i+1;
							else nextIndex = 0;
						}
                        objects[nextIndex].focus();
                        foundFocus = true;
                        break;
					}
				}
				if(!foundFocus && objects[0]){
					this.focusOn(objects[0]);
				}
				Event.stop(e);
			}
			if(this.blockShortcuts || e['ctrlKey'] || e['metaKey']) return;
			if(e.keyCode != Event.KEY_DELETE && ( e.keyCode > 90 || e.keyCode < 65 ) ) return;
			else return this.actionBar.fireActionByKey(e, (e.keyCode == Event.KEY_DELETE ? "key_delete":String.fromCharCode(e.keyCode).toLowerCase()));
		}.bind(this));
	}
		
});
