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
"use strict";
Class.create("LomMetaEditor", AbstractEditor, {
    _selection:null,
    _node: null,
    _optionSetsCache: null,
    tab: null,
    spec: null,
    formManager: null,
    massive: false,
    metaTag: '',
    initialize: function($super, oFormObject){
        $super(oFormObject, {fullscreen:false});
        this.oForm = oFormObject;
        this.formManager = this.getFormManager();
        this._optionSetsCache = {};
    },
    show: function(selection){
        this.massive = selection.isMultiple();
        this._selection = selection;
        var type = 'DIGITAL_RESOURCE_OBJECT'; //By default open with the digital resource object spec
        if (!this.massive){//If it is a DCO object, then read the type from the object
            this._node = selection.getNode(0);
            if (this._node.getMime() == 'dco'){
                type = this._node.getMetadata().get('type_id');
            }
        }
        var params = new Hash();
        params.set("get_action", "get_spec_by_id");
        params.set("spec_id", type);
        var connexion = new Connexion();
        connexion.setParameters(params);
        connexion.onComplete = function(transport){
            var xmlData = transport.responseXML;
            this.createSpecEditor(xmlData);
        }.bind(this);
        connexion.sendAsync();
    },
    createSpecEditor: function(spec){
        this.spec = spec;
        this.updateHeader();
        this.tab = new SimpleTabs(this.oForm.down("#categoryTabulator"));
        var categories = XPathSelectNodes(spec, '//fields/*[@type="category"]');
        var metadata = this.massive ? null : this._node.getMetadata().get("lommetadata");
        metadata = (metadata && metadata.evalJSON())||{};
        //set available languages
        var languages = this.getOptionSet('languages');
        this.formManager.setAvailableLanguages(languages);

        $A(categories).each(function(cat){
            var pane = new Element("div");
            var catName = this.getMetaNodeTranslation(cat, 'meta.fields.');
            if (this.prepareCategoryMetaEntry(cat, pane, (metadata[cat.nodeName]||{})))
                this.tab.addTab(catName, pane);
        }.bind(this));

        this.actions.get("saveButton").observe("click", this.save.bind(this));
        modal.setCloseValidation(function(){
            if(this.isDirty()){
                var confirm = window.confirm(MessageHash["role_editor.19"]);
                if(!confirm) return false;
            }
            return true;
        }.bind(this));
        modal.setCloseAction(function(){
            this.element.select(".meta_form_container").each(function(frm){
                this.formManager.destroyForm(frm);
            }.bind(this));
        }.bind(this));

        this.oForm.observe('form:language_changing', function (e){
            if (e.memo.current == 'none') {
                var missing = this.formManager.serializeParametersInputs(this.element.down("#categoryTabulator"), new Hash(), 'DCO_');
                if(missing){
                    app.displayMessage("ERROR", MessageHash['meta_lom.missing_fields']);
                    Event.stop(e);
                }
            }
        }.bind(this));
        this.setClean();
        this.refreshActionsToolbar();
    },
    refreshActionsToolbar: function(){
        var publishButton = this.actions.get("publishButton");

        if (this.massive){
            publishButton.hide();
            return;
        }

        var meta = this._node.getMetadata();
        var status = meta.get('status_id'),
            lastupdated = meta.get('lastupdated'),
            lastpublished = meta.get('lastpublished');

        publishButton.stopObserving('click');
        if (status == null || status == undefined || status == 'inprogress' || status == 'published' && lastpublished && lastpublished < lastupdated) {
            publishButton.observe("click", this.publish.bind(this));
            publishButton.removeClassName("disabled");
            var statusText = MessageHash["meta_lom.pending_to_publish"];
            if (lastpublished){
                var otherText = MessageHash["meta_lom.last_published"];
                otherText = otherText.replace('[DATE]', moment(lastpublished).format('MMMM D, YYYY hh:mm a'));
                statusText += ' ' + otherText;
                this.element.down('span.header_sublabel').update('<i class="fa-exclamation-sign" style="font-size:16px;color:#ffff00;padding:0 4px 0 0"/>');
                this.element.down('span.header_sublabel').insert(statusText);
            }
        }
    },
    updateHeader: function(){
        var headerLabel = this.element.down("span.header_label");
        if (this.massive){
            headerLabel.update("Asignar metadatos"); //MessageHash["meta_lom.massivemetadata"]
            var icon = resolveImageSource("dco.png", "/images/mimes/64");
            this.element.down("span.header_label").setStyle({
                    backgroundImage:"url('"+icon+"')",
                    backgroundSize : '34px'
                });
            return;
        }
        var meta = this._node.getMetadata();
        this.element.down("span.header_label").update(meta.get("text"));
        var icon = resolveImageSource(this._node.getIcon(), "/images/mimes/64");
        this.element.down("span.header_label").setStyle({
                backgroundImage:"url('"+icon+"')",
                backgroundSize : '34px'
            });
        var statusText = meta.get('status');
        var lastPublished = meta.get('lastpublished');
        if (lastPublished){
            statusText += moment(lastPublished).format(" (YYYY-MM-DD hh:mm a)");
        }
        this.element.down('span.header_sublabel').update(statusText);
    },
    /**
     * Process a metadata category to prepare form entry fields for it.
     * @param category xmlNode|null
     * @param container Element|null
     */
    prepareCategoryMetaEntry: function(category, container, metadata){
        var form = new Element("div", {className:'meta_form_container'});
        var dicprefix = 'meta_lom.setup.table.col.';
        var fields = new $A([]);
        var values = new Hash();
        $A(category.children).each(function(field){
            var fieldSettings = this.prepareMetaFieldEntry(field, form, 1, 'meta.fields.'+category.nodeName+'.', metadata, values);
            if (!fieldSettings) return;
            if (fieldSettings.length){
                for(var k=0; k<fieldSettings.length; k++){
                    fields.push(fieldSettings[k]);
                }
            }
            else{
                fields.push(fieldSettings);
            }
        }.bind(this));

        if (fields.length > 0){
            container.insert(form);
            form.paneObject = this;
            this.formManager.createParametersInputs(form, fields, true, values, null, true, this.massive);
            this.formManager.observeFormChanges(form, this.onFormChanges.bind(this));
            return true;
        }
        return false;
    },
    onFormChanges: function(event){

        this.setDirty();
        if (!event || !event.target) return;
        var el = $(event.target);
        if (!el || !el.match('.SF_input,.form-control')) return;
        var name = el.name;
        this.oForm.select('select[data-dependencies]').each(function(select){
            if (select.name == name) return;
            var dependencies = select.retrieve('dependencies');
            var dep = dependencies && dependencies.get(name);
            if (!dep) return;

            dep.value = el.value;
            dependencies.set(name, dep);
            select.store('dependencies', dependencies);
            var createOptions = select.retrieve('createOptions');
            var getChoices = select.retrieve('getChoices');
            if (Object.isFunction(createOptions) && Object.isFunction(getChoices)){
                var choices = getChoices(dependencies);
                select.update(createOptions(choices));
            }
        });

    },

    /**
     * Process a metadata field to prepare form entry fields for it
     * @param field xmlNode|null
     * @param form Element|null Target form where to insert the meta entry fields
     */
    prepareMetaFieldEntry: function(field, container, level, dicprefix, metadata, values){
        var type = field.getAttribute('type');
        var enabled = field.getAttribute('enabled');
        if (enabled !== 'true') return;

        var isContainer = type == 'container';
        var fname = field.nodeName;
        var name = isContainer?dicprefix.replace(/\.$/, ''):dicprefix+fname;
        var label = this.getMetaNodeTranslation(isContainer?name:field.nodeName, isContainer?'':dicprefix);
        level = level || 1;
        var options = null;
        if (type=='composed' || isContainer){
            var result = [$H({
                type:'label',
                label: label,
                name: name,
                description: field.firstChild!=null&&field.firstChild.nodeType==field.firstChild.TEXT_NODE?field.firstChild.wholeText.trim():"",
                isMassive: this.massive
            })];
            var isCollection = field.getAttribute("collection") === 'true';
            var isRequired = field.getAttribute("required") === 'true';
            var isFixed = field.getAttribute("fixed") === 'true';

            var nameSuffix = isContainer ? '' : fname+'.';
            var data = isContainer ? metadata : metadata[fname];

            // Load default values if metadata is empty or not has some property.
            if (isCollection){
                var value = field.getAttribute('defaultValue');
                if (value) {
                    var defaultdata = JSON.parse(value)||[];

                    if (Object.isArray(defaultdata)) {
                        var initialize = false;
                        if (!data) {
                            data = [];
                            data[0] = {};
                            initialize = true;
                        }

                        defaultdata.forEach(item => {
                            var keys = Object.entries(item);
                            if (Object.isArray(keys)) {
                                keys.forEach(([prop, v]) => {
                                    if (initialize) {
                                        data[0][prop] = v.default;
                                    }
                                    else {
                                        data.forEach(item2 => {

                                            if (!(prop in item2) || !item2[prop]) {
                                                item2[prop] = v.default;
                                            }
                                        });
                                    }
                                });
                            }
                        });
                    }

                }
             }

            $A(field.children).each(function(child){
                options = this.prepareMetaFieldEntry(child, container, level+1, dicprefix+nameSuffix, (data||{}), values);
                if (!options) return;
                options.set('replicationGroup', name);
                options.set('groupRequired', isRequired);
                options.set('groupFixed', isFixed);
                if (!isCollection){
                    options.set('replicatable', false);
                }
                result.push(options);
            }.bind(this));
            return result;
        }
        else {
            options = {
                text: label,
                description: field.firstChild!=null?field.firstChild.wholeText.trim():"",
                name: name,
                type: "string"
            };
            if (Array.isArray(metadata)){
                for(var i=0; i < metadata.length; i++){
                    if (i > 0 && /duration|vcard/.test(type)) {
                        $A(Object.keys(metadata[i][fname])).each(function(key){
                            values.set(name+'.'+key+(i==0?'':'_'+i),metadata[i][fname][key]);
                        });
                    }
                    else if (metadata[i].hasOwnProperty(fname)){
                        values.set(name+(i==0?'':'_'+i),metadata[i][fname]);
                    }
                }
            }
            else if (metadata != undefined && metadata.hasOwnProperty(fname)){
                values.set(name, metadata[fname]);
            }
            return $H(Object.extend(options, this.getControlSettings({type:type, meta:field, text: label, name: name, values: values})));
        }
    },

    getMetaNodeTranslation: function(key, prefix, type){
        var key = key.nodeName?key.nodeName:key;
        type = type || 'label';
        return this.getMetaTranslation(key+'.'+type, prefix);
    },

    getMetaTranslation: function(key, prefix){
        prefix = ('meta_lom.' + (prefix || '')).replace(/\.$/, '');
        var text = MessageHash[prefix+'.'+key];
        return text||key;
    },

    /**
     * Create a Form control with specific options
     * @param options object|null, key value pair properties to create the form control
     */
    getControlSettings: function(options){
        var settings = {};
        settings.mandatory = options.meta.getAttribute('required');
        settings.readonly = options.meta.getAttribute('editable') === "false";
        settings.defaultValue = "";
        settings.label = options.text;
        settings.translatable = options.meta.getAttribute('translatable') === 'true';
        settings.isMassive = this.massive;

        if (settings.translatable){
            settings.languages = this.getOptionSet('languages');
        }
        switch(options.type){
            case 'checkbox':
                settings.type = 'checkbox';
                break;
            case 'keywords':
                settings.type = 'keywords';
                break;
            case 'text':
            case 'string':
            case null:
                settings.type = 'string';
                break;
            case 'longtext':
                settings.type = 'textarea';
                break;
            case 'label':
                settings.type = 'string';
                break;
            case 'date':
            case 'datetime':
                settings.type = options.type;
                break;
            case 'duration':
                settings.type = 'duration';
                break;
            case 'int':
                settings.type = 'integer';
                break;
            case 'optionset':
                settings.type = 'select';
                settings.multiple = options.meta.getAttribute('multiple') === "true";
                var choices = [];
                var optionsetname=options.meta.getAttribute('optionset-name');
                settings.optionsetname = optionsetname;
                //Set the option set name based on the value of another field in the collection
                if (/\{(.*?)\}/.test(optionsetname)) {
                    var dependencies = {};
                    var matches = optionsetname.match(/\{(.*?)\}/g);
                    for(var i = 0; i < matches.length; i++){
                        var ph = options.name.split('.').slice(0,-1).join('.')+'.'+matches[i].slice(1, -1);
                        dependencies[ph] = { ph: matches[i], value: null };
                        if (options.values && options.values.get(ph)){
                            dependencies[ph].value = options.values.get(ph);
                        }
                    }
                    settings.dependencies = $H(dependencies);
                    settings.choices = function(dependencies){
                        var optionsetname = settings.optionsetname;
                        dependencies.each(function(dep){
                            optionsetname = optionsetname.replace(dep.value.ph, dep.value.value);
                        });
                        return this.getOptions(optionsetname);
                    }.bind(this);
                }
                else{
                    settings.choices = this.getOptions(optionsetname);
                }
                break;
            default:
                var type = XPathSelectSingleNode(this.spec, '//types/type[@name="'+options.type+'"]')
                if (type != null){
                    var childSettings = [];

                    $A(type.children).each(function(it){
                        var options = {
                            label: MessageHash[it.getAttribute("labelId")] || it.getAttribute("labelId"),
                            name: it.nodeName,
                            type: it.getAttribute("type"),
                            mandatory: it.getAttribute('required')
                        };
                        childSettings.push($H(options));
                    }.bind(this));
                    settings.type = 'composed';
                    settings.typeName = options.type;
                    settings.childs = childSettings;
                }
                else
                    return {};
        }
        return settings;
    },
    getOptions: function(optionsetname){
        var optionset = this.getOptionSet(optionsetname);
        var choices = [];
        $A(Object.keys(optionset)).each(function(choice){
            choices.push(choice+"|"+optionset[choice]);
        });
        return choices;
    }
    ,
    setDirty: function(){
        this.actions.get("saveButton").removeClassName("disabled");
    },

    setClean: function(){
        this.actions.get("saveButton").addClassName("disabled");
    },

    isDirty: function(){
        return !this.actions.get("saveButton").hasClassName("disabled");
    },
    getFormManager: function(){
        return new FormManager(this.element.down(".tabpanes"));
    },
    getSpecId: function(){
        return XPathSelectSingleNode(this.spec, "/spec/id").firstChild.nodeValue;
    },
    save: function(){
        if(!this.isDirty()) return;

        var toSubmit = new Hash();
        toSubmit.set("action", "save_dcometa");
        toSubmit.set("plugin_id", 'meta.lom');
        toSubmit.set("dir", app.getContextNode().getPath());
        toSubmit.set('spec_id', this.getSpecId());
        toSubmit.set('mode', this.massive?'massive':'single');
        var missing = this.formManager.serializeParametersInputs(this.element.down("#categoryTabulator"), toSubmit, 'DCO_');
        if(missing){
            app.displayMessage("ERROR", MessageHash['boaconf.36']);
        }else{
            var i = 0;
            var selectedNodes = this._selection.getSelectedNodes();
            selectedNodes.each(function(node){
                toSubmit.set("file", node.getPath());
                var conn = new Connexion();
                conn.setParameters(toSubmit);
                conn.setMethod("post");
                conn.onComplete = function(transport){
                    this.updateNodeMetadata(node, transport.responseJSON || {});
                    --i;
                }.bind(this);
                i++;
                conn.sendAsync();
            }.bind(this));
            var tw = 100;
            var check = function(){
                if (i > 0) {
                    setTimeout(check, tw);
                    return;
                }
                this.setClean();
                this.refreshActionsToolbar();
                this.actions.get("closeButton").click();
                alert(MessageHash['meta_lom.18']);
            }.bind(this);
            check();
        }
    },
    publish: function(){
        if (this.isDirty()){
            app.displayMessage("ERROR", MessageHash['meta_lom.save_required']);
            return;
        }
        var toSubmit = new Hash();
        var missing = this.formManager.serializeParametersInputs(this.element.down("#categoryTabulator"), toSubmit, 'DCO_');
        if(missing){
            app.displayMessage("ERROR", MessageHash['boaconf.36']);
        }else{
            if (!window.confirm(MessageHash["meta_lom.publish_confirmation"])) return;
            toSubmit = new Hash();
            toSubmit.set("action", "publish_metadata");
            toSubmit.set("plugin_id", 'meta.lom');
            toSubmit.set("dir", app.getContextNode().getPath());
            toSubmit.set("file", this._node.getPath());
            toSubmit.set('spec_id', this.getSpecId());
            app.actionBar.submitForm(this.oForm);

            var conn = new Connexion();
            conn.setParameters(toSubmit);
            conn.setMethod("post");
            conn.onComplete = function(transport){
                if (transport.responseJSON){
                    var publishButton = this.actions.get("publishButton");
                    publishButton.addClassName("disabled");
                    publishButton.stopObserving('click');
                    var data = transport.responseJSON;
                    var meta = this._node.getMetadata();
                    meta.set('status_id', data.status_id);
                    meta.set('status', data.status);
                    meta.set('lastpublished', data.lastpublished);
                    meta.set('manifest', transport.responseText);
                    this.updateHeader();
                    var overlay = meta.get('overlay_icon');
                    if (overlay){
                        if (/(,?)alert\.png/.test(overlay)){
                            overlay = overlay.replace(/(,?)alert\.png/, '$1ok.png');
                        }
                        else {
                            overlay += ',ok.png';
                        }

                    }
                    else {
                        overlay = 'ok.png';
                    }
                    meta.set('overlay_icon', overlay);
                    this._node.notify('node_replaced', this._node);
                }
                else {
                    app.actionBar.parseXmlMessage(transport.responseXML);
                }
                this.setClean();
                //hideLightBox(true);
            }.bind(this);
            conn.sendAsync();
        }

    },
    getOptionSet: function(optionsetname){
        if (this._optionSetsCache[optionsetname]){
            return this._optionSetsCache[optionsetname];
        }
        //var optionsetname=options.meta.getAttribute('optionset-name');
        var optionset = XPathSelectSingleNode(this.spec, '//optionsets/optionset[@name="'+optionsetname+'"]');
        var choices = [];

        if (optionset){
            $A(optionset.getAttribute('values').split('||')).each(function(set){
                if (/::/.test(set)){
                    var parts = set.split('::');
                    choices["_grp_"+parts[0]] = this.getMetaTranslation(parts[0], 'optionset.'+optionsetname);
                    set = parts[1];
                }
                $A(set.split('|')).each(function(choice){
                    choices[choice] = this.getMetaTranslation(choice, 'optionset.'+optionsetname);
                }.bind(this));
            }.bind(this));
        }
        return (this._optionSetsCache[optionsetname] = choices);
    },
    updateNodeMetadata: function(node, data){
        var meta = node.getMetadata();
        meta.set('lommetadata', JSON.stringify(data.metadata||{}));
        meta.set('lastupdated', data.manifest && data.manifest.lastupdated);
        var overlay = meta.get('overlay_icon');
        if (overlay){
            if (/(,?)(alert|ok)\.png/.test(overlay)){
                overlay = overlay.replace(/(,?)(alert|ok)\.png/, '$1alert.png');
            }
            else {
                overlay += ',alert.png';
            }
        }
        else {
            overlay = (meta.get('is_file')?'dro.png,':'')+'alert.png';
        }
        meta.set('overlay_icon', overlay);
        node.notify('node_replaced', node);
    }
});
