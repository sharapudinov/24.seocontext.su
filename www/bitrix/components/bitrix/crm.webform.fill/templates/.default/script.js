function CrmWebForm(params)
{
	this.init = function(params)
	{
		this.id = params.id || '';
		this.hash = params.hash || '';
		this.postAjax = params.postAjax || false;
		this.mess = params.mess || {sentError: '', sentSuccess: ''};
		this.currency = params.currency || null;
		this.useReCaptcha = params.useReCaptcha || false;
		this.phoneFormatDataUrl = params.phoneFormatDataUrl || null;
		this.phoneCountryCode = params.phoneCountryCode || null;
		this.canRemoveCopyright = !!params.canRemoveCopyright;
		this.redirectDelay = null;

		// Form
		this.form = BX(params.form);
		this.disabled = false;

		// Post message
		this.postMessageDomain = null;
		this.postMessageOrigin = null;
		this.postMessageSource = null;

		if(this.form)
		{
			//tracking
			var trackingParams = params.tracking || {data: {}, ga: false, gaPageView: false, ya: null};
			trackingParams.caller = this;
			this.tracking = new CrmWebFormTracking(trackingParams);

			// popup
			this.popup = new CrmWebFormPopup({caller: this});

			// Form checker
			params.onShow = BX.proxy(this.onShow, this);
			params.onHide = BX.proxy(this.onHide, this);
			params.onFocus = BX.proxy(this.onFocus, this);
			params.onBlur = BX.proxy(this.onBlur, this);
			params.onCheck = BX.proxy(this.onCheck, this);
			params.onChange = BX.proxy(this.onChange, this);
			params.onSubmitSuccess = BX.proxy(this.onSubmitSuccess, this);
			this.webForm = new FormChecker(params);
			this.webForm.fireChangeEvent();

			// init submit buttons
			this.submitButtonNodes = BX.convert.nodeListToArray(document.querySelectorAll('[data-bx-webform-submit-btn]'));
			this.submitButtonNodes.forEach(function(button){
				BX.bind(button, 'click', BX.proxy(this.webForm.submit, this.webForm));
			}, this);
		}

		// add from-url input to form
		this.addHiddenInputToForm('from', window.location.href);

		// Process parameters from top window
		this.initFrameParameters();

		if(this.form)
		{
			// Init date & phone inputs
			this.initDateControls();
			this.initPhoneControls();

			// Init file inputs
			this.initFileInputs(document.querySelectorAll('input[type="file"]'));

			// init reCaptcha
			this.initReCaptcha();
		}

		// Start listener of resize events
		this.listenResizeEvent();

		// Start capturing of keyboard events
		this.captureKeyBoard();

		// licence
		this.licence = new CrmWebFormLicence({caller: this});
	};

	this.initReCaptcha = function()
	{
		if (!this.useReCaptcha)
		{
			return;
		}

		if (!window.onReCaptchaLoadCallback && !window.grecaptcha)
		{
			window.onReCaptchaLoadCallback = BX.proxy(this.initReCaptcha, this);
			return;
		}

		if (window.grecaptcha && window.grecaptcha.render)
		{
			var recaptchaNode = BX('recaptcha-cont');
			window.grecaptcha.render(recaptchaNode, {
				'sitekey': recaptchaNode.getAttribute('data-sitekey'),
				'size': BX.browser.IsMobile() ? 'compact' : 'normal'
			});
		}

		BX.addCustomEvent(this.webForm, 'onSubmit', function (e, eventData) {
			if (window.grecaptcha && window.grecaptcha.getResponse)
			{
				eventData.isSuccess = window.grecaptcha.getResponse().length > 0;
				if (!eventData.isSuccess)
				{
					var errorClass = 'crm-webform-captcha-error-animate';
					var errorNode = BX('recaptcha-error');
					BX.addClass(errorNode, errorClass);
					setTimeout(function () {
						BX.removeClass(errorNode, errorClass);
					}, 700);
				}
			}
		});

	};

	this.initDateControls = function()
	{
		var dateList = BX.convert.nodeListToArray(this.form.querySelectorAll('.crm-webform-input-desktop.crm-webform-input-date'));
		dateList.forEach(function(dateNode){
			this.initDateControl(dateNode, false);
		}, this);

		var dateTimeList = BX.convert.nodeListToArray(this.form.querySelectorAll('.crm-webform-input-desktop.crm-webform-input-datetime'));
		dateTimeList.forEach(function(dateNode){
			this.initDateControl(dateNode, true);
		}, this);
	};

	this.initDateControl = function(node, isDateTime)
	{
		isDateTime = isDateTime || false;
		if(!node)
		{
			return;
		}

		if(BX.browser.IsMobile() && node.nextElementSibling && node.nextElementSibling.tagName == 'INPUT')
		{
			var mobileNode = node.nextElementSibling;
			BX.bind(mobileNode, 'blur', function(){
				try{
					var date = new Date(mobileNode.value);
					node.value = BX.formatDate(date, isDateTime ? BX.message('FORMAT_DATETIME') : BX.message('FORMAT_DATE'));
				}
				catch(e){}
			});
		}
		else
		{
			BX.bind(node, 'click', function(){
				BX.calendar({node: node, field: node, bTime: isDateTime});
			});
		}
	};

	this.initPhoneControls = function()
	{
		var inputList = BX.convert.nodeListToArray(this.form.querySelectorAll('.crm-webform-input-phone'));
		inputList.forEach(function(inputNode){
			this.initPhoneControl(inputNode, false);
		}, this);
	};

	this.initPhoneControlByDataInput = function(node)
	{
		if(!node)
		{
			return;
		}

		if(!node.previousElementSibling || node.previousElementSibling.tagName != 'INPUT')
		{
			return;
		}

		this.initPhoneControl(node.previousElementSibling);
	};

	this.initPhoneControl = function(node)
	{
		if(!node)
		{
			return;
		}

		if(!node.nextElementSibling || node.nextElementSibling.tagName != 'INPUT')
		{
			return;
		}

		var flagNode = node.previousElementSibling;
		var dataNode = node.nextElementSibling;
		var maskedController = new BXMaskedPhone({
			url: this.phoneFormatDataUrl,
			country: this.phoneCountryCode,
			'maskedInput': {
				input: node,
				dataInput: dataNode
			},
			'flagNode': flagNode,
			'flagSize': 24
		});
	};


	this.initFrameParameters = function()
	{
		if(!this.isFrame())
		{
			return;
		}

		if(!window.location.hash)
		{
			return;
		}

		var frameParameters = {};
		try
		{
			frameParameters = JSON.parse(decodeURIComponent(window.location.hash.substring(1)));
		}
		catch (err){}

		if(frameParameters.domain)
		{
			this.postMessageDomain = frameParameters.domain;
		}

		if(frameParameters.from)
		{
			this.addHiddenInputToForm('from', frameParameters.from);
		}

		if(frameParameters.presets)
		{
			var presets = '';
			for(var presetFieldName in frameParameters.presets)
			{
				if(!frameParameters.presets.hasOwnProperty(presetFieldName))
				{
					continue;
				}

				presets += encodeURIComponent(presetFieldName) + '=' + encodeURIComponent(frameParameters.presets[presetFieldName]) + '&';
			}
			this.addHiddenInputToForm('presets', presets);
		}

		if(frameParameters.options)
		{
			if(BX.type.isNumber(frameParameters.options['redirectDelay']))
			{
				this.redirectDelay = parseInt(frameParameters.options['redirectDelay']);
			}
			if(frameParameters.options['borders'] === false)
			{
				BX.addClass(document.body, 'crm-webform-no-borders');
			}
			if(frameParameters.options['logo'] === false && this.canRemoveCopyright)
			{
				BX.addClass(document.body, 'crm-webform-no-logo');
			}
		}

		if(!frameParameters.fields || !frameParameters.fields.values)
		{
			return;
		}

		for(var fieldName in frameParameters.fields.values)
		{
			if(!frameParameters.fields.values.hasOwnProperty(fieldName))
			{
				continue;
			}

			var field = this.webForm.getField(fieldName);
			if(!field)
			{
				continue;
			}

			var fieldValues = frameParameters.fields.values[fieldName];
			fieldValues = BX.type.isArray(fieldValues) ? fieldValues : [fieldValues];
			field.setValues(fieldValues);
		}
	};

	this.initFileInputs = function(inputFileList)
	{
		inputFileList = BX.convert.nodeListToArray(inputFileList);
		inputFileList.forEach(function(input){
			BX.bind(input, 'change', function(){
				var captionCont = this.previousElementSibling;
				var captionNo = captionCont.querySelector('span:first-child');
				var captionFile = captionCont.querySelector('span:last-child');

				var isVal = false;
				if(this.value)
				{
					var parts = [];
					parts = this.value.replace(/\\/g, '/').split( '/' );
					captionFile.innerText = parts[parts.length-1];
					isVal = true;
				}

				captionNo.style.display = !isVal ? 'block' : 'none';
				captionFile.style.display = isVal ? 'block' : 'none';

			});
		});
	};

	this.addHiddenInputToForm = function(inputName, inputValue)
	{
		inputName = inputName || 'from';
		var formInput = this.webForm.form.querySelector('input[name="' + inputName + '"]');
		if (!formInput)
		{
			formInput = document.createElement('INPUT');
			formInput.type = 'hidden';
			formInput.name = inputName;
			this.webForm.form.appendChild(formInput);
		}
		formInput.value = inputValue;
	};

	this.isFrame = function()
	{
		return window != window.top;
	};

	this.isFrame = function()
	{
		return window != window.top;
	};

	this.captureKeyBoard = function()
	{
		if(!this.isFrame())
		{
			return;
		}

		var _this = this;
		BX.bind(document, 'keyup', function (e) {
			e = e || window.e;
			var kc = (typeof e.which == "number") ? e.which : e.keyCode;
			if (kc == 27)
			{
				_this.fireKeyBoardEvent(kc);
			}
		});
	};

	this.listenResizeEvent = function()
	{
		if(!this.isFrame())
		{
			this.tracking.onFormView();
			return;
		}

		if(typeof window.postMessage === 'function')
		{
			BX.bind(window, 'message', BX.proxy(function(event){
				if(event && event.origin == this.postMessageDomain)
				{
					var data = {};
					try { data = JSON.parse(event.data); } catch (err){}
					this.uniqueLoadId = data.uniqueLoadId;
					this.postMessageSource = event.source;
					this.postMessageOrigin = event.origin;
					this.fireResizeEvent();

					this.tracking.onFormView();
				}
			}, this));
		}
		else
		{
			var _this = this;
			setTimeout(function () {
				_this.tracking.onFormView();
			}, 1000);
		}

		this.fireResizeEvent();
	};

	this.sendDataToFrameHolder = function(data)
	{
		var encodedData = JSON.stringify(data);
		if(typeof window.postMessage === 'function')
		{
			if(this.postMessageSource)
			{
				this.postMessageSource.postMessage(
					encodedData,
					this.postMessageOrigin
				);
			}
		}

		var ie = 0 /*@cc_on + @_jscript_version @*/;
		if(ie)
		{
			var url = window.location.hash.substring(1);
			top.location = url.substring(0, url.indexOf('#')) + '#' + encodedData;
		}
	};

	this.fireRedirectEvent = function(url)
	{
		if(this.isFrame())
		{
			this.sendDataToFrameHolder({action: 'redirect', value: url});
		}
		else
		{
			window.location = url;
		}
	};

	this.fireResizeEvent = function()
	{
		if(!this.isFrame())
		{
			return;
		}

		this.sendDataToFrameHolder({uniqueLoadId: this.uniqueLoadId, action: 'change_height', value: this.getHeight()});
	};

	this.fireKeyBoardEvent = function(keyCode)
	{
		if(!this.isFrame())
		{
			return;
		}

		this.sendDataToFrameHolder({uniqueLoadId: this.uniqueLoadId, action: 'keyboard', value: keyCode});
	};

	this.fireFrameEvent = function(eventName, data)
	{
		if(!this.isFrame())
		{
			return;
		}

		this.sendDataToFrameHolder({uniqueLoadId: this.uniqueLoadId, action: 'event', "eventName": eventName, value: data});
	};

	this.fireAnalyticsEvent = function(data)
	{
		if(!this.isFrame())
		{
			return;
		}

		this.sendDataToFrameHolder({uniqueLoadId: this.uniqueLoadId, action: 'analytics', value: data});
	};

	this.getHeight = function()
	{
		return Math.ceil(BX.pos(document.querySelector('.content').parentNode).height);
	};
	
	this.getCurrentItems = function(field)
	{
		var items = [];
		var values = field.getValues();
		for(var i in field.params.items)
		{
			var item = field.params.items[i];
			if(BX.util.in_array(item.value, values))
			{
				items.push(item);
			}
		}
		
		return items;
	};

	this.createFormField = function(fieldName, tmplId)
	{
		var cont = BX.create({'tag': 'div'});
		cont.innerHTML = this.getTemplate(tmplId);
		cont = BX.findChild(cont);

		var target = BX('field_' + fieldName + '_CONT');//BX(tmplId);
		target.appendChild(cont);

		var element = cont.querySelector('[name]');
		var field = this.webForm.getField(fieldName);
		field.addElement(element);

		switch(field.type)
		{
			case 'file':
				this.initFileInputs([element]);
				break;

			case 'date':
				this.initDateControl(element, false);
				break;

			case 'datetime':
				this.initDateControl(element, true);
				break;

			case 'phone':
				this.initPhoneControlByDataInput(element);
				break;
		}

		this.fireResizeEvent();
	};
		
	this.getTemplate = function(id, replaceData)
	{
		var html = BX(id).innerHTML;
		if(!html)
		{
			return;
		}
		
		if(replaceData)
		{
			for(var placeHolder in replaceData)
			{
				html = html.replace(placeHolder, replaceData[placeHolder]);
			}
		}
		
		return html;
	};

	this.appendNodeByTemplate = function(container, templateId, replaceData, isInsertBefore)
	{
		isInsertBefore = isInsertBefore || false;
		var node = BX.create({'tag': 'div'});
		node.innerHTML = this.getTemplate(templateId, replaceData);
		node = BX.findChild(node);
		if(container)
		{
			if(!isInsertBefore)
				container.appendChild(node);
			else
				container.insertBefore(node, container.firstChild);
		}

		return node;
	};

	this.processAjaxSubmitResult = function(data)
	{
		this.disabled = false;

		var popupParams = {error: data.error, text: data.text};
		if(data.redirect)
		{
			popupParams.redirect = {
				delay: (this.redirectDelay === null ? data.redirectDelay : this.redirectDelay) + 1,
				url: data.redirect
			};
		}
		this.popup.show(popupParams);


		// send frame event if no errors
		if(data.error)
		{
			return;
		}

		var eventSendData = BX.ajax.prepareForm(this.webForm.form).data;
		for(var key in eventSendData)
		{
			if(!key || key == 'sessid')
			{
				delete eventSendData[key];
			}
		}
		this.fireFrameEvent('send', [eventSendData]);
	};

	this.onAjaxSubmitSuccess = function(data)
	{
		this.tracking.onFormSent();
		this.processAjaxSubmitResult(data);
	};

	this.onAjaxSubmitFailure = function(data)
	{
		data = BX.type.isPlainObject(data) ? data : {};
		data.error = true;
		this.processAjaxSubmitResult(data);
	};

	this.onSubmitSuccess = function(e)
	{
		var sessionIdInput = this.webForm.form.querySelector('input[name="sessid"]');
		if(!sessionIdInput)
		{
			sessionIdInput = document.createElement('input');
			sessionIdInput.type = 'hidden';
			sessionIdInput.name = 'sessid';
			this.webForm.form.appendChild(sessionIdInput);
		}
		sessionIdInput.value = BX.bitrix_sessid();

		if(!this.licence.isAccepted())
		{
			BX.PreventDefault(e);
			return false;
		}

		if(!this.postAjax)
		{
			return true;
		}

		BX.PreventDefault(e);

		if(this.disabled)
		{
			return false;
		}
		else
		{
			this.popup.show({wait: true});
			this.disabled = true;
		}

		var preparedFormData = BX.ajax.prepareForm(this.webForm.form);
		if (preparedFormData.filesCount > 0)
		{
			BX.ajax.submitAjax(
				this.webForm.form,
				{
					'dataType': 'json',
					'method': 'POST',
					'onsuccess': BX.proxy(this.onAjaxSubmitSuccess, this),
					'onfailure': BX.proxy(this.onAjaxSubmitFailure, this)
				}
			);
		}
		else
		{
			BX.ajax({
				start: true,
				url: this.webForm.form.getAttribute("action"),
				method: 'POST',
				data: preparedFormData.data,
				dataType: 'json',
				processData: true,
				//preparePost: true,
				'onsuccess': BX.proxy(this.onAjaxSubmitSuccess, this),
				'onfailure': BX.proxy(this.onAjaxSubmitFailure, this)
			});
		}
	};

	this.onShow = function(name){
		var element = BX('field_' + name);
		BX.removeClass(element, 'crm-webform-hide');
		this.fireResizeEvent();
	};
	this.onHide = function(name){
		var element = BX('field_' + name);
		BX.addClass(element, 'crm-webform-hide');
		this.fireResizeEvent();
	};
	this.onFocus = function(name){
		var element = BX('field_' + name);
		BX.addClass(element, 'crm-webform-active');

		this.tracking.onFieldFocus();
	};
	this.onBlur = function(name, field){
		var element = BX('field_' + name);
		BX.removeClass(element, 'crm-webform-active');

		this.tracking.onFieldBlur(name, field);
		this.fireFrameEvent('fill', [name, field.getValues()]);
	};
	this.onCheck = function(name, elements, isSuccess, errorCode){
		var element = BX('field_' + name);
		if(isSuccess)
		{
			BX.removeClass(element, 'crm-webform-error');
			if(isSuccess == 1)
			{
				BX.addClass(element, 'crm-webform-success');
			}
			else
			{
				BX.removeClass(element, 'crm-webform-success');
			}					
		}
		else
		{
			BX.removeClass(element, 'crm-webform-success');
			BX.addClass(element, 'crm-webform-error');
		}
	};

	this.onChange = function(name, field)
	{
		// if changes product, change image
		/*
		if(name == 'PRODUCT')
		{
			var currentItems = this.getCurrentItems(field);
			if(currentItems && currentItems[0] && currentItems[0].image)
			{
				BX('summary_image').src = currentItems[0].image;
				BX('summary_image').display = 'block';
			}
			else
			{
				BX('summary_image').display = 'none';
			}			
		}
		*/

		if(!this.currency)
		{
			return;
		}

		if(!this.carts)
		{
			this.carts = [];
			var attributeCart = 'data-bx-webform-cart';
			var cartNodes = document.querySelectorAll('[' + attributeCart + ']');
			cartNodes = BX.convert.nodeListToArray(cartNodes);
			cartNodes.forEach(function(cartNode){
				var isMini = cartNode.getAttribute(attributeCart) == 'mini';
				var itemsNode = cartNode.querySelector('[data-bx-webform-cart-items]');
				var totalNode = cartNode.querySelector('[data-bx-webform-cart-total]');
				if(!itemsNode || !totalNode)
				{
					return;
				}

				this.carts.push({
					'itemsNode': itemsNode,
					'totalNode': totalNode,
					'isMini': isMini
				});
			}, this);
		}

		// if options changed, change price
		if(!field.params['items'] || !field.params.items[0] || !field.params.items[0]['price'])
		{
			return;
		}
		
		var items = [];
		this.webForm.getFields().forEach(function(field){
			var currentItems = this.getCurrentItems(field);
			if(currentItems.length == 0 || !currentItems[0]['price'])
			{
				return;
			}

			items = BX.util.array_merge(items, currentItems);
		}, this);
		
		var summaryPrice = 0;
		var itemHtmlList = [];
		var itemMiniHtmlList = [];
		items.forEach(function(item){
			summaryPrice = summaryPrice + parseFloat(item.price);
			var formattedPrice = item.price_formatted ? item.price_formatted : item.price;
			var replaceData = {'%name%': item.title, '%price%': formattedPrice};
			itemHtmlList.push(this.getTemplate('product_price_item', replaceData));
			itemMiniHtmlList.push(this.getTemplate('product_price_mini_item', replaceData));
		}, this);

		this.carts.forEach(function(cart){
			var summaryPricePrint = BX.util.number_format(
				summaryPrice,
				this.currency.DECIMALS,
				this.currency.DEC_POINT,
				this.currency.THOUSANDS_SEP
			);
			cart.totalNode.innerHTML = this.currency.FORMAT_STRING.replace('#', summaryPricePrint);;
			cart.itemsNode.innerHTML = cart.isMini ? itemMiniHtmlList.join(' ') : itemHtmlList.join(' ');
		}, this);

		this.fireResizeEvent();
	};

	this.disableButton = function(disable)
	{
		disable = disable || false;
		this.submitButtonNodes.forEach(function(btn){
			if(disable)
			{
				BX.addClass(btn, 'crm-webform-submit-button-loader');
				BX.addClass(btn, 'crm-webform-submit-button-loader-customize');
			}
			else
			{
				BX.removeClass(btn, 'crm-webform-submit-button-loader');
				BX.removeClass(btn, 'crm-webform-submit-button-loader-customize');
			}
		});
	};

	this.init(params);
}

