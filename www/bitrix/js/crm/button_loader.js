;(function (window) {
	if (!window.BX)
	{
		window.BX = {};
	}
	else if (window.BX.SiteButton)
	{
		return;
	}

	window.BX.SiteButton = {

		isShown: false,
		init: function(config)
		{
			this.loadBxAnalytics();

			this.userParams = window.Bitrix24WidgetObject || {};
			this.config = config;
			this.handlers = this.userParams.handlers || {};
			this.eventHandlers = [];

			this.execEventHandler('init', [this]);

			if(!this.check())
			{
				return;
			}

			this.loadedButtonCount = 0;
			this.load();

			if(this.config.delay)
			{
				var _this = this;
				window.setTimeout(
					function(){
						_this.show();
					},
					1000 * this.config.delay
				);
			}
			else
			{
				this.show();
			}
		},
		check: function()
		{
			if(!this.config.isActivated)
			{
				return false;
			}

			if(this.config.widgets.length == 0)
			{
				return false;
			}

			if (!this.wm.checkPagesAll(this))
			{
				return false;
			}

			return true;
		},
		loadBxAnalytics: function()
		{
			if(typeof window._ba != "undefined")
			{
				return;
			}

			var targetHost = document.location.hostname;

			window._ba = window._ba || [];
			window._ba.push(["aid", "ext:" + targetHost]);
			window._ba.push(["host", targetHost]);
			(function() {
				var ba = document.createElement("script"); ba.type = "text/javascript"; ba.async = true;
				ba.src = (document.location.protocol == "https:" ? "https://" : "http://") + "bitrix.info/ba.js";
				var s = document.getElementsByTagName("script")[0];
				s.parentNode.insertBefore(ba, s);
			})();
		},
		load: function()
		{
			this.execEventHandler('load', [this]);

			// set common classes
			if(this.util.isIOS()) this.addClass(document.documentElement, 'bx-ios');
			if(this.util.isMobile()) this.addClass(document.documentElement, 'bx-touch');

			// load resources
			this.config.resources.forEach(function(resource){

				switch (resource.type)
				{
					case 'text/css':

						var cssNode = document.createElement('STYLE');
						cssNode.setAttribute("type", "text/css");
						if(cssNode.styleSheet)
						{
							cssNode.styleSheet.cssText = resource.content;
						}
						else
						{
							cssNode.appendChild(document.createTextNode(resource.content))
						}
						document.head.appendChild(cssNode);

						break;
				}

			}, this);

			// insert layout
			this.context = this.util.getNodeFromText(this.config.layout);
			if (!this.context)
			{
				return;
			}

			document.body.appendChild(this.context);
			this.container = this.context.querySelector('[data-b24-crm-button-cont]');


			// init components
			this.shadow.init({
				'caller': this,
				'shadowNode': this.context.querySelector('[data-b24-crm-button-shadow]')
			});
			this.buttons.init({
				'caller': this,
				'container': this.container.querySelector('[data-b24-crm-button-block]'),
				'blankButtonNode': this.context.querySelector('[data-b24-crm-button-widget-blank]'),
				'openerButtonNode': this.context.querySelector('[data-b24-crm-button-block-button]')
			});
			this.wm.init({'caller': this});
			this.hello.init({
				caller: this,
				context: this.container.querySelector('[data-b24-crm-hello-cont]')
			});

			// load widgets
			this.wm.loadAll();

			this.execEventHandler('loaded', [this]);
		},
		setPulse: function(isActive)
		{
			isActive = isActive || false;
			var pulseNode = this.context.querySelector('[data-b24-crm-button-pulse]');
			if (!pulseNode)
			{
				return;
			}
			pulseNode.style.display = isActive ? '' : 'none';
		},
		show: function()
		{
			this.removeClass(this.container, 'b24-widget-button-disable');
			this.addClass(this.container, 'b24-widget-button-visible');

			this.execEventHandler('show', [this]);
			this.isShown = true;
		},
		hide: function()
		{
			this.addClass(this.container, 'b24-widget-button-disable');

			this.execEventHandler('hide', [this]);
		},
		addEventHandler: function(eventName, handler)
		{
			if (!eventName || !handler)
			{
				return;
			}

			this.eventHandlers.push({
				'eventName': eventName,
				'handler': handler
			});
		},
		execEventHandler: function(eventName, params)
		{
			params = params || [];
			if (!eventName)
			{
				return;
			}

			this.eventHandlers.forEach(function (eventHandler) {
				if (eventHandler.eventName == eventName)
				{
					eventHandler.handler.apply(this, params);
				}
			}, this);

			if(this.handlers[eventName])
			{
				this.handlers[eventName].apply(this, params);
			}

			var externalEventName = 'b24-sitebutton-' + eventName;
			if (window.BX.onCustomEvent)
			{
				window.BX.onCustomEvent(document, externalEventName, params);
			}
			if (window.jQuery && typeof(window.$) == 'function')
			{
				var obj = window.$( document );
				if (obj && obj.trigger) obj.trigger(externalEventName, params);
			}
		},
		onWidgetFormInit: function(form)
		{
			this.execEventHandler('form-init', [form]);
		},
		onWidgetClose: function()
		{
			this.buttons.hide();
			this.show();
		},
		addClass: function(element, className)
		{
			if (element && typeof element.className == "string" && element.className.indexOf(className) === -1)
			{
				element.className += " " + className;
				element.className = element.className.replace('  ', ' ');
			}
		},
		removeClass: function(element, className)
		{
			if (!element || !element.className)
			{
				return;
			}

			element.className = element.className.replace(className, '').replace('  ', ' ');
		},
		addEventListener: function(el, eventName, handler)
		{
			el = el || window;
			if (window.addEventListener)
			{
				el.addEventListener(eventName, handler, false);
			}
			else
			{
				el.attachEvent('on' + eventName, handler);
			}
		},
		buttons: {
			isShown: false,
			isInit: false,
			wasOnceShown: false,
			wasOnceClick: false,
			blankButtonNode: null,
			list: [],
			animatedNodes: [],
			init: function(params)
			{
				this.c = params.caller;
				this.container = params.container;
				this.blankButtonNode = params.blankButtonNode;
				this.openerButtonNode = params.openerButtonNode;

				/* location magic */
				this.openerClassName = this.c.config.location > 3 ? 'b24-widget-button-bottom' : 'b24-widget-button-top';

				var _this = this;
				this.c.addEventListener(this.openerButtonNode, 'click', function (e) {
					if (_this.list.length == 1 && _this.list[0].onclick && !_this.list[0].href)
					{
						_this.list[0].onclick.apply(this, []);
					}
					else
					{
						_this.toggle();
					}
				});

				this.isInit = true;

				this.list.forEach(function (button) {
					if (!button.node) this.insert(button);
				}, this);

				// animation
				this.animatedNodes = this.c.util.nodeListToArray(this.c.context.querySelectorAll('[data-b24-crm-button-icon]'));

				this.animate();
				this.c.addClass(
					this.c.context.querySelector('[data-b24-crm-button-pulse]'),
					'b24-widget-button-pulse-animate'
				);
			},
			animate: function()
			{
				var className = 'b24-widget-button-icon-animation';
				var curIndex = 0;
				this.animatedNodes.forEach(function (node, index) {
					if (this.c.util.hasClass(node, className)) curIndex = index;
					this.c.removeClass(node, className);
				}, this);

				curIndex++;
				curIndex = curIndex < this.animatedNodes.length ? curIndex : 0;
				this.c.addClass(this.animatedNodes[curIndex], className);

				if (this.animatedNodes.length > 1)
				{
					var _this = this;
					setTimeout(function () {_this.animate();}, 1500);
				}
			},
			toggle: function()
			{
				this.isShown ? this.hide() : this.show();
			},
			show: function()
			{
				if(this.c.util.isIOS()) this.c.addClass(document.documentElement, 'bx-ios-fix-frame-focus');

				//if (this.c.util.isMobile())
				{
					this.c.shadow.show();
				}

				this.isShown = true;
				this.wasOnceShown = true;
				this.c.addClass(this.c.container, this.openerClassName);
				this.c.addClass(this.container, 'b24-widget-button-show');
				this.c.removeClass(this.container, 'b24-widget-button-hide');

				this.c.hello.hide();
			},
			hide: function()
			{
				if(this.c.util.isIOS()) this.c.removeClass(document.documentElement, 'bx-ios-fix-frame-focus');

				this.isShown = false;

				this.c.addClass(this.container, 'b24-widget-button-hide');
				this.c.removeClass(this.container, 'b24-widget-button-show');
				this.c.removeClass(this.c.container, this.openerClassName);

				this.c.hello.hide();
				this.c.shadow.hide();
			},
			displayButton: function (id, display)
			{
				this.list.forEach(function (button) {
					if (button.id != id) return;
					if (!button.node) return;
					button.node.style.display = display ? '' : 'none';
				});
			},
			sortOut: function ()
			{
				this.list.sort(function(buttonA, buttonB){
					return buttonA.sort > buttonB.sort ? 1 : -1;
				});

				this.list.forEach(function(button){
					if (!button.node) return;
					button.node.parentNode.appendChild(button.node);
				});
			},
			add: function (params)
			{
				this.list.push(params);
				this.insert(params);
			},
			insert: function (params)
			{
				if (!this.isInit)
				{
					params.node = null;
					return null;
				}

				var buttonNode = this.blankButtonNode.cloneNode(true);
				params.node = buttonNode;
				params.sort = params.sort || 100;

				buttonNode.setAttribute('data-b24-crm-button-widget', params.id);
				buttonNode.setAttribute('data-b24-widget-sort', params.sort);

				if (params.classList && params.classList.length > 0)
				{
					params.classList.forEach(function (className) {
						this.c.addClass(buttonNode, className);
					}, this);
				}

				if (params.title)
				{
					var tooltipNode = buttonNode.querySelector('[data-b24-crm-button-tooltip]');
					if (tooltipNode)
					{
						tooltipNode.innerText = params.title;
					}
					else
					{
						buttonNode.title = params.title;
					}
				}

				if (params.icon)
				{
					buttonNode.style['background-image'] = 'url(' + params.icon + ')';
				}
				else
				{
					if (params.iconColor)
					{
						setTimeout(function () {
							var styleName = 'background-image';
							if(!window.getComputedStyle)
							{
								return;
							}

							var styleValue = window.getComputedStyle(buttonNode, null).getPropertyValue(styleName);
							buttonNode.style[styleName] = (
								styleValue || ''
							).replace('FFF', params.iconColor.substring(1));
						}, 1000);
					}

					if (params.bgColor)
					{
						buttonNode.style['background-color'] = params.bgColor;
					}
				}

				if (params.href)
				{
					buttonNode.href = params.href;
					buttonNode.target = params.target ? params.target : '_blank';
				}

				if (params.onclick)
				{
					var _this = this;
					this.c.addEventListener(buttonNode, 'click', function (e) {
						_this.wasOnceClick = true;
						params.onclick.apply(_this, []);
					});
				}

				this.container.appendChild(buttonNode);
				this.sortOut();

				return buttonNode;
			}
		},
		shadow: {
			clickHandler: null,
			shadowNode: null,
			init: function(params)
			{
				this.c = params.caller;
				this.shadowNode = params.shadowNode;

				var _this = this;
				this.c.addEventListener(this.shadowNode, 'click', function (e) {
					_this.onClick();
				});
				this.c.addEventListener(document, 'keyup', function (e) {
					e = e || window.e;
					if (e.keyCode == 27)
					{
						_this.onClick();
					}
				});
			},
			onClick: function()
			{
				this.c.wm.hide();
				this.c.buttons.hide();

				if (!this.clickHandler)
				{
					return;
				}

				this.clickHandler.apply(this, []);
				this.clickHandler = null;
			},
			show: function(clickHandler)
			{
				this.clickHandler = clickHandler;
				this.c.addClass(this.shadowNode, 'b24-widget-button-show');
				this.c.removeClass(this.shadowNode, 'b24-widget-button-hide');
				this.c.addClass(document.documentElement, 'crm-widget-button-mobile');
			},
			hide: function()
			{
				this.c.addClass(this.shadowNode, 'b24-widget-button-hide');
				this.c.removeClass(this.shadowNode, 'b24-widget-button-show');
				this.c.removeClass(document.documentElement, 'crm-widget-button-mobile');
			}
		},
		util: {
			getNodeFromText: function(text)
			{
				var node = document.createElement('div');
				node.innerHTML = text;
				return node.children[0];
			},
			hasClass: function(node, className)
			{
				var classList = this.nodeListToArray(node.classList);
				var filtered = classList.filter(function (name) { return name == className});
				return filtered.length > 0;
			},
			nodeListToArray: function(nodeList)
			{
				var list = [];
				if (!nodeList) return list;
				for (var i = 0; i < nodeList.length; i++)
				{
					list.push(nodeList.item(i));
				}
				return list;
			},
			isIOS: function()
			{
				return (/(iPad;)|(iPhone;)/i.test(navigator.userAgent));
			},
			isOpera: function()
			{
				return navigator.userAgent.toLowerCase().indexOf('opera') != -1;
			},
			isIE: function()
			{
				return document.attachEvent && !this.isOpera();
			},
			isMobile: function()
			{
				return (/(ipad|iphone|android|mobile|touch)/i.test(navigator.userAgent));
			},
			isArray: function(item) {
				return item && Object.prototype.toString.call(item) == "[object Array]";
			},
			isString: function(item) {
				return item === '' ? true : (item ? (typeof (item) == "string" || item instanceof String) : false);
			},
			evalGlobal: function(text)
			{
				if (!text)
				{
					return;
				}

				var head = document.getElementsByTagName("head")[0] || document.documentElement,
					script = document.createElement("script");

				script.type = "text/javascript";

				if (!this.isIE())
				{
					script.appendChild(document.createTextNode(text));
				}
				else
				{
					script.text = text;
				}

				head.insertBefore(script, head.firstChild);
				head.removeChild(script);
			},
			isCurPageInList: function(list)
			{
				var filtered = list.filter(function (page) {
					var pattern = this.prepareUrl(page).split('*').map(function(chunk){
						return chunk.replace(/[\-\[\]\/\{\}\(\)\*\+\?\.\\\^\$\|]/g, "\\$&");
					}).join('.*');
					pattern = '^' + pattern + '$';
					return (new RegExp(pattern)).test(this.prepareUrl(window.location.href));
				}, this);

				return filtered.length > 0;
			},
			prepareUrl: function(url)
			{
				if (url.substring(0, 5) == 'http:')
				{
					result = url.substring(7);
				}
				else if (url.substring(0, 6) == 'https:')
				{
					result = url.substring(8);
				}
				else
				{
					result = url;
				}

				return result;
			},
			getCookie: function (name)
			{
				var matches = document.cookie.match(new RegExp(
					"(?:^|; )" + name.replace(/([\.$?*|{}\(\)\[\]\\\/\+^])/g, '\\$1') + "=([^;]*)"
				));

				return matches ? decodeURIComponent(matches[1]) : undefined;
			},
			setCookie: function (name, value, options)
			{
				options = options || {};
				var expires = options.expires;
				if (typeof(expires) == "number" && expires)
				{
					var currentDate = new Date();
					currentDate.setTime(currentDate.getTime() + expires * 1000);
					expires = options.expires = currentDate;
				}

				if (expires && expires.toUTCString)
				{
					options.expires = expires.toUTCString();
				}
				value = encodeURIComponent(value);
				var updatedCookie = name + "=" + value;
				for (var propertyName in options)
				{
					if (!options.hasOwnProperty(propertyName))
					{
						continue;
					}
					updatedCookie += "; " + propertyName;
					var propertyValue = options[propertyName];
					if (propertyValue !== true)
					{
						updatedCookie += "=" + propertyValue;
					}
				}

				document.cookie = updatedCookie;
			}
		},
		wm: { /* Widget Manager */
			showedWidget: null,
			init: function(params)
			{
				this.c = params.caller;
			},
			getList: function()
			{
				return this.c.config.widgets;
			},
			getById: function(id)
			{
				var widgets = this.c.config.widgets.filter(function (widget) {
					return id == widget.id;
				}, this);

				return (widgets.length > 0 ? widgets[0] : null);
			},
			hide: function()
			{
				if (!this.showedWidget)
				{
					return;
				}

				if(this.showedWidget.hide)
				{
					this.c.util.evalGlobal(this.showedWidget.hide);
				}

				this.c.onWidgetClose();
				this.c.shadow.hide();
				this.showedWidget = null;
			},
			show: function(widget)
			{
				if(!widget.show || !this.c.util.isString(widget.show))
				{
					return;
				}

				this.showedWidget = widget;
				this.c.shadow.show();

				this.c.util.evalGlobal(widget.show);
				this.c.hide();
			},
			checkPagesAll: function(manager)
			{
				this.c = manager;
				return this.c.config.widgets.some(this.checkPages, this);
			},
			checkPages: function(widget)
			{
				var isPageFound = this.c.util.isCurPageInList(widget.pages.list);
				if(widget.pages.mode == 'EXCLUDE')
				{
					return !isPageFound;
				}
				else
				{
					return isPageFound;
				}
			},
			loadAll: function()
			{
				this.c.config.widgets.forEach(this.load, this);
			},
			load: function(widget)
			{
				widget.isLoaded = false;

				widget.buttonNode = this.c.buttons.add({
					'id': widget.id,
					'href': this.getButtonUrl(widget),
					'sort': widget.sort,
					'classList': (typeof widget.classList != "undefined" ? widget.classList : null),
					'title': (typeof widget.title != "undefined" ? widget.title : null),
					'onclick': this.getButtonHandler(widget),
					'bgColor': widget.useColors ? this.c.config.bgColor : null,
					'iconColor': widget.useColors ? this.c.config.iconColor : null
				});

				this.c.execEventHandler('load-widget-' + widget.id, [widget]);
				if(!this.checkPages(widget))
				{
					this.c.buttons.displayButton(widget.id, false);
					return;
				}

				this.loadScript(widget);
				widget.isLoaded = true;
				this.loadedButtonCount++;
			},
			getButtonHandler: function(widget)
			{
				var _this = this;
				return function () {
					_this.show(widget);
				};
			},
			getButtonUrl: function(widget)
			{
				if (widget.script || !widget.show)
				{
					return null;
				}

				if (this.c.util.isString(widget.show) || !widget.show.url)
				{
					return null;
				}

				var url = null;
				if (this.c.util.isMobile() && widget.show.url.mobile)
					url = widget.show.url.mobile;
				else if (!this.c.util.isMobile() && widget.show.url.desktop)
					url = widget.show.url.desktop;
				else if (this.c.util.isString(widget.show.url))
					url = widget.show.url;

				return url;
			},
			loadScript: function(widget)
			{
				if (!widget.script)
				{
					return;
				}

				var scriptText = '';
				var isAddInHead = false;
				var parsedScript = widget.script.match(/<script\b[^>]*>(.*?)<\/script>/i);
				if(parsedScript && parsedScript[1])
				{
					scriptText = parsedScript[1];
					isAddInHead = true;
				}
				else
				{
					widget.node = this.c.util.getNodeFromText(widget.script);
					if(!widget.node)
					{
						return;
					}
					isAddInHead = false;

					if (typeof widget.caption != "undefined")
					{
						var widgetCaptionNode = widget.node.querySelector('[data-bx-crm-widget-caption]');
						if (widgetCaptionNode)
						{
							widgetCaptionNode.innerText = widget.caption;
						}
					}
				}

				if (isAddInHead)
				{
					widget.node = document.createElement("script");
					try {
						widget.node.appendChild(document.createTextNode(scriptText));
					} catch(e) {
						widget.node.text = scriptText;
					}
					document.head.appendChild(widget.node);
				}
				else
				{
					document.body.insertBefore(widget.node, document.body.firstChild);
				}
			}
		},
		hello: {
			isInit: false,
			wasOnceShown: false,
			condition: null,
			cookieName: 'b24_sitebutton_hello',
			init: function (params)
			{
				this.c = params.caller;

				if (this.isInit || this.c.util.getCookie(this.cookieName) == 'y')
				{
					return;
				}

				this.context = params.context;
				this.showClassName = 'b24-widget-button-popup-show';
				this.config = this.c.config.hello;
				this.delay = this.config.delay;

				this.buttonHideNode = this.context.querySelector('[data-b24-hello-btn-hide]');
				this.iconNode = this.context.querySelector('[data-b24-hello-icon]');
				this.nameNode = this.context.querySelector('[data-b24-hello-name]');
				this.textNode = this.context.querySelector('[data-b24-hello-text]');

				this.initHandlers();

				if (!this.config || !this.config.conditions || this.config.conditions.length == 0)
				{
					return;
				}

				if (!this.condition)
				{
					this.setConditions(this.config.conditions);
				}
				var _this = this;
				this.c.addEventHandler('show', function () {
					if (!_this.c.isShown)
					{
						_this.initCondition();
					}
				});
				this.isInit = true;
			},
			setConditions: function (conditions)
			{
				this.condition = this.findCondition(conditions);
				this.initCondition();
			},
			initCondition: function ()
			{
				if (!this.condition)
				{
					return;
				}

				if (!this.isInit)
				{
					return;
				}

				if (this.condition.icon)
				{
					this.iconNode.style['background-image'] = 'url(' + this.condition.icon + ')';
				}
				if (this.condition.name)
				{
					this.nameNode.innerText = this.condition.name;
				}
				if (this.condition.text)
				{
					this.textNode.innerText = this.condition.text;
				}
				if (this.condition.delay)
				{
					this.delay = this.condition.delay;
				}

				this.planShowing();
			},
			initHandlers: function ()
			{
				var _this = this;
				this.c.addEventListener(this.buttonHideNode, 'click', function (e) {
					_this.hide();

					if(!e) e = window.event;
					if(e.stopPropagation){e.preventDefault();e.stopPropagation();}
					else{e.cancelBubble = true;e.returnValue = false;}
				});
				this.c.addEventListener(this.context, 'click', function () {
					_this.showWidget();
				});
			},
			planShowing: function ()
			{
				if (this.wasOnceShown || this.c.buttons.wasOnceClick)
				{
					return;
				}

				var showDelay = this.delay || 10;
				var _this = this;
				setTimeout(function () {
					_this.show();
				}, showDelay * 1000);
			},
			findCondition: function (conditions)
			{
				if (!conditions)
				{
					return;
				}

				var filtered;
				// find first suitable condition with mode 'include'
				filtered = conditions.filter(function (condition) {
					if (!condition.pages || condition.pages.MODE == 'EXCLUDE' || condition.pages.LIST.length == 0)
					{
						return false;
					}

					return this.c.util.isCurPageInList(condition.pages.LIST);
				}, this);
				if (filtered.length > 0)
				{
					return filtered[0];
				}

				// find first suitable condition with mode 'exclude'
				filtered = conditions.filter(function (condition) {
					if (!condition.pages || condition.pages.MODE == 'INCLUDE')
					{
						return false;
					}

					return !this.c.util.isCurPageInList(condition.pages.LIST);
				}, this);
				if (filtered.length > 0)
				{
					return filtered[0];
				}

				// find first condition with empty pages
				filtered = conditions.filter(function (condition) {
					return !condition.pages;
				}, this);
				if (filtered.length > 0)
				{
					return filtered[0];
				}

				// nothing found
				return null;
			},
			showWidget: function ()
			{
				this.hide();

				var widget = null;
				if (this.condition && this.condition.showWidgetId)
				{
					widget = this.c.wm.getById(this.condition.showWidgetId);
				}

				if (!widget)
				{
					widget = this.c.wm.getById(this.config.showWidgetId);
				}

				if (!widget)
				{
					widget = this.c.wm.getList()[0];
				}

				if (widget)
				{
					this.c.wm.show(widget);
				}
			},
			show: function ()
			{
				if (this.c.buttons.isShown) //maybe use wasOnceShown
				{
					this.planShowing();
					return;
				}

				this.wasOnceShown = true;
				this.c.addClass(this.context, this.showClassName);
			},
			hide: function ()
			{
				this.c.removeClass(this.context, this.showClassName);
				this.c.util.setCookie(this.cookieName, 'y', {expires: 60*60*6});
			}
		}
	};


})(window);