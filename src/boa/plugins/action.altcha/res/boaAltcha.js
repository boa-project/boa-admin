/**
 * Client helper for ALTCHA challenges in BoA login / forgot / reset forms.
 *
 * Login uses Connexion.addParameter (not native form submit), so the payload
 * must be read explicitly and attached as boa_altcha.
 */
var boaAltcha = {

	fieldName: 'boa_altcha',
	scriptLoading: false,
	scriptLoaded: false,
	requiredCache: null,
	/** @type {Object.<string,string>} */
	payloadByTarget: {},

	pluginResBase: function(){
		var src = null;
		$$('script').each(function(tag){
			if(tag.src && tag.src.indexOf('boaAltcha.js') !== -1){
				src = tag.src.replace(/boaAltcha\.js.*$/, '');
			}
		});
		if(src){ return src; }
		var folder = (window._bootstrap && window._bootstrap.parameters)
			? (window._bootstrap.parameters.get('ajxpResourcesFolder')
				|| window._bootstrap.parameters.get('resourcesFolder')
				|| '')
			: '';
		if(folder){
			return folder.replace(/\/gui\.ajax\/res\/?$/, '') + '/action.altcha/res/';
		}
		if(window.resourcesFolder){
			return String(window.resourcesFolder).replace(/\/gui\.ajax\/res\/?$/, '') + '/action.altcha/res/';
		}
		return 'boa/plugins/action.altcha/res/';
	},

	ensureWidgetScript: function(callback){
		if(window.customElements && customElements.get('altcha-widget')){
			this.scriptLoaded = true;
			if(callback) callback();
			return;
		}
		if(this.scriptLoaded){
			if(callback) callback();
			return;
		}
		var self = this;
		var finish = function(){
			self.scriptLoaded = true;
			if(callback) callback();
		};
		if(this.scriptLoading){
			var wait = function(){
				if(self.scriptLoaded || (window.customElements && customElements.get('altcha-widget'))){
					finish();
				}else{
					setTimeout(wait, 50);
				}
			};
			wait();
			return;
		}
		this.scriptLoading = true;
		var s = document.createElement('script');
		s.type = 'module';
		s.async = true;
		s.src = this.pluginResBase() + 'altcha.min.js';
		s.onload = finish;
		s.onerror = finish;
		document.head.appendChild(s);
	},

	rememberPayload: function(target, payload){
		if(!payload) return;
		this.payloadByTarget[target || 'login'] = payload;
		this.lastPayload = payload;
	},

	attach: function(form, target){
		if(!form) return;
		target = target || 'login';
		this.payloadByTarget[target] = '';
		this.lastPayload = '';
		var slot = form.down ? form.down('.boa-altcha-slot') : (form.querySelector ? form.querySelector('.boa-altcha-slot') : null);
		if(!slot){
			slot = new Element('div', {className: 'SF_element boa-altcha-slot'});
			if(form.insert){ form.insert(slot); }
			else if(form.appendChild){ form.appendChild(slot); }
		}
		var self = this;
		this.ensureWidgetScript(function(){
			self.render(slot, target);
		});
	},

	render: function(slot, target){
		if(!slot) return;
		if(slot.update){ slot.update(''); } else { slot.innerHTML = ''; }
		var self = this;
		var apply = function(data){
			if(!data || data.disabled || data.ok === false){
				self.requiredCache = false;
				return;
			}
			self.requiredCache = true;

			// Single controlled field for Connexion (avoid competing empty inputs).
			var hidden = document.createElement('input');
			hidden.type = 'hidden';
			hidden.name = data.name || self.fieldName;
			hidden.id = 'boa_altcha_payload_' + target;
			hidden.value = '';
			hidden.setAttribute('data-boa-altcha', '1');

			var w = document.createElement('altcha-widget');
			// Do not set name= on the widget: that creates a second hidden input that
			// can shadow ours when reading the form. We own the payload field.
			w.setAttribute('maxnumber', String(data.maxnumber || 100000));
			w.setAttribute('challengejson', data.challengejson || '{}');
			w.setAttribute('strings', data.strings || '{}');
			w.setAttribute('auto', 'onload');
			w.style.maxWidth = '100%';
			w.setAttribute('data-boa-target', target);

			var store = function(payload){
				if(!payload) return;
				hidden.value = payload;
				self.rememberPayload(target, payload);
			};

			w.addEventListener('verified', function(ev){
				store(ev && ev.detail ? ev.detail.payload : '');
			});
			w.addEventListener('statechange', function(ev){
				if(ev && ev.detail && ev.detail.state === 'verified'){
					store(ev.detail.payload || '');
				}
			});

			if(slot.appendChild){
				slot.appendChild(w);
				slot.appendChild(hidden);
			}
		};
		if(window.Connexion){
			var conn = new Connexion();
			conn.addParameter('get_action', 'get_altcha_challenge');
			conn.addParameter('target', target);
			conn.onComplete = function(transport){
				try{
					apply(transport.responseText.evalJSON());
				}catch(e){}
			};
			conn.setMethod('get');
			conn.sendAsync();
		}
	},

	/**
	 * Read payload for Connexion. Prefer in-memory value from verified event.
	 */
	getPayload: function(form, target){
		target = target || 'login';
		if(this.payloadByTarget[target]){
			return this.payloadByTarget[target];
		}
		if(this.lastPayload){
			return this.lastPayload;
		}
		if(!form) return '';

		// Prefer our controlled field.
		var marked = null;
		if(form.down){
			marked = form.down('input[data-boa-altcha="1"]');
		}
		if(marked && marked.value){
			return marked.value;
		}

		var name = this.fieldName;
		var nodes = [];
		if(form.select){
			nodes = form.select('input[name="'+name+'"]');
		}else if(form.querySelectorAll){
			nodes = $A(form.querySelectorAll('input[name="'+name+'"]'));
		}
		for(var i = 0; i < nodes.length; i++){
			if(nodes[i].value){ return nodes[i].value; }
		}

		// Fallback: widget internals / shadow root.
		var widgets = form.getElementsByTagName ? form.getElementsByTagName('altcha-widget') : [];
		for(var j = 0; j < widgets.length; j++){
			var w = widgets[j];
			var hi = null;
			if(w.shadowRoot && w.shadowRoot.querySelector){
				hi = w.shadowRoot.querySelector('input[type="hidden"]');
			}
			if((!hi || !hi.value) && w.querySelector){
				hi = w.querySelector('input[type="hidden"]');
			}
			if(hi && hi.value){ return hi.value; }
		}
		return '';
	}
};

window.boaAltcha = boaAltcha;