function CrmWebFormTracking(params)
{
	this.init = function(params)
	{
		this.caller = params.caller;

		this.yaId = params.ya || null;
		this.gaId = params.ga || null;
		this.gaPageView = !!params.gaPageView;
		this.data = params.data;

		this.isStartFillTracked = false;
		this.filledFields = [];
		this.processedCounters = [];
	};

	this.trackByData = function(actionName)
	{
		if(!this.data[actionName])
		{
			return;
		}

		this.track(
			this.data[actionName].name,
			this.data[actionName].code
		);
	};

	this.track = function(action, page)
	{
		action = action || '';
		page = page || '';

		var gaEventCategory = this.data.category;
		var gaEventAction = this.data.template.name.replace('%name%', action);
		var gaPageName = this.data.template.code.replace('%code%', page);
		if(this.gaId && window.ga)
		{
			//add google event
			window.ga('send', 'event', gaEventCategory, gaEventAction);
			if(this.gaPageView && page)
			{
				//add google page view
				window.ga('send', 'pageview', gaPageName);
			}
		}
		this.caller.fireAnalyticsEvent([
			{ type: 'ga', 'gaId': this.gaId, params: ['event', gaEventCategory, gaEventAction] },
			(this.gaPageView && page) ? { type: 'ga', 'gaId': this.gaId, params: ['pageview', gaPageName] } : null
		]);

		//add yametric event
		this.trackYa(action, page);
	};

	this.trackYa = function (action, page)
	{
		var eventName = this.data.eventTemplate.code
			.replace('%code%', page)
			.replace('%form_id%', this.caller.id);

		if (this.yaId)
		{
			if (!window['yaCounter' + this.yaId])
			{
				var _this = this;
				setTimeout(function () {
					_this.trackYa(action, page);
				}, 100);
				return;
			}

			window['yaCounter' + this.yaId].reachGoal(eventName);
		}

		this.caller.fireAnalyticsEvent([{ type: 'ya', 'yaId': this.yaId, params: [eventName] }]);
	};

	this.onFieldBlur = function(fieldName, field)
	{
		if(BX.util.in_array(fieldName, this.filledFields))
		{
			return;
		}

		if(field.isEmpty())
		{
			return;
		}

		this.filledFields.push(fieldName);
		this.track(
			this.data.field.name.replace('%name%', field.caption),
			this.data.field.code.replace('%code%', fieldName)
		);
	};

	this.onFieldFocus = function()
	{
		if(!this.isStartFillTracked)
		{
			this.trackByData('start');
			this.isStartFillTracked = true;
		}

		this.incBxCounter('start');
	};

	this.onFormSent = function()
	{
		this.trackByData('end');
	};

	this.onFormView = function()
	{
		this.trackByData('view');
		this.incBxCounter('view');
	};

	this.incBxCounter = function(code)
	{
		if(BX.util.in_array(code, this.processedCounters) || !this.caller.form)
		{
			return;
		}

		this.processedCounters.push(code);
		var _this = this;

		BX.ajax({
			url: this.caller.form.action,
			method: 'POST',
			data: {
				hash: this.caller.hash,
				sessid: BX.bitrix_sessid(),
				action: 'inc_counter',
				counter: code
			},
			processData: false,
			onfailure: function(){
				var ind = BX.util.array_search(code, _this.processedCounters);
				if(ind < 0) return;
				BX.util.deleteFromArray(_this.processedCounters, ind);
			}
		});
	};

	this.init(params);
}

