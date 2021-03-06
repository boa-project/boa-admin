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
 * Encapsulation of Ajax.Request
 */
Class.create("Connexion", {

    discrete : false,

    /**
     * Constructor
     * @param baseUrl String The base url for services
     */
    initialize: function(baseUrl)
    {
        this._baseUrl = window.appServerAccessPath;
        if(baseUrl) this._baseUrl = baseUrl;
        this._libUrl = window.resourcesFolder+'/js';
        this._parameters = new Hash();
        this._method = 'get';
    },
    
    /**
     * Add a parameter to the query
     * @param paramName String
     * @param paramValue String
     */
    addParameter : function (paramName, paramValue){
        this._parameters.set(paramName, paramValue);    
    },
    
    /**
     * Sets the whole parameter as a bunch
     * @param hParameters $H()
     */
    setParameters : function(hParameters){
        this._parameters = $H(hParameters);
    },
    
    /**
     * Set the query method (get post)
     * @param method String
     */
    setMethod : function(method){
        this._method = 'put';
    },
    
    /**
     * Add the secure token parameter
     */
    addSecureToken : function(){
        if(Connexion.SECURE_TOKEN && this._baseUrl.indexOf('secure_token') == -1 && !this._parameters.get('secure_token')){
            this.addParameter('secure_token', Connexion.SECURE_TOKEN);
        }
    },

    /**
     * Show a small loader
     */
    showLoader : function(){
        if(this.discrete) return;
        if(!$('Connexion-loader') && window._bootstrap.parameters.get("theme")){
            var span = new Element("span", {
                id:'Connexion-loader',
                style:'position:absolute;top:2px;right:2px;z-index:40000;display:none;'});
            var img = new Element("img", {
                src:resourcesFolder+"/images/loadingImage.gif"
            });
            span.insert(img);
            $$('body')[0].insert(span);
        }
        if($('Connexion-loader')) $('Connexion-loader').show();
    },

    /**
     * Hide a small loader
     */
    hideLoader : function(){
        if(this.discrete) return;
        if($('Connexion-loader'))$('Connexion-loader').hide();
    },

    /**
     * Send Asynchronously
     */
    sendAsync : function(){ 
        this.addSecureToken();
        this.showLoader();
        var t = new Ajax.Request(this._baseUrl,
        {
            method:this._method,
            onComplete:this.applyComplete.bind(this),
            onInteractive:this.applyInteractive.bind(this),
            parameters:this._parameters.toObject()
        });
        try {if(Prototype.Browser.IE10) t.transport.responseType =  'msxml-document'; } catch(e){}
    },
    
    /**
     * Send synchronously
     */
    sendSync : function(){  
        this.addSecureToken();
        this.showLoader();
        var t = new Ajax.Request(this._baseUrl,
        {
            method:this._method,
            asynchronous: false,
            onComplete:this.applyComplete.bind(this),
            parameters:this._parameters.toObject(),
            msxmldoctype: true
        });
    },
    
    /**
     * Apply the complete callback, try to grab maximum of errors
     * @param transport Transpot
     */
    applyComplete : function(transport){
        this.hideLoader();
        var message;
        var tokenMessage;
        var tok1 = "Ooops, it seems that your security token has expired! Please %s by hitting refresh or F5 in your browser!";
        var tok2 =  "reload the page";
        if(window.MessageHash && window.MessageHash[437]){
            var tok1 = window.MessageHash[437];
            var tok2 = window.MessageHash[438];
        }
        tokenMessage = tok1.replace("%s", "<a href='javascript:document.location.reload()' style='text-decoration: underline;'>"+tok2+"</a>");

        var headers = transport.getAllResponseHeaders();
        if(Prototype.Browser.Gecko && transport.responseXML && transport.responseXML.documentElement && transport.responseXML.documentElement.nodeName=="parsererror"){
            message = "Parsing error : \n" + transport.responseXML.documentElement.firstChild.textContent;                  
        }else if(Prototype.Browser.IE && transport.responseXML && transport.responseXML.parseError && transport.responseXML.parseError.errorCode != 0){
            message = "Parsing Error : \n" + transport.responseXML.parseError.reason;
        }else if(headers.indexOf("text/xml")>-1 && transport.responseXML == null){
            message = "Unknown Parsing Error!";
        }else if(headers.indexOf("text/xml") == -1 && headers.indexOf("application/json") == -1 && transport.responseText.indexOf("<b>Fatal error</b>") > -1){
            message = transport.responseText.replace("<br />", "");
        }else if(transport.status == 500){
            message = "Internal Server Error: you should check your web server logs to find what's going wrong!";
        }
        if(message){
            if(message.startsWith("You are not allowed to access this resource.")) message = tokenMessage;
            if(app) app.displayMessage("ERROR", message);
            else alert(message);
        }
        if(transport.responseXML && transport.responseXML.documentElement){
            var authNode = XPathSelectSingleNode(transport.responseXML.documentElement, "require_auth");
            if(authNode && app){
                var root = app._contextHolder.getRootNode();
                if(root){
                    app._contextHolder.setContextNode(root);
                    root.clear();
                }
                app.actionBar.fireAction('logout');
                app.actionBar.fireAction('login');
            }
            var messageNode = XPathSelectSingleNode(transport.responseXML.documentElement, "message");
            if(messageNode){
                var messageType = messageNode.getAttribute("type").toUpperCase();
                var messageContent = getDomNodeText(messageNode);
                if(messageContent == "You are not allowed to access this resource.") messageContent = tokenMessage;
                if(app){
                    app.displayMessage(messageType, messageContent);
                }else{
                    if(messageType == "ERROR"){
                        alert(messageType+":"+messageContent);
                    }
                }
            }
        }
        if(this.onComplete){
            this.onComplete(transport);
        }
        document.fire("app:server_answer");
    },
    
    /**
     * Apply the complete callback, try to grab maximum of errors
     * @param transport Transpot
     */
    applyInteractive : function(transport){
        if(this.onInteractive){
            this.onInteractive(transport);
        }
    },
    
    /**
     * Load a javascript library
     * @param fileName String
     * @param onLoadedCode Function Callback
     */
    loadLibrary : function(fileName, onLoadedCode){
        if(window._bootstrap && window._bootstrap.parameters.get("appVersion") && fileName.indexOf("?")==-1){
            fileName += "?v="+window._bootstrap.parameters.get("appVersion");
        }
        var path = (this._libUrl?this._libUrl+'/'+fileName:fileName);
        new Ajax.Request(path,
        {
            method:'get',
            asynchronous: false,
            onComplete:function(transport){
                if(transport.responseText) 
                {
                    try
                    {
                        var script = transport.responseText;                
                        if (window.execScript){ 
                            window.execScript( script );
                        }
                        else{
                            // TO TEST, THIS SEEM TO WORK ON SAFARI
                            window.my_code = script;
                            var script_tag = document.createElement('script');
                            script_tag.type = 'text/javascript';
                            script_tag.innerHTML = 'eval(window.my_code)';
                            document.getElementsByTagName('head')[0].appendChild(script_tag);
                        }
                        if(onLoadedCode != null) onLoadedCode();
                    }
                    catch(e)
                    {
                        alert('error loading '+fileName+':'+ e.message);
                    }
                }
                document.fire("app:server_answer");             
            }
        }); 
    }
});