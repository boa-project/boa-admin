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
 * Description : Static class for renderers
 */
Class.create("LomMetaCellRenderer", {	
	initialize: function(){
    console.log('initializing...');
		this.cssList = new Hash({
			'low': {cssClass:'meta_low', label:MessageHash['meta_lom.4'], sortValue:'5'},
			'todo' : {cssClass:'meta_todo', label:MessageHash['meta_lom.5'], sortValue:'4'},
			'personal' : {cssClass:'meta_personal', label:MessageHash['meta_lom.6'], sortValue:'3'},
			'work' : {cssClass:'meta_work', label:MessageHash['meta_lom.7'], sortValue:'2'},
			'important' : {cssClass:'meta_important', label:MessageHash['meta_lom.8'], sortValue:'1'}
		});
		var head = $$('head')[0];
		var href = "plugins/meta.lom/css/labelsClasses.css";
		if(!head.down('link[href="'+href+'"]')){
			var cssNode = new Element('link', {
				type : 'text/css',
				rel  : 'stylesheet',
				href : href,
				media : 'screen'
			});
			head.insert(cssNode);
		}
	},
	
	/* LABELS SYSTEM */
	cssLabelsFilter : function(element, node, type, nodeObject){
        if(!element && nodeObject){
            var content = node.getMetadata().get('css_label');
            if(content){
                var obj = new MetaCellRenderer();
                var rule = obj.findCssRule(content.strip());
                if(rule){
                    nodeObject.addClassName(rule.cssClass);
                }
            }
        }else if(type == 'row'){
			try{
				var span = element.down('span');
				var content = span.innerHTML;
			}catch(e){
			}
			if(content){
				var obj = new MetaCellRenderer();
				var rule = obj.findCssRule(content.strip());
				if(rule){
					element.up().addClassName(rule.cssClass);					
					span.update(rule.label);
					element.writeAttribute("data-sorter_value", rule.sortValue);
				}
			}
		}else if(type =='thumb'){
			var content = node.getMetadata().get('css_label');
			if(content){
				var obj = new MetaCellRenderer();
				var rule = obj.findCssRule(content.strip());
				if(rule){
					element.addClassName(rule.cssClass);
				}
			}			
		}
	},
	
	formPanelCssLabels: function(formElement, form){
		var value = formElement.value;
		var obj = new MetaCellRenderer();
		var hidden = new Element('input', {type:'hidden', name:formElement.name, value:formElement.value});
		form.insert(hidden);
		var cssList = obj.cssList;
		var selector = new Element('select', {style:"width:120px;height:20px;"});
		selector.insert(new Element('option', {
			name:'',
			value:'', 
			selected:(!value)
		}).update(MessageHash['meta_lom.2']));
		cssList.each(function(pair){
			var option = new Element('option', {
				name:pair.key,
				value:pair.key, 
				selected:(value == pair.key),
				className:pair.value.cssClass				
			}).update(pair.value.label);
			selector.insert(option);
		});
		formElement.replace(selector);
		selector.observe("change", function(){
			hidden.value = selector.getValue();
		});
	},
	
	findCssRule : function(value){
		return this.cssList.get(value);
	},
	
	/* STARS RATE SYSTEM */
	starsRateFilter: function(element, node, type){
		if(type == 'thumb') return;
        if(!element) return;
		var value = 0;
		try{
			var content = element.select('span')[0].innerHTML;
		}catch(e){
		}
		if(content) value = parseInt(content);
		var obj = new MetaCellRenderer();
		if(element.down('span.text_label')){
			var div = obj.createStars(value);
			div.setStyle({width:'70px'});
			element.down('span.text_label').update(div);
		}else{
			element.update(obj.createStars(value));	
		}
		element.writeAttribute("data-sorter_value", value);
	},
	
	infoPanelModifier : function(htmlElement){
        var obj = new MetaCellRenderer();
        htmlElement.select('[data-metatype]').each(function(td){
            var metaType = td.readAttribute("data-metatype");
            switch(metaType){
                case "stars_rate":
                    var value = parseInt(td.innerHTML);
                    td.update(this.createStars(value));
                break;
                case "css_label":
                    var value = td.innerHTML.strip();
                    var rule = this.findCssRule(value);
                    if(rule){
                        td.addClassName(rule.cssClass);
                        td.update(rule.label);
                    }
                break;
                case "text":
                case "textarea":
                    if(typeof td.contentEditable != 'undefined'){
                        enableTextSelection(td);
                        var editableDiv = new Element("div", {
                            contentEditable:"true",
                            title : "Click to edit inline",
                            style:"min-height:16px;float:left;width:86%;cursor:pointer;"}).update(td.innerHTML);
                        td.update(editableDiv);
                        obj.linkEditableDiv(editableDiv);
                    }
                break;
                default:
                break;
            }
        }.bind(obj));
	},

    linkEditableDiv : function(div){
        div.saver = new Element("img", {src:"plugins/gui.ajax/res/themes/umbra/images/actions/22/dialog_ok_apply.png"}).setStyle({
            float:"left",
            width: "22px",
            height:"22px",
            cursor:"pointer",
            border:"none"
        });
        div.saver.observe("click", function(){
            if(div.saver.removerTimeout){
                window.clearTimeout(div.saver.removerTimeout);
            }
            var selectedNode = app.getUserSelection().getUniqueNode();
            var conn = new Connexion();
            conn.setMethod("POST");
            conn.setParameters(new Hash({
                get_action  : 'edit_lom_meta',
                file	    : selectedNode.getPath()
            }));
            conn.addParameter(id, div.textContent);
            conn.onComplete = function(){
                div.saver.remove();
                app.enableAllKeyBindings();
                app.fireNodeRefresh(selectedNode);
            };
            conn.sendAsync();
        });

        div.observe("focus", function(event){
            var source = event.target;
            id = source.up("td").id.substring(3);
            source.insert({after:source.saver});
            app.disableAllKeyBindings();
            window.setTimeout(function(){
                document.observeOnce("click", function(clickEvent){
                    if(clickEvent.target != source) source.blur();
                });
            }, 500);
        }).observe("blur", function(event){
            app.enableAllKeyBindings();
            event.target.saver.removerTimeout = window.setTimeout(function(){
                event.target.saver.remove();
            }, 500);
        });

    },

	formPanelStars: function(formElement, form){
		var value = formElement.value;
		var obj = new MetaCellRenderer();
		var div = obj.createStars(value, form);
		div.setStyle({paddingTop:3});
		formElement.replace(div);
		form.insert(new Element('input', {type:'hidden',name:'stars_rate',value:value}));
	},
		
	createStars : function(value, containingForm){
		var imgOff = 'plugins/meta.lom/rating_off.png';
		var imgOn = 'plugins/meta.lom/rating.png';
		var imgRemove = 'plugins/meta.lom/rating_remove.png';
		var cont = new Element('div');
		if(containingForm){
			var img = new Element('img',{
				src:imgRemove,
				style:'float:left;cursor:pointer;margin-right:2px;padding-right:3px;border-right:1px solid #ccc;',
				note:0,
				title:MessageHash['meta_lom.3']
			});
			cont.insert(img);			
		}
		for(var i=1;i<6;i++){
			var img = new Element('img',{
				src:(value>=i?imgOn:imgOff),
				style:'float:left;cursor:pointer;margin-right:2px;',
				note:i,
				title:i
			});
			cont.insert(img);
		}
		cont.select('img').invoke('observe', 'click', function(event){
			var note = Event.element(event).readAttribute('note');
			window.setTimeout(function(){
				var selectedNode = app.getUserSelection().getUniqueNode();
				var conn = new Connexion();
				conn.setParameters(new Hash({
					get_action : 'edit_lom_meta',
					stars_rate : note,
					file	   : selectedNode.getPath()
				}));
				if(containingForm){
					containingForm.select('input').each(function(el){						
						if(el.name != 'stars_rate'){
							conn.addParameter(el.name, el.value);
						}
					});
				}
				conn.onComplete = function(){
					app.fireNodeRefresh(selectedNode);
					if(containingForm){
						hideLightBox(true);
					}
				};
				conn.sendAsync();
			}, 500);
		});
		return cont;
	},
	
	// mod for textarea
	formTextarea: function(formElement, form){
		var obj = new MetaCellRenderer();
		var cont = new Element('textarea', {name:formElement.name,style:'float: left;width: 136;border-radius: 3px;padding: 2px;height:100px;'});
		cont.innerHTML = formElement.value;
		formElement.replace(cont);
	}
});