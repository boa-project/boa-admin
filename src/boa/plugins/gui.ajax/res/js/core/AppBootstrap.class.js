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
 * Main BootLoader.
 * Defaults params for constructor should be {} and content.php?get_action=get_boot_conf
 */
Class.create("AppBootstrap", {
	/**
	 * @var $H()
	 */
	parameters : $H({}),
	/**
	 * Constructor 
	 * @param startParameters Object The options
	 */
	initialize : function(startParameters){
		this.parameters = $H(startParameters);
		this.detectBaseParameters();
		if(this.parameters.get("ALERT")){
			window.setTimeout(function(){alert(this.parameters.get("ALERT"));}.bind(this),0);
		}		
		Event.observe(document, 'dom:loaded', function(){
			this.insertBasicSkeleton(this.parameters.get('MAIN_ELEMENT'));
            var startedFromOpener = false;
            try{
                if(window.opener && window.opener._bootstrap){
                    this.parameters = window.opener._bootstrap.parameters;
                    // Handle queryString case, as it's not passed via get_boot_conf
                    var qParams = document.location.href.toQueryParams();
                    if(qParams['external_selector_type']){
                        this.parameters.set('SELECTOR_DATA', {type:qParams['external_selector_type'], data:qParams});
                    }else{
                        if(this.parameters.get('SELECTOR_DATA')) this.parameters.unset('SELECTOR_DATA');
                    }
                    this.refreshContextVariablesAndInit(new Connexion());
                    startedFromOpener = true;
                }
            }catch(e){
                if(console && console.log) console.log(e);
            }
            if(!startedFromOpener){
                this.loadBootConfig();
            }
		}.bind(this));
		document.observe("app:before_gui_load", function(e){
			var desktop = $(this.parameters.get('MAIN_ELEMENT'));
			var options = desktop.getAttribute("appOptions").evalJSON(false);
			if(options.fit && options.fit == 'height'){
				var marginBottom = 0;
				if(options.fitMarginBottom){
					try{marginBottom = parseInt(eval(options.fitMarginBottom));}catch(e){}
				}
				if(options.fitParent == 'window') options.fitParent = window;
				else options.fitParent = $(options.fitParent);
				fitHeightToBottom($(this.parameters.get('MAIN_ELEMENT')), options.fitParent, marginBottom, true);
			}
		}.bind(this));
		document.observe("app:actions_loaded", function(){
			if(!this.parameters.get("SELECTOR_DATA") && app.actionBar.actions.get("ext_select")){
				app.actionBar.actions.unset("ext_select");
				app.actionBar.fireContextChange();
				app.actionBar.fireSelectionChange();	
			}else if(this.parameters.get("SELECTOR_DATA")){
				app.actionBar.defaultActions.set("file", "ext_select");
			}
		}.bind(this));					
		document.observe("app:loaded", function(e){
			this.insertAnalytics();
			if(this.parameters.get("SELECTOR_DATA")){
	    		app.actionBar.defaultActions.set("file", "ext_select");
	    		app.actionBar.selectorData = new Hash(this.parameters.get("SELECTOR_DATA"));	    		
			}
		}.bind(this));
	},
	/**
	 * Real loading action
	 */
	loadBootConfig : function(){
        if(this.parameters.get('PRELOADED_BOOT_CONF')){
            this.parameters.update(this.parameters.get('PRELOADED_BOOT_CONF'));
            if(this.parameters.get('SECURE_TOKEN')){
                Connexion.SECURE_TOKEN = this.parameters.get('SECURE_TOKEN');
            }
            if(this.parameters.get('SERVER_PREFIX_URI')){
                this.parameters.set('resourcesFolder', this.parameters.get('SERVER_PREFIX_URI') + this.parameters.get('resourcesFolder'));
                this.parameters.set('appServerAccess', this.parameters.get('SERVER_PREFIX_URI') + this.parameters.get('appServerAccess') + '?' + (Connexion.SECURE_TOKEN? 'secure_token='+Connexion.SECURE_TOKEN:''));
            }else{
                this.parameters.set('appServerAccess', this.parameters.get('appServerAccess') + '?' + (Connexion.SECURE_TOKEN? 'secure_token='+Connexion.SECURE_TOKEN:''));
            }
            this.refreshContextVariablesAndInit(new Connexion());
            return;
        }

		var url = this.parameters.get('BOOTER_URL')+(this.parameters.get("debugMode")?'&debug=true':'');
		if(this.parameters.get('SERVER_PREFIX_URI')){
			url += '&server_prefix_uri=' + this.parameters.get('SERVER_PREFIX_URI').replace(/\.\.\//g, "_UP_/");
		}
		var connexion = new Connexion(url);
		connexion.onComplete = function(transport){			
			if(transport.responseXML && transport.responseXML.documentElement && transport.responseXML.documentElement.nodeName == "tree"){
				var alert = XPathSelectSingleNode(transport.responseXML.documentElement, "message");
				window.alert('Exception caught by application : ' + alert.firstChild.nodeValue);
				return;
			}
			var phpError;
			try{
				var data = transport.responseText.evalJSON();
			}catch(e){
				phpError = 'Error while parsing JSON response : ' + e.message;
			}
			if(!typeof data == "object"){
				phpError = 'Exception uncaught by application : ' + transport.responseText;
			}
			if(phpError){
				document.write(phpError);
				if(phpError.indexOf('<b>Notice</b>')>-1 || phpError.indexOf('<b>Strict Standards</b>')>-1){
					window.alert('Php errors detected, it seems that Notice or Strict are detected, you may consider changing the PHP Error Reporting level!');
				}
				return;
			}
			this.parameters.update(data);
			
			if(this.parameters.get('SECURE_TOKEN')){
				Connexion.SECURE_TOKEN = this.parameters.get('SECURE_TOKEN');
			}
			if(this.parameters.get('SERVER_PREFIX_URI')){
				this.parameters.set('resourcesFolder', this.parameters.get('SERVER_PREFIX_URI') + this.parameters.get('resourcesFolder'));
				this.parameters.set('appServerAccess', this.parameters.get('SERVER_PREFIX_URI') + this.parameters.get('appServerAccess') + '?' + (Connexion.SECURE_TOKEN? 'secure_token='+Connexion.SECURE_TOKEN:''));
			}else{
				this.parameters.set('appServerAccess', this.parameters.get('appServerAccess') + '?' + (Connexion.SECURE_TOKEN? 'secure_token='+Connexion.SECURE_TOKEN:''));
			}
			
			this.refreshContextVariablesAndInit(connexion);
			
		}.bind(this);
		connexion.sendSync();
		
	},
	
	refreshContextVariablesAndInit: function(connexion){
		if(this.parameters.get('SECURE_TOKEN') && !Connexion.SECURE_TOKEN){
			Connexion.SECURE_TOKEN = this.parameters.get('SECURE_TOKEN');
		}

		// Refresh window variable
		window.appServerAccessPath = this.parameters.get('appServerAccess');
		var cssRes = this.parameters.get("cssResources");
		if(cssRes) cssRes.each(this.loadCSSResource.bind(this));
		if(this.parameters.get('resourcesFolder')){
            connexion._libUrl = this.parameters.get('resourcesFolder') + "/js";
			window.resourcesFolder = this.parameters.get('resourcesFolder') + "/themes/" + this.parameters.get("theme");
		}
		if(this.parameters.get('additional_js_resource')){
			connexion.loadLibrary(this.parameters.get('additional_js_resource?v='+this.parameters.get("appVersion")));
		}
		this.insertLoaderProgress();
		if(!this.parameters.get("debugMode")){
			connexion.loadLibrary("app.js?v="+this.parameters.get("appVersion"));
		}
		window.MessageHash = this.parameters.get("i18nMessages");
        if(!Object.keys(MessageHash).length){
            alert('Ooups, this should not happen, your message file is empty!');
        }
		for(var key in MessageHash){
			MessageHash[key] = MessageHash[key].replace("\\n", "\n");
		}
		window.zipEnabled = this.parameters.get("zipEnabled");
		window.multipleFilesDownloadEnabled = this.parameters.get("multipleFilesDownloadEnabled");
		document.fire("app:boot_loaded");
		window.app = new App(this.parameters.get("EXT_REP")||"", this.parameters.get("usersEnabled"), this.parameters.get("loggedUser"));
		if(this.parameters.get("currentLanguage")){
			window.app.currentLanguage = this.parameters.get("currentLanguage");
		}
		$('version_span').update(' - Version '+this.parameters.get("appVersion") + ' - '+ this.parameters.get("appVersionDate"));
		window.app.init();		
	},
	
	/**
	 * Detect the base path of the javascripts based on the script tags
	 */
	detectBaseParameters : function(){
		$$('script').each(function(scriptTag){
			if(scriptTag.src.match("/js/app_boot") || scriptTag.src.match("/js/core/AppBootstrap.class.js")){
				if(scriptTag.src.match("/js/app_boot")){
					this.parameters.set("debugMode", false);
				}else{
					this.parameters.set("debugMode", true);
				}
        var src = scriptTag.src.replace('/js/core/AppBootstrap.class.js','').replace('/js/app_boot.js', '').replace('/js/app_boot_protolegacy.js', '');
        if(src.indexOf("?")!=-1) src = src.split("?")[0];
				this.parameters.set("resourcesFolder", src);
			}
		}.bind(this) );
		if(this.parameters.get("resourcesFolder")){
			window.resourcesFolder = this.parameters.get("resourcesFolder");		
		}else{
			alert("Cannot find resource folder");
		}
		var booterUrl = this.parameters.get("BOOTER_URL");
		if(booterUrl.indexOf("?") > -1){
			booterUrl = booterUrl.substring(0, booterUrl.indexOf("?"));
		}
		this.parameters.set('appServerAccessPath', booterUrl);
		window.appServerAccessPath = booterUrl;
	},
	/**
	 * Inserts a progress bar 
	 */
	insertLoaderProgress : function(targetContainer, passedParameters){
		var html = '<div id="loading_overlay" style="background-color:#555555;opacity: 0.2;"></div>';
		if(this.parameters.get('customWelcomeScreen')){
			try { this.parameters.set('customWelcomeScreen', customFuncDecode(this.parameters.get('customWelcomeScreen')));
			}catch(e){
				this.parameters.set('customWelcomeScreen','');
			}
		}		
		if(this.parameters.get('customWelcomeScreen')){
			html += this.parameters.get('customWelcomeScreen');
		}else{
            var customWording;
            if(passedParameters){
                customWording = passedParameters;
            }else{
                customWording = this.parameters.get("customWording");
            }
			html+='	<div id="progressBox" class="dialogBox" style="width: 320px;display:block;top:30%;z-index:2002;left:40%;position: absolute;background-color: #fff;padding: 0;">';
			html+='	<div align="center" class="dialogContent" style="color:#676965;font-family:Trebuchet MS,sans-serif;font-size:11px;font-weight:normal;left:10px;padding:10px;border-bottom:0px;padding-bottom:0px">';
			var icon = customWording.icon || resourcesFolder+'/../../../AppLogo250.png';
            if(customWording.icon_binary_url){
                icon = this.parameters.get("appServerAccess") + "&" + customWording.icon_binary_url;
            }
			var title = customWording.title || ""; //Left the title empty if not provided on configuration
			var iconWidth = customWording.iconWidth || '35px';
			var fontSize = customWording.titleFontSize || '35px';
            var titleDivSize = (customWording.iconHeight ? 'height:' + customWording.iconHeight + ';' : '');
			html+=' <div style="margin-bottom:0px; font-size:'+
                fontSize+';font-weight:bold; background-image:url(\''+ (this.parameters.get("SERVER_PREFIX_URI") || '') + 
                icon+'\');background-position:left center;background-repeat:no-repeat;width:'+
                iconWidth+';'+titleDivSize+'color:#0077b3;">'+(customWording.iconOnly?'':title)+'</div>';

			if(customWording.title.toLowerCase() != "app"){
				html+='	<div style="padding:4px 7px;position: relative;"><div>BoA - Explorer<span id="version_span"></span></div>';
			}else{
				html+='	<div style="padding:4px 7px;position: relative;"><div>The web data-browser<span id="version_span"></span></div>';
			}
			//html+='	Copyright C. du Jeu 2008-2013 - AGPL License. <div id="progressCustomMessage" style="margin-top: 35px;font-weight: bold;padding-bottom: 5px;">';
            html+=' Copyright BoA 2017 - AGPL License. <div id="progressCustomMessage" style="margin-top: 35px;font-weight: bold;padding-bottom: 5px;">';
			if(customWording.welcomeMessage){
				html+= customWording.welcomeMessage.replace(new RegExp("\n", "g"), "<br>");
			}
            html+="</div></div></div>";
            html+=' <div align="left" class="dialogContent" style="color:#676965;font-family:Trebuchet MS,sans-serif;font-size:11px;font-weight:normal;left:10px;padding:10px;border-top:0px;padding-top:0px">';
            html+='<div style="padding:4px 7px;position: relative;"><div id="progressState" style="float:left; display: inline;">Booting...</div>';
			html+='	<div id="progressBarContainer" style="margin-top:3px; margin-left: 126px;"><span id="loaderProgress"></span></div>';
            html+= '<div id="progressBarHeighter" style="height:10px;"></div>';
			html+='	</div></div>';
		}
        var viewPort;
        if(!targetContainer){
            targetContainer = $$('body')[0];
            viewPort = document.viewport.getDimensions();
        }else{
            viewPort = targetContainer.getDimensions();
        }
        targetContainer.insert(html);
        var progressBox = targetContainer.down('#progressBox');
		progressBox.setStyle({
            left:parseInt(Math.max((viewPort.width-progressBox.getWidth())/2,0))+"px",
            top:parseInt(Math.max((viewPort.height-progressBox.getHeight())/3,0))+"px"
        });
		var options = {
			animate		: true,										// Animate the progress? - default: true
			showText	: false,									// show text with percentage in next to the progressbar? - default : true
			width		: 154,										// Width of the progressbar - don't forget to adjust your image too!!!
			boxImage	: window.resourcesFolder+'/images/progress_box.gif',			// boxImage : image around the progress bar
			barImage	: window.resourcesFolder+'/images/progress_bar.gif',	// Image to use in the progressbar. Can be an array of images too.
			height		: 11,										// Height of the progressbar - don't forget to adjust your image too!!!
			onTick		: function(pbObj) { 
				if(pbObj.getPercentage() == 100){
                    new Effect.Parallel([
                            new Effect.Opacity($('loading_overlay'),{sync:true,from:0.2,to:0,duration:0.3}),
                            new Effect.Opacity($('progressBox'),{sync:true,from:1,to:0,duration:0.3})
                        ],
                        {afterFinish : function(){
                            $('loading_overlay').remove();
                            if ($('progressCustomMessage').innerHTML.strip() && $("generic_dialog_box") && $("generic_dialog_box").visible() && $("generic_dialog_box").down('div.dialogLegend')){
                                $("generic_dialog_box").down('div.dialogLegend').update($('progressCustomMessage').innerHTML.strip());
                            }
                            $('progressBox').remove();
                        }});
					return false;
				}
				return true ;
			}
		};
		window.loaderProgress = new JS_BRAMUS.jsProgressBar($('loaderProgress'), 0, options); 
	},
	/**
	 * Inserts Google Analytics Code
	 */
	insertAnalytics : function(){	
		if(!this.parameters.get("googleAnalyticsData")) return;
		var data = this.parameters.get("googleAnalyticsData");
		window._gaq = window._gaq || [];
		window._gaq.push(['_setAccount', data.id]);		
		if(data.domain) window._gaq.push(['_setDomainName', data.domain]);
		window._gaq.push(['_trackPageview']);
		window._gaTrackEvents = data.event;
		window.setTimeout(function(){
			var src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';
			var ga = new Element("script", {type:'text/javascript', async:'true',src:src});
			($$('head')[0] || $$('body')[0]).insert(ga);
		}, 200);
	},
	/**
	 * Loads a CSS file
	 * @param fileName String
	 */
	loadCSSResource : function(fileName){
		var head = $$('head')[0];
		var cssNode = new Element('link', {
			type : 'text/css',
			rel  : 'stylesheet',
			href : this.parameters.get("resourcesFolder") + '/' + fileName,
			media : 'screen'
		});
		head.insert(cssNode);
	},
	/**
	 * Inserts the all_forms and generic dialog box if not alreay present.
	 * @param desktopNode String The id of the node to attach
	 */
	insertBasicSkeleton : function(desktopNode){
        var elem = new Element("div", {style:"position: absolute;z-index: 10000; bottom: 0; right: 0; color: #666;font-family: Arial;font-size: 11px;text-align: right;padding: 3px; padding-right: 10px;"});
        $(desktopNode).insert({after:elem});
        disableTextSelection(elem);
		if($('all_forms')) return;
		$(desktopNode).insert({after:
			'<div id="all_forms">\
				<div id="generic_dialog_box" class="dialogBox"><div class="dialogTitle"></div><div class="dialogContent"></div></div>\
				<div id="hidden_frames" style="display:none;"></div>\
				<div id="hidden_forms" style="position:absolute;left:-1000px;"></div>\
			</div>'});
	}
});