function CrmWebFormLicence(params)
{
	this.init = function (params)
	{
		this.caller = params.caller;
		this.required = false;
		this.submitAfterAccept = false;

		this.licenceAcceptNode = BX('licence_accept');
		this.licenceShowButton = BX('licence_show_button');
		this.licencePopupBtnAccept = BX('RESULT_BUTTON_BNT_ACCEPT');
		this.licencePopupBtnCancel = BX('RESULT_BUTTON_BNT_CANCEL');
		if(!this.licenceAcceptNode || !this.licenceShowButton)
		{
			return;
		}

		this.required = true;
		BX.bind(this.licenceShowButton, 'click', BX.proxy(this.showPopup, this));
		BX.bind(this.licencePopupBtnAccept, 'click', BX.proxy(function(){
			this.closePopup(true);
		}, this));
		BX.bind(this.licencePopupBtnCancel, 'click', BX.proxy(function(){
			this.closePopup(false);
		}, this));
	};

	this.showPopup = function()
	{
		this.caller.popup.show({
			error: false,
			text: this.caller.mess.licencePre,
			licence: true
		});
	};

	this.closePopup = function(isAccepted)
	{
		this.licenceAcceptNode.checked = isAccepted;
		this.caller.popup.hide();

		if(isAccepted && this.submitAfterAccept)
		{
			this.caller.webForm.submit();
		}
		this.submitAfterAccept = false;
	};

	this.isAccepted = function()
	{
		if(!this.required || this.licenceAcceptNode.checked)
		{
			return true;
		}

		this.submitAfterAccept = true;
		this.showPopup();
		return false;
	};

	this.init(params);
}

