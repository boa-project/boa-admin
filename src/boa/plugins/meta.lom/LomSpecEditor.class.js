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
Class.create("LOMSpecEditor", {
    tabName: null,
    tab : null,
    repositoryId: null,
    formManager : null,
    infoPane: null,
    docPane: null,
    metaPane:null,
    element: null,
    metadata: null,

    initialize: function(oContainer, tabName)
    {
        this.tabName = tabName;
        this.element = $(oContainer);
        this.metaPane = this.element.down(".metaEntry");
        /*$super(oFormObject, {fullscreen:false});
        fitHeightToBottom(this.element.down("#pluginTabulator"), this.element.up(".dialogBox"));
        this.contentMainContainer = this.element.down("#pluginTabulator");
        // INIT TAB
        var infoPane = this.element.down("#pane-infos");
        var docPane = this.element.down("#pane-docs");

        infoPane.setStyle({position:"relative"});
        infoPane.resizeOnShow = function(tab){
            fitHeightToBottom(infoPane, $("plugin_edit_box"), Prototype.Browser.IE ? 40 : 0);
        }
        docPane.resizeOnShow = function(tab){
            fitHeightToBottom(docPane, $("plugin_edit_box"), Prototype.Browser.IE ? 40 : 0);
        }
        this.tab = new SimpleTabs(oFormObject.down("#pluginTabulator"));
        this.actions.get("saveButton").observe("click", this.save.bind(this) );
        modal.setCloseValidation(function(){
            if(this.isDirty()){
                var confirm = window.confirm(MessageHash["role_editor.19"]);
                if(!confirm) return false;
            }
            return true;
        }.bind(this) );
        modal.setCloseAction(function(){
            this.formManager.destroyForm(this.infoPane.down("div.driver_form"));
        }.bind(this));
        oFormObject.down(".action_bar").select("a").invoke("addClassName", "css_gradient");
        this.infoPane = infoPane;
        this.docPane = docPane;*/
        this.loadPluginConfig();
    },

    save : function(){
        /*if(!this.isDirty()) return;

        var toSubmit = new Hash();
        toSubmit.set("action", "edit");
        toSubmit.set("sub_action", "edit_plugin_options");
        toSubmit.set("plugin_id", this.pluginId);
        var missing = this.formManager.serializeParametersInputs(this.infoPane.down("div.driver_form"), toSubmit, 'DRIVER_OPTION_');
        if(missing){
            app.displayMessage("ERROR", MessageHash['boaconf.36']);
        }else{
            var conn = new Connexion();
            conn.setParameters(toSubmit);
            conn.setMethod("post");
            conn.onComplete = function(transport){
                app.actionBar.parseXmlMessage(transport.responseXML);
                this.loadPluginConfig();
                this.setClean();
            }.bind(this);
            conn.sendAsync();
        }

        */
    },

    open : function($super, node){
        /*$super(node);
        this.pluginId = getBaseName(node.getMetadata().get("plugin_id"));
        this.element.down("span.header_label").update(node.getMetadata().get("text"));
        var icon = resolveImageSource(node.getIcon(), "/images/mimes/64");
        this.element.down("span.header_label").setStyle(
            {
                backgroundImage:"url('"+icon+"')",
                backgroundSize : '34px'
            });
        this.node = node;
        this.formManager = this.getFormManager();
        this.loadPluginConfig();*/
    },

    loadPluginConfig2 : function(){
        var $this = this;
        var params = new Hash();
        params.set("get_action", "get_specs_list");
        params.set("plugin_id", 'meta.lom');
        var connexion = new Connexion();
        connexion.setParameters(params);
        connexion.onComplete = function(transport){
            console.log('getting response');
            var specs = transport.responseJSON;
        }.bind(this);
        connexion.sendAsync();
    },

    loadPluginConfig : function(){
        var $this = this;
        var params = new Hash();
        params.set("get_action", "get_plugin_manifest");
        params.set("plugin_id", 'meta.lom');
        var connexion = new Connexion();
        connexion.setParameters(params);
        connexion.onComplete = function(transport){

            var xmlData = transport.responseXML;
            //var params = XPathSelectNodes(xmlData, "//global_param");
            //var values = XPathSelectNodes(xmlData, "//plugin_settings_values/param");
            //var documentation = XPathSelectSingleNode(xmlData, "//plugin_doc");
            var config = XPathSelectSingleNode(xmlData, '//config_tab[@name="'+$this.tabName+'"]');
            var metadata = XPathSelectSingleNode(config, '//metadata');
            $this.metadata = metadata;
            var categories = XPathSelectNodes(metadata, '//fields/*[@type="category"]');
            $A(categories).each(function(cat){
                $this.prepareCategoryMetaEntry(cat, $this.metaPane);
            });

            $this.metaPane.SF_accordion = new accordion($this.metaPane, {
                classNames : {
                    toggle : 'accordion_toggle',
                    toggleActive : 'accordion_toggle_active',
                    content : 'accordion_content'
                },
                defaultSize : {
                    width : '360px',
                    height: null
                },
                direction : 'vertical'
            });
        }.bind(this);
        connexion.sendAsync();
        /*    var paramsValues = new Hash();
            $A(values).each(function(child){
                if(child.nodeName != 'param') return;
                if(child.getAttribute("cdatavalue")){
                    paramsValues.set(child.getAttribute("name"), child.firstChild.nodeValue);
                }else{
                    paramsValues.set(child.getAttribute('name'), child.getAttribute('value'));
                }
            });


            var driverParamsHash = $A([]);
            if(this.pluginId.split("\.")[0] != "core"){
                driverParamsHash.push($H({
                    name:'APP_PLUGIN_ENABLED',
                    type:'boolean',
                    label:MessageHash['boaconf.104'],
                    description:""
                }));
            }
            for(var i=0;i<params.length;i++){
                var hashedParams = this.formManager.parameterNodeToHash(params[i]);
                driverParamsHash.push(hashedParams);
            }
            var form = new Element('div', {className:'driver_form'});

            this.infoPane.insert({bottom:form});
            form.paneObject = this;

            if(driverParamsHash.size()){
                this.formManager.createParametersInputs(form, driverParamsHash, true, (paramsValues.size()?paramsValues:null));
            }else{
                form.update(MessageHash['boaconf.105']);
            }

            if(form.SF_accordion){
                form.SF_accordion.openAll();
                var toggles = form.select(".accordion_toggle");
                toggles.invoke("removeClassName", "accordion_toggle");
                toggles.invoke("removeClassName", "accordion_toggle_active");
                toggles.invoke("addClassName", "innerTitle");
            }
            this.formManager.observeFormChanges(form, this.setDirty.bind(this));


            app.blurAll();
        }.bind(this);
        connexion.sendAsync();*/
    },

    /**
     * Process a metadata category to prepare form entry fields for it.
     * @param category xmlNode|null
     * @param container Element|null
     */
    prepareCategoryMetaEntry: function (category, container){
        var $this = this;
        var catName = $this.getMetaNodeTranslation(category, 'meta.fields.');// MessageHash['meta_lom.'+category.nodeName+'.title'];
        var title = new Element('div',{className:'accordion_toggle', tabIndex:0}).update("<span class=\"title\">"+catName+"</span>");
        var accordionContent = new Element("div", {className:"accordion_content", style:"padding-bottom: 10px;"});
        var form = new Element("div", {className:'meta_form_container'});
        var table = new Element("table", {className: 'meta_form_table', cellspacing:"0", cellpadding:"0"});
        var hrow = new Element("tr");
        var tbody = new Element("tbody");
        var dicprefix = 'meta_lom.setup.table.col.'
        table.insert(new Element("thead").insert(hrow));

        hrow.insert(new Element("th").update(MessageHash[dicprefix+"metadata.header"])); //Metadata name
        hrow.insert(new Element("th").update(MessageHash[dicprefix+"inuse.header"])); //InUse?
        hrow.insert(new Element("th").update(["<span title='", MessageHash[dicprefix+"visible.title"], "'>&nbsp;</span>"].join(''))); //Visible?
        hrow.insert(new Element("th").update(["<span title='", MessageHash[dicprefix+"editable.title"], "'>&nbsp;</span>"].join(''))); //Editable?
        hrow.insert(new Element("th").update(["<span title='", MessageHash[dicprefix+"required.title"], "'>&nbsp;</span>"].join(''))); //Required?
        hrow.insert(new Element("th").update(MessageHash[dicprefix+"aka.header"])); //Metadata Alias
        hrow.insert(new Element("th").update(MessageHash[dicprefix+"defaultValue.header"])); //Default Value
        hrow.insert(new Element("th").update(MessageHash[dicprefix+"help.header"])); //Help
        hrow.insert(new Element("th").update(MessageHash[dicprefix+"lomhelp.header"])); //LOM Help

        table.insert(tbody);
        form.insert(table);


        $A(category.children).each(function(field){
            $this.prepareMetaFieldEntry(field, tbody, 1, 'meta.fields.'+category.nodeName+'.');
        });

        accordionContent.insert(form);
        container.insert({bottom: title});
        container.insert({bottom: accordionContent});
    },

    /**
     * Process a metadata field to prepare form entry fields for it
     * @param field xmlNode|null
     * @param form Element|null Target form where to insert the meta entry fields
     */
    prepareMetaFieldEntry(field, container, level, dicprefix){
        var $this = this;
        var type = field.getAttribute('type');
        var fname = field.nodeName;
        level = level || 1;

        if (type=='composed'){
            //var composedContainer = new Element('div', {className:''}).update("<span>"+field.nodeName+"</span>");
            var row = new Element('tr');
            row.insert(new Element('td', {colspan:9}).update($this.getMetaNodeTranslation(field, dicprefix)));
            container.insert(row);
            $A(field.children).each(function(child){
                $this.prepareMetaFieldEntry(child, container, level+1, dicprefix+fname+'.');
            });
        }
        else {
            var row = new Element('tr');
            row.insert(new Element('td', {className: 'level'+level}).insert(this.createFormControl({type:'label', meta:field, text: $this.getMetaNodeTranslation(field, dicprefix)})));
            row.insert(new Element('td', {className: 'text-center'}).insert(this.createFormControl({type:'checkbox', meta:field, name:fname+'_inuse'})));
            row.insert(new Element('td', {className: 'text-center'}).insert(this.createFormControl({type:'checkbox', meta:field, name:fname+'_visible'})));
            row.insert(new Element('td', {className: 'text-center'}).insert(this.createFormControl({type:'checkbox', meta:field, name:fname+'_editable'})));
            row.insert(new Element('td', {className: 'text-center'}).insert(this.createFormControl({type:'checkbox', meta:field, name:fname+'_required'})));
            row.insert(new Element('td', {className: ''}).insert(this.createFormControl({type:'text', meta:field, name:fname+'_aka'})));
            row.insert(new Element('td', {className: ''}).insert(this.createFormControl({type:type, meta:field, name:fname+'_defaultValue'})));
            row.insert(new Element('td', {className: ''}).insert(this.createFormControl({type:'longtext', meta:field, name:fname+'_help'})));
            row.insert(new Element('td', {className: ''}).insert(this.createFormControl({type:'label', meta:field, text:$this.getMetaNodeTranslation(field, dicprefix, 'help')})));

            container.insert(row);
        }
    },

    getMetaNodeTranslation(key, prefix, type){
        var key = key.nodeName?key.nodeName:key;
        type = type || 'label';
        return this.getMetaTranslation(key+'.'+type, prefix);
    },

    getMetaTranslation(key, prefix){
        prefix = ('meta_lom.' + (prefix || '')).replace(/\.$/, '');
        var text = MessageHash[prefix+'.'+key];
        return text||key;
    },

    /**
     * Create a Form control with specific options
     * @param options object|null, key value pair properties to create the form control
     */
    createFormControl(options){
        var $this = this;
        var ctrl;
        switch(options.type){
            case 'checkbox':
                ctrl = new Element('input', {type:'checkbox', className:"SF_fieldCheckBox", name: options.name});
                ctrl.checked = options.defaultValue?true:false;
                break;
            case 'text':
                ctrl = new Element('input', {type:'text', className:"SF_Input", name: options.name});
                ctrl.checked = options.defaultValue?true:false;
                break;
            case 'longtext':
                ctrl = new Element('textarea', {type:'text', className:"SF_Input", name: options.name});
                //element = '<textarea class="SF_input" style="height:70px;" data-ctrl_type="'+type+'" data-mandatory="'+(mandatory?'true':'false')+'" name="'+name+'"'+disabledString+'>'+defaultValue+'</textarea>'
                ctrl.checked = options.defaultValue?true:false;
                break;
            case 'label':
                ctrl = new Element('span').update(options.text);
                break;
            case 'date':
                ctrl = new Element('input', {type:'date', className:"SF_Input", name: options.name});
                break;
            case 'optionset':
                var multiple = options.multiple?'multiple="true"':'';
                var ctrl = new Element('select', {className:'SF_input', name:options.name, 'data-mandatory':options.mandatory?true:false});
                if (multiple) ctrl.multiple = true;

                var choices = '';
                if(!options.mandatory && !multiple) choices += '<option value=""></option>';
                var optionsetname=options.meta.getAttribute('optionset-name');
                var optionset = XPathSelectSingleNode(this.metadata, '//optionsets/optionset[@name="'+optionsetname+'"]');

                if (optionset){
                    $A(optionset.getAttribute('values').split('|')).each(function(choice){
                        choices += '<option value="'+choice+'">'+$this.getMetaTranslation(choice, 'optionset.'+optionsetname)+'</option>';
                    });
                }
                ctrl.update(choices);
                break;
        }
        return ctrl;
    },

    /**
     * Resizes the main container
     * @param size int|null
     */
    resize : function(size){
        /*if(size){
            this.contentMainContainer.setStyle({height:size+"px"});
        }else{
            fitHeightToBottom(this.contentMainContainer, this.element.up(".dialogBox"));
            this.tab.resize();
        }
        this.element.fire("editor:resize", size);*/
    },

    setDirty : function(){
        //this.actions.get("saveButton").removeClassName("disabled");
    },

    setClean : function(){
        //this.actions.get("saveButton").addClassName("disabled");
    },

    isDirty : function(){
        //return !this.actions.get("saveButton").hasClassName("disabled");
    },

    getFormManager : function(){
        //return new FormManager(this.element.down(".tabpanes"));
    },

    updateBinaryContext : function(parameter){
        /*if(this.roleData.USER){
            parameter.set("binary_context", "user_id="+this.roleId.replace("APP_USR_/", ""));
        }else if(this.roleData.GROUP){
            parameter.set("binary_context", "group_id="+this.roleId.replace("APP_GRP_/", ""));
        }else{
            parameter.set("binary_context", "role_id="+this.roleId);
        }*/
    },

    mergeObjectsRecursive : function(source, destination){
        /*var newObject = {};
        for (var property in source) {
            if (source.hasOwnProperty(property)) {
                if( source[property] === null ) continue;
                if( destination.hasOwnProperty(property)){
                    if(source[property] instanceof Object && destination instanceof Object){
                        newObject[property] = this.mergeObjectsRecursive(source[property], destination[property]);
                    }else{
                        newObject[property] = destination[property];
                    }
                }else{
                    if(source[property] instanceof Object) {
                        newObject[property] = this.mergeObjectsRecursive(source[property], {});
                    }else{
                        newObject[property] = source[property];
                    }
                }
            }
        }
        for (var property in destination){
            if(destination.hasOwnProperty(property) && !newObject.hasOwnProperty(property) && destination[property]!==null){
                if(destination[property] instanceof Object) {
                    newObject[property] = this.mergeObjectsRecursive(destination[property], {});
                }else{
                    newObject[property] = destination[property];
                }
            }
        }
        return newObject;*/
    },

    encodePassword : function(password){
        /*// First get a seed to check whether the pass should be encoded or not.
        var sync = new Connexion();
        var seed;
        sync.addParameter('get_action', 'get_seed');
        sync.onComplete = function(transport){
            seed = transport.responseText;
        };
        sync.sendSync();
        var encoded;
        if(seed != '-1'){
            encoded = hex_md5(password);
        }else{
            encoded = password;
        }
        return encoded;*/

    }


});
