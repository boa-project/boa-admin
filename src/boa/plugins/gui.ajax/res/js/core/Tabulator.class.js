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

Class.create("Tabulator", AppPane, {
	/**
	 * Constructor
	 * @param $super klass Superclass reference
	 * @param htmlElement HTMLElement Anchor of this pane
	 * @param tabulatorOptions Object Widget options
	 */
	initialize : function($super, htmlElement, tabulatorOptions){
		$super(htmlElement, tabulatorOptions);
		this.tabulatorData 	= tabulatorOptions.tabInfos;		
		// Tabulator Data : array of tabs infos
		// { id , label, icon and element : tabElement }.
		// tab Element must implement : showElement() and resize() methods.
		// Add drop shadow here, otherwise the negative value gets stuck in the CSS compilation...
		var div = new Element('div', {className:'tabulatorContainer panelHeader'});
		$(this.htmlElement).insert({top:div});
		this.tabulatorData.each(function(tabInfo){
			var td = new Element('span', {className:'toggleHeader', title:MessageHash[tabInfo.title] || MessageHash[tabInfo.label].stripTags()});
            if(tabInfo.icon){
                td.insert('<img width="16" height="16" align="absmiddle" src="'+resolveImageSource(tabInfo.icon, '/images/actions/ICON_SIZE', 16)+'">');
            }
            if(tabInfo.iconClass){
                td.insert(new Element('span', {className:tabInfo.iconClass}));
            }
            td.insert('<span class="tab_label" message_id="'+tabInfo.label+'">'+MessageHash[tabInfo.label]+'</span>');
			td.observe('click', function(){
				this.switchTabulator(tabInfo.id);
			}.bind(this) );
			div.insert(td);
			tabInfo.headerElement = td;
			disableTextSelection(td);
			this.selectedTabInfo = tabInfo; // select last one by default
		}.bind(this));
        if(this.options.headerToolbarOptions){
            var tbD = new Element('div', {id:"display_toolbar"});
            div.insert({top:tbD});
            var tb = new ActionsToolbar(tbD, this.options.headerToolbarOptions);
        }
        if(tabulatorOptions.defaultTabId){
            this.switchTabulator(tabulatorOptions.defaultTabId);
        }

	},
	
	/**
	 * Tab change
	 * @param tabId String The id of the target tab
	 */
	switchTabulator:function(tabId){
		var toShow ;
		this.tabulatorData.each(function(tabInfo){
			var appObject = this.getAndSetAppObject(tabInfo);
			if(tabInfo.id == tabId){				
				tabInfo.headerElement.removeClassName("toggleInactive");
				if(tabInfo.headerElement.down('img')) tabInfo.headerElement.down('img').show();
				if(appObject){
					toShow = appObject;
				}
				this.selectedTabInfo = tabInfo;
			}else{
				tabInfo.headerElement.addClassName("toggleInactive");
                if(tabInfo.headerElement.down('img')) tabInfo.headerElement.down('img').hide();
				if(appObject){
					appObject.showElement(false);
				}
			}
		}.bind(this));
		if(toShow){
			toShow.showElement(true);
            if(this.htmlElement.up('div[appClass="Splitter"]') && this.htmlElement.up('div[appClass="Splitter"]').paneObject){
                var splitter = this.htmlElement.up('div[appClass="Splitter"]').paneObject;
                if(splitter.splitbar.hasClassName('folded')){
                    splitter.unfold();
                }
            }
			toShow.resize();
		}
        this.resize();
        this.notify("switch", tabId);
	},
	
	/**
	 * Resizes the widget
	 */
	resize : function(){
		if(!this.selectedTabInfo) return;
		var appObject = this.getAndSetAppObject(this.selectedTabInfo);
		if(appObject){
			appObject.resize();
            var left ;
            var total = 0;
            var cont = this.htmlElement.down('div.tabulatorContainer');
            var innerWidth = parseInt(this.htmlElement.getWidth()) - parseInt(cont.getStyle('paddingLeft')) - parseInt(cont.getStyle('paddingRight'));
            if(this.options.headerToolbarOptions){
                innerWidth -= parseInt(this.htmlElement.down('div#display_toolbar').getWidth());
            }
            cont.removeClassName('icons_only');
            this.htmlElement.removeClassName('tabulator-vertical');
            this.tabulatorData.each(function(tabInfo){
                var header = tabInfo.headerElement;
                header.setStyle({width:'auto'});
                var hWidth = parseInt(header.getWidth());
                if(tabInfo == this.selectedTabInfo){
                    left = innerWidth - hWidth;
                }
                total += hWidth;
            }.bind(this));
            if(total >= innerWidth){
                var part = parseInt( left / ( this.tabulatorData.length -1) ) ;
                if(part < 14){
                    cont.addClassName('icons_only');
                }
                if(innerWidth < 30 ){
                    this.htmlElement.addClassName('tabulator-vertical');
                }
                this.tabulatorData.each(function(tabInfo){
                    var header = tabInfo.headerElement;
                    if(tabInfo != this.selectedTabInfo){
                        header.setStyle({width:part - ( parseInt(header.getStyle('paddingRight')) + parseInt(header.getStyle('paddingLeft')) ) + 'px'});
                    }
                }.bind(this));
            }
        }
	},
	
	/**
	 * Implementation of the IAppWidget methods
	 */
	getDomNode : function(){
		return this.htmlElement;
	},
	
	/**
	 * Implementation of the IAppWidget methods
	 */
	destroy : function(){
		this.tabulatorData.each(function(tabInfo){
			var appObject = this.getAndSetAppObject(tabInfo);
			tabInfo.headerElement.stopObserving("click");
			appObject.destroy();
		}.bind(this));
		this.htmlElement.update("");
        if(window[this.htmlElement.id]){
            try{delete window[this.htmlElement.id];}catch(e){}
        }
		this.htmlElement = null;
	},
	
	
	/**
	 * Getter/Setter of the Widget that will be attached to each tabInfo
	 * @param tabInfo Object
	 * @returns IAppWidget
	 */
	getAndSetAppObject : function(tabInfo){
		var appObject = tabInfo.appObject || null;
		if($(tabInfo.element) && $(tabInfo.element).paneObject && (!appObject || appObject != $(tabInfo.element).paneObject) ){
			appObject = tabInfo.appObject = $(tabInfo.element).paneObject;
		}
		return appObject;		
	}
	
});