function CrmWebFormPopup(params)
{
	this.init = function (params)
	{
		this.caller = params.caller;
		this.initNode();
	};

	this.initNode = function ()
	{
		if(this.node)
		{
			return;
		}

		this.caller.appendNodeByTemplate(document.body, 'tmpl_result_message', null, true);
		this.node = BX('RESULT_MESSAGE_CONTAINER');

		this.btnContainer = BX('RESULT_BUTTON_CONTAINER');
		this.messageContent = BX('RESULT_MESSAGE_CONTENT');
		this.messageContentLoader = BX('RESULT_MESSAGE_CONTENT_LOADER');
		this.messageContentLoader = BX('RESULT_MESSAGE_CONTENT_LOADER');

		this.nodeSuccess = this.messageContent.querySelector('.crm-webform-popup-success');
		this.nodeWarning = this.messageContent.querySelector('.crm-webform-popup-warning');
		this.nodeLicence = this.messageContent.querySelector('.crm-webform-popup-licence');
		this.nodeText = this.messageContent.querySelector('.crm-webform-popup-text');

		this.btnRedirectContainer = BX('RESULT_BUTTON_REDIRECT_CONTAINER');
		this.btnRedirectNode = BX('RESULT_BUTTON_REDIRECT_BNT');
		this.btnLicenceContainer = BX('RESULT_BUTTON_LICENCE_CONTAINER');

		this.btnResult = BX('RESULT_BUTTON_BNT');

		BX.bind(this.btnResult, 'click', BX.proxy(this.hide, this));
	};

	this.showLoader = function ()
	{

	};

	this.animateRedirect = function(redirect)
	{
		redirect.delay--;
		BX('RESULT_BUTTON_REDIRECT_COUNTER').innerText = redirect.delay;
		if(redirect.delay > 0)
		{
			var _this = this;
			setTimeout(function(){
				_this.animateRedirect(redirect);
			}, 1000);
			return;
		}

		this.caller.fireRedirectEvent(redirect.url);
	};

	this.show = function (data)
	{
		var isError = data.error || false;
		var isWait = data.wait || false;

		this.caller.disableButton(isWait);
		this.btnContainer.style.display = isWait ? 'none' : 'block';
		this.messageContent.style.display = isWait ? 'none' : 'block';
		this.messageContentLoader.style.display = !isWait ? 'none' : 'block';
		this.nodeLicence.style.display = 'none';
		this.btnLicenceContainer.style.display = 'none';

		if(this.nodeSuccess)
		{
			this.nodeSuccess.style.display = isError ? 'none' : 'block';
		}
		this.nodeWarning.style.display = !isError ? 'none' : 'block';

		if(!isWait)
		{
			if(data.licence)
			{
				this.btnContainer.style.display = 'none';
				this.btnRedirectContainer.style.display = 'none';
				this.btnLicenceContainer.style.display = 'block';
				this.nodeLicence.style.display = 'block';
			}
			else if(data.redirect)
			{
				this.btnContainer.style.display = 'none';
				this.btnRedirectContainer.style.display = 'block';
				var _this = this;
				BX.bind(this.btnRedirectNode, 'click', function(){
					_this.caller.fireRedirectEvent(data.redirect.url);
				});
			}
			else
			{
				this.btnContainer.style.display = 'block';
				this.btnLicenceContainer.style.display = 'none';
				this.btnResult.focus();
			}

			var text = data.text;
			if(!text)
			{
				text = isError ? this.caller.mess.sentError : this.caller.mess.sentSuccess;
			}
			this.nodeText.innerText = text;
		}

		this.node.style.display = 'block';

		if(data.redirect)
		{
			this.animateRedirect(data.redirect);
		}
	};

	this.hide = function ()
	{
		this.node.style.display = 'none';
	};

	this.init(params);
}