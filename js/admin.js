jQuery(document).ready(function($) {
	
	var data_tag = jQuery('input[name="pdf-forms-for-wpforms-form-settings[data]"]');
	var preload_data_tag = jQuery('div[class="preload-data"]');
	
	// https://github.com/uxitten/polyfill/blob/master/string.polyfill.js
	// https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/String/padEnd
	if (!String.prototype.padEnd) {
		String.prototype.padEnd = function padEnd(targetLength,padString) {
			targetLength = targetLength>>0; //floor if number or convert non-number to 0;
			padString = String((typeof padString !== 'undefined' ? padString : ' '));
			if (this.length > targetLength) {
				return String(this);
			}
			else {
				targetLength = targetLength-this.length;
				if (targetLength > padString.length) {
					padString += padString.repeat(targetLength/padString.length); //append to original to ensure we are longer than needed
				}
				return String(this) + padString.slice(0,targetLength);
			}
		};
	}
	
	// Object assign polyfill, courtesy of https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Object/assign
	if (typeof Object.assign !== 'function')
	{
		// Must be writable: true, enumerable: false, configurable: true
		Object.defineProperty(Object, "assign", {
			value: function assign(target, varArgs) { // .length of function is 2
				'use strict';
				if (target === null || target === undefined) {
					throw new TypeError('Cannot convert undefined or null to object');
				}
				
				var to = Object(target);
				
				for (var index = 1; index < arguments.length; index++) {
					var nextSource = arguments[index];
					
					if (nextSource !== null && nextSource !== undefined) {
						for (var nextKey in nextSource) {
							// Avoid bugs when hasOwnProperty is shadowed
							if (Object.prototype.hasOwnProperty.call(nextSource, nextKey)) {
							  to[nextKey] = nextSource[nextKey];
							}
						}
					}
				}
				return to;
			},
			writable: true,
			configurable: true
		});
	}
	
	jQuery.fn.select2.amd.define("pdf-forms-for-wpforms-shared-data-adapter", 
	['select2/data/array','select2/utils'],
		function (ArrayData, Utils) {
			function CustomData($element, options) {
				CustomData.__super__.constructor.call(this, $element, options);
			}
			
			Utils.Extend(CustomData, ArrayData);
			
			CustomData.prototype.query = function (params, callback) {
				
				var options = this.options.options;
				var items = select2SharedData[options.sharedDataElement];
				if(options.hasOwnProperty('sharedDataElementId'))
					items = items[options.sharedDataElementId];
				
				var pageSize = 20;
				if(!("page" in params))
					params.page = 1;
				
				var totalNeeded = params.page * pageSize;
				
				if(params.term && params.term !== '')
				{
					var upperTerm = params.term.toLowerCase();
					var count = 0;
					
					items = items.filter(function(item) {
						
						// don't filter any more items if we have collected enough
						if(count > totalNeeded)
							return false;
						
						if(!item.hasOwnProperty("lowerText"))
							item.lowerText = item.text.toLowerCase();
						
						var counts = item.lowerText.indexOf(upperTerm) >= 0;
						
						if(counts)
							count++;
						
						return counts;
					});
				}
				
				if(options.tags === true)
				{
					var currentValue = this.$element.val();
					var tag = params.term && params.term != '' ? params.term : currentValue ? currentValue : "";
					var lowerTag = String(tag).toLowerCase();
					
					var exists = false;
					if(lowerTag != "")
						jQuery.each(items, function(index, item) {
							if(item.lowerText == lowerTag) {
								exists = true;
								return false; // break
							}
						});
					
					if(!exists) {
						items = Object.assign([], items); // shallow copy
						items.unshift({id: tag, text: tag, lowerText: lowerTag});
					}
				}
				
				var more = items.length > totalNeeded;
				
				items = items.slice((params.page - 1) * pageSize, totalNeeded); // paginate
				
				callback({
					results: items,
					pagination: { more: more }
				});
			};
			
			return CustomData;
		}
	);
	
	// TODO: add custom select2DataAdapter for wpfFields choices that doesn't use a select2SharedData
	
	jQuery.fn.initializeMultipleSelect2Field = function(attachment_options) {
		
		if(!jQuery(this).data('option'))
			return;
		
		var class_name = this[0].className;
		var option = jQuery(this).data('option');
		var attachment_id = jQuery(this).data('attachment_id');
		
		if(attachment_id === undefined || !class_name)
			return;
		
		jQuery(this)
			.removeClass(class_name).addClass(class_name + '--' + attachment_id)
			.select2({
				ajax: {},
				width: '100%',
				dropdownAutoWidth: true,
				sharedDataElement: option,
				dropdownParent: jQuery('#wpforms-builder'),
				dataAdapter: jQuery.fn.select2.amd.require("pdf-forms-for-wpforms-shared-data-adapter")
			})
			.change(function() {
				var attachment_id = jQuery(this).data('attachment_id');
				var value = jQuery(this).val(), option = jQuery(this).data('option');
				setAttachmentOption(attachment_id, option, value);
			});
		
		var select2Data = select2SharedData[option];
		var selected_options = attachment_options[option];
		
		// TODO: optimize
		for(var i = 0; i < selected_options.length; ++i)
			for(var j = 0; j < select2Data.length; ++j)
				if(selected_options[i] == select2Data[j].id)
				{
					var text = select2Data[j].text, id = select2Data[j].id;
					jQuery(this).append( new Option(text, id, true, true) );
				}
		
		jQuery(this).val(selected_options).trigger('change');
	}
	
	jQuery.fn.resetSelect2Field = function(id) {
		
		if(typeof id == 'undefined')
			id = null;
		
		if(!jQuery(this).data('select2'))
			return;
		
		jQuery(this).empty();
		
		var select2Data = select2SharedData[this.data().select2.options.options.sharedDataElement];
		if(select2Data.length > 0)
		{
			var optionInfo = select2Data[id !== null ? id : 0];
			var option = new Option(optionInfo.text, optionInfo.id, true, true);
			jQuery(this).append(option).val(optionInfo.id);
			
			// TODO fix
			jQuery(this).trigger('change');
			jQuery(this).trigger({
				type: 'select2:select',
				params: {
					data: optionInfo
				}
			});
		}
		else
			jQuery(this).trigger('change');
		
		return this;
	}
	
	var pdfFields = [],
		attachmentData = {},
		defaultPdfOptions = {};
	
	var pluginData = {
		attachments: [],
		mappings: [],
		value_mappings: [],
		embeds: []
	};
	
	var select2SharedData = {
		unmappedPdfFields: [],
		wpfFieldsCache: [],
		pdfSelect2Files: [{id: 0, text: pdf_forms_for_wpforms.__All_PDFs, lowerText: String(pdf_forms_for_wpforms.__All_PDFs).toLowerCase()}],
		pageList: [],
		notifications: [],
		confirmations: [],
		wpfFieldsChoices: [],
	};
	
	jQuery('.pdf-forms-for-wpforms-admin .wpf-field-smarttag-list').select2({
		ajax: {},
		width: '100%',
		sharedDataElement: "wpfFieldsCache",
		dropdownParent: jQuery('#wpforms-builder'),
		dataAdapter: jQuery.fn.select2.amd.require("pdf-forms-for-wpforms-shared-data-adapter")
	}).on('select2:select', function (e) {
		var data = e.params.data;
		jQuery(this).find('option:selected').attr('data-smarttags', data['smarttag']);
	});
	
	jQuery('.pdf-forms-for-wpforms-admin .pdf-field-list').select2({
		ajax: {},
		width: '100%',
		sharedDataElement: "unmappedPdfFields",
		dropdownParent: jQuery('#wpforms-builder'),
		dataAdapter: jQuery.fn.select2.amd.require("pdf-forms-for-wpforms-shared-data-adapter")
	});
	
	jQuery('.pdf-forms-for-wpforms-admin .pdf-files-list').select2({
		ajax: {},
		width: '100%',
		dropdownAutoWidth: true,
		sharedDataElement: "pdfSelect2Files",
		dropdownParent: jQuery('#wpforms-builder'),
		dataAdapter: jQuery.fn.select2.amd.require("pdf-forms-for-wpforms-shared-data-adapter")
	});
	jQuery('.pdf-forms-for-wpforms-admin .page-list').select2({
		ajax: {},
		width: '100%',
		dropdownAutoWidth: true,
		sharedDataElement: "pageList",
		dropdownParent: jQuery('#wpforms-builder'),
		dataAdapter: jQuery.fn.select2.amd.require("pdf-forms-for-wpforms-shared-data-adapter")
	});
	
	var clearMessages = function()
	{
		jQuery('.pdf-forms-for-wpforms-admin .messages').empty();
	};
	
	var errorMessage = function(msg)
	{
		if(!msg)
			msg = pdf_forms_for_wpforms.__Unknown_error;
		jQuery('.pdf-forms-for-wpforms-admin .messages').append(
			jQuery('<div class="error"/>').text(msg)
		);
		location.href = '#wpforms-pdf-form-messages';
	};
	
	var warningMessage = function(msg)
	{
		jQuery('.pdf-forms-for-wpforms-admin .messages').append(
			jQuery('<div class="warning"/>').text(msg)
		);
		location.href = '#wpforms-pdf-form-messages';
	};
	
	var successMessage = function(msg)
	{
		jQuery('.pdf-forms-for-wpforms-admin .messages').append(
			jQuery('<div class="updated"/>').text(msg)
		);
		location.href = '#wpforms-pdf-form-messages';
	};
	
	var strtr = function(str, replacements)
	{
		for(i in replacements)
			if(replacements.hasOwnProperty(i))
				str = str.replace(i, replacements[i]);
		return str;
	}
	
	var base64urldecode = function(data)
	{
		return window.atob(strtr(data, {'.': '+', '_': '/'}).padEnd(data.length % 4, '='));
	}
	
	var getPdfFieldData = function(id)
	{
		for (var i = 0, l = pdfFields.length; i < l; ++i)
			if (pdfFields[i].id == id)
				return pdfFields[i];
		
		return null;
	};
	
	var getUnmappedPdfFields = function()
	{
		var pdf_fields = [];
		var mappings = getMappings();
		
		jQuery.each(pdfFields, function(f, field) {
			
			var field_pdf_field = String(field.id);
			var field_attachment_id = field_pdf_field.substr(0, field_pdf_field.indexOf('-'));
			var field_pdf_field_name = field_pdf_field.substr(field_pdf_field.indexOf('-')+1);
			
			for(var i=0, l=mappings.length; i<l; i++)
			{
				var mapping_pdf_field = String(mappings[i].pdf_field);
				var mapping_attachment_id = mapping_pdf_field.substr(0, mapping_pdf_field.indexOf('-'));
				var mapping_pdf_field_name = mapping_pdf_field.substr(mapping_pdf_field.indexOf('-')+1);
				
				if( (mapping_attachment_id == 'all' || field_attachment_id == 'all' || mapping_attachment_id == field_attachment_id)
					&& mapping_pdf_field_name == field_pdf_field_name)
					return;
			}
			
			pdf_fields.push(field);
		});
		
		return pdf_fields;
	};
	
	var reloadPdfFields = function()
	{
		var pdfFieldsA = [];
		var pdfFieldsB = [];
		
		var attachments = getAttachments();
		jQuery.each(attachments, function(a, attachment) {
			
			var info = getAttachmentInfo(attachment.attachment_id);
			if(!info || !info.fields)
				return;
			
			jQuery.each(info.fields, function(f, field) {
				
				// sanity check
				if(!field.hasOwnProperty('name') || !field.hasOwnProperty('type'))
					return;
				
				var name = String(field.name);
				var type = String(field.type);
				
				// sanity check
				if(!(type === 'text' || type === 'radio' || type === 'select' || type === 'checkbox'))
					return;
				
				var all_attachment_data = Object.assign({}, field); // shallow copy
				var current_attachment_data = Object.assign({}, field); // shallow copy
				
				all_attachment_data['id'] = 'all-' + field.id;
				all_attachment_data['text'] = name;
				all_attachment_data['attachment_id'] = 'all';
				
				current_attachment_data['id'] = attachment.attachment_id + '-' + field.id;
				current_attachment_data['text'] = '[' + attachment.attachment_id + '] ' + name;
				current_attachment_data['attachment_id'] = attachment.attachment_id;
				
				pdfFieldsA.push(all_attachment_data);
				pdfFieldsB.push(current_attachment_data);
				
			});
			
			var ids = [];
			pdfFields = [];
			
			jQuery.each(pdfFieldsA.concat(pdfFieldsB), function(f, field) {
				if(ids.indexOf(field.id) == -1)
				{
					ids.push(field.id);
					field.lowerText = String(field.text).toLowerCase();
					pdfFields.push(field);
				}
			});
			
			runWhenDone(refreshPdfFields);
		});
	};
	
	var refreshPdfFields = function()
	{
		select2SharedData.unmappedPdfFields = getUnmappedPdfFields(); // TODO: optimize this
		jQuery('.pdf-forms-for-wpforms-admin .pdf-field-list').resetSelect2Field();
		updateFieldHint();
	};
	
	var wpformsFormToObject = function(fieldPrefix)
	{
		var object = {};
		
		// extract object from form inputs
		jQuery('#wpforms-builder-form').serializeArray().forEach(function(item) {
			
			if(item.name.substr(0,fieldPrefix.length) !== fieldPrefix)
				return;
			
			var mantissa = item.name.substr(fieldPrefix.length);
			var regex = new RegExp('\\[([^[]*)?\\]','g');
			var matches = mantissa.match(regex);
			matches = matches.map(function(item){ return item.substr(1,item.length-2) });
			
			var obj = object;
			var key = matches.shift();
			matches.forEach(function(k) {
				if(!obj.hasOwnProperty(key))
					obj[key] = {};
				obj = obj[key];
				key = k;
			});
			obj[key] = item.value;
			
		});
		
		return object;
	};
	
	var getWpformsNotifications = function()
	{
		return wpformsFormToObject("settings[notifications]");
	}
	
	var getWpformsConfirmations = function()
	{
		return wpformsFormToObject("settings[confirmations]");
	}
	
	var precomputeWpfSelect2Cache = function() {
		
		var wpfSelect2Cache = [];
		
		jQuery('.pdf-forms-for-wpforms-admin .wpf-field-list option').each(function(i) {
			var id = jQuery(this).val();
			if(id === "") return; // skip "--- select field ---"
			var label = jQuery(this).text();
			var field = {
				id: id,
				text: label,
				lowerText: label.toLowerCase(),
				smarttag: false
			};
			wpfSelect2Cache.push(field);
		});
		
		var smartTags = [
			 '{date format="m/d/Y"}'
			,'{entry_id}'
			,'{unique_value}'
			,pdf_forms_for_wpforms.__Custom_String
			,'https://embed.image.url/{page_url}'
			,'{user_ip}'
			,'{page_url}'
			,'{page_id}'
			,'{page_title}'
			,'{author_display}'
			,'{author_email}'
			,'{admin_email}'
			,'{user_id}'
			,'{user_email}'
		];
		
		jQuery.each(smartTags, function(i, smartTag) {
			wpfSelect2Cache.push({
				id: smartTag,
				text: smartTag,
				lowerText: String(smartTag).toLowerCase(),
				smarttag: true
			});
		});
		
		select2SharedData.wpfFieldsCache = wpfSelect2Cache;
	};
	
	var getWpfFieldData = function(id)
	{
		try
		{
			var field = Object.assign({}, wpf.getField(id)); // shallow copy
			var name = "Field #" + id;
			if(field.hasOwnProperty('label') && field.label != "")
				name = field.label;
			field['name'] = name;
			field['text'] = name;
			return field;
		}
		catch(e)
		{
			return null;
		}
	};
	
	var refreshWpfFields = function() {
		precomputeWpfSelect2Cache();
		
		jQuery('.pdf-forms-for-wpforms-admin .wpf-field-smarttag-list').resetSelect2Field();
	};
	
	var getData = function(field)
	{
		return pluginData[field];
	};
	
	var setData = function(field, value)
	{
		pluginData[field] = value;
		runWhenDone(updatePluginDataField);
	};
	
	var updatePluginDataField = function()
	{
		// we can't use json directly, we have to use encoding without slashes and quotes due to an unnecessary stripslashes() call in wpforms/includes/admin/builder/panels/class-base.php
		data_tag.val(btoa(JSON.stringify(pluginData)));
	}
	
	var getAttachments = function()
	{
		var attachments = getData('attachments');
		if(attachments)
			return attachments;
		else
			return [];
	};
	
	var getAttachment = function(attachment_id)
	{
		var attachments = getAttachments();
		
		for(var i=0, l=attachments.length; i<l; i++)
			if(attachments[i].attachment_id == attachment_id)
				return attachments[i];
		
		return null;
	};
	
	var setAttachments = function(attachments)
	{
		setData('attachments', attachments);
		reloadPdfFields();
	};
	
	var deleteAttachment = function(attachment_id)
	{
		var remove_ids = [];
		var mappings = getMappings();
		jQuery.each(mappings, function(index, mapping) {
			var field_attachment_id = mapping.pdf_field.substr(0, mapping.pdf_field.indexOf('-'));
			if(field_attachment_id == attachment_id)
				remove_ids.push(mapping.mapping_id);
		});
		jQuery.each(remove_ids, function(index, id) { deleteMapping(id); });
		
		remove_ids = [];
		var embeds = getEmbeds();
		jQuery.each(embeds, function(index, embed) {
			if(embed.attachment_id == attachment_id)
				remove_ids.push(embed.id);
		});
		jQuery.each(remove_ids, function(index, id) { deleteEmbed(id); });
		
		var attachments = getAttachments();
		
		for(var i=0, l=attachments.length; i<l; i++)
			if(attachments[i].attachment_id == attachment_id)
			{
				attachments.splice(i, 1);
				break;
			}
		
		setAttachments(attachments);
		
		for(var i = 0, l = select2SharedData.pdfSelect2Files.length; i < l; i++)
			if(select2SharedData.pdfSelect2Files[i].id == attachment_id)
			{
				select2SharedData.pdfSelect2Files.splice(i, 1);
				break;
			}
		
		deleteAttachmentData(attachment_id);
		
		refreshMappings();
		refreshEmbeds();
		refreshPdfFilesList();
	};
	
	var setAttachmentOption = function(attachment_id, option, value) {
		
		var attachments = getAttachments();
		
		for(var i=0, l=attachments.length; i<l; i++)
			if(attachments[i].attachment_id == attachment_id)
			{
				if(typeof attachments[i].options == 'undefined'
				|| attachments[i].options == null)
					attachments[i].options = {};
				attachments[i].options[option] = value;
				break;
			}
		
		setAttachments(attachments);
	};
	
	var getAttachmentData = function(attachment_id)
	{
		return attachmentData[attachment_id];
	}
	var getAttachmentInfo = function(attachment_id)
	{
		var data = getAttachmentData(attachment_id);
		if(!data || !data.info)
			return;
		return data.info;
	}

	var setAttachmentData = function(attachment_id, data)
	{
		attachmentData[attachment_id] = data;
	}
	var deleteAttachmentData = function(attachment_id)
	{
		delete attachmentData[attachment_id];
	}
	
	var addAttachment = function(data)
	{
		var attachment_id = data.attachment_id;
		
		var info = getAttachmentData(attachment_id);
		if(!info)
			return;
		
		var filename = info.filename;
		var options = data.options;
		
		var attachments = getAttachments();
		attachments.push( data );
		setAttachments(attachments);
		
		jQuery('.pdf-forms-for-wpforms-admin .instructions').remove();
		
		var template = jQuery('.pdf-forms-for-wpforms-admin .pdf-attachment-row-template');
		var tag = template.clone().removeClass('pdf-attachment-row-template').addClass('pdf-attachment-row');
		
		tag.find('.pdf-filename').text('['+attachment_id+'] '+filename);
		tag.find('.pdf-options input, .pdf-options select').data('attachment_id', attachment_id);
		
		if(typeof options != 'undefined' && options !== null)
		{
			tag.find('.pdf-options input[type=checkbox]').each(function() {
				var option = jQuery(this).data('option');
				jQuery(this)[0].checked = (options[option] !== false);
			});
			tag.find('.pdf-options input[type=text]').each(function() {
				var option = jQuery(this).data('option');
				jQuery(this).val(options[option]);
			});
			tag.find('.pdf-options select.notifications-list, .pdf-options select.confirmations-list').each(function() {
				jQuery(this).initializeMultipleSelect2Field(options);
			});
			
			// set unique ids
			tag.find('.pdf-option-save-directory label').attr('for', 'pdf-option-save-directory-smart-tags-'+attachment_id);
			tag.find('.pdf-option-save-directory input.smart-tags').attr('id', 'pdf-option-save-directory-smart-tags-'+attachment_id);
			tag.find('.pdf-option-filename label').attr('for', 'pdf-option-filename-smart-tags-'+attachment_id);
			tag.find('.pdf-option-filename input.smart-tags').attr('id', 'pdf-option-filename-smart-tags-'+attachment_id);
		}
		
		tag.find('.pdf-options input[type=checkbox]').change(function() {
			var attachment_id = jQuery(this).data('attachment_id');
			var option = jQuery(this).data('option');
			setAttachmentOption(attachment_id, option, jQuery(this)[0].checked);
		});
		tag.find('.pdf-options input[type=text]').change(function() {
			var attachment_id = jQuery(this).data('attachment_id');
			var option = jQuery(this).data('option');
			setAttachmentOption(attachment_id, option, jQuery(this).val());
		});
		tag.find('.pdf-options-button').click(function() {
			jQuery(this).closest('.pdf-attachment-row').find('.pdf-options').toggle('.pdf-options-hidden');
		});
		
		var delete_button = tag.find('.delete-button');
		delete_button.data('attachment_id', attachment_id);
		delete_button.click(function(event) {
			
			// prevent running default button click handlers
			event.stopPropagation();
			event.preventDefault();
			
			if(!confirm(pdf_forms_for_wpforms.__Confirm_Delete_Attachment))
				return;
			
			var attachment_id = jQuery(this).data('attachment_id');
			if(!attachment_id)
				return false;
			
			deleteAttachment(attachment_id);
			
			tag.remove();
			
			jQuery('.pdf-forms-for-wpforms-admin .pdf-files-list option[value='+attachment_id+']').remove();
			
			return false;
		});
		
		jQuery('.pdf-forms-for-wpforms-admin .pdf-attachments tr.pdf-buttons').before(tag);
		// TODO: remove item when attachment is deleted
		// better TODO: use shared list (attachmentData)
		select2SharedData.pdfSelect2Files.push({
			id: attachment_id,
			text: '[' + attachment_id + '] ' + filename,
			lowerText: String('[' + attachment_id + '] ' + filename).toLowerCase()
		});
		
		refreshPdfFilesList();
		
		jQuery('.pdf-forms-for-wpforms-admin .help-button').each(function() {
			var button = jQuery(this);
			var helpbox = button.parent().find('.helpbox');
			hideHelp(button, helpbox);
		});
	};
	
	var preloadData = function()
	{
		if(!WPFormsBuilder.settings.formID)
			return errorMessage(pdf_forms_for_wpforms.__No_Form_ID);
		
		refreshNotificationsFields();
		refreshConfirmationsFields();
		
		// trigger field update to load fields into select.wpf-field-list
		// TODO: figure out how to eliminate the need to do this
		jQuery(document).trigger('wpformsFieldUpdate', [wpf.getFields()]);
		
		// get initial form data
		// we can't use json directly, we have to use encoding without slashes and quotes due to an unnecessary stripslashes() call in wpforms/includes/admin/builder/panels/class-base.php
		var data_base64 = data_tag.val();
		var data_json = null;
		var data = {};
		try { if(data_base64) data_json = atob(data_base64); }
		catch(e) { data_json = data_base64; }
		try { if(data_json) data = JSON.parse(data_json); }
		catch(e) { return errorMessage(e.message); }
		var preload_data_json = preload_data_tag.text();
		var preload_data = {};
		if(preload_data_json)
			preload_data = JSON.parse(preload_data_json);
		
		if((typeof data != 'object' || data === null)
		|| (typeof preload_data != 'object' || preload_data === null))
			return errorMessage(pdf_forms_for_wpforms.__No_Preload_Data);
		
		// load information about attached PDFs
		if(preload_data.hasOwnProperty('attachments'))
			jQuery.each(preload_data.attachments, function(index, attachment) {
				setAttachmentData(attachment.attachment_id, attachment);
			});
		
		if(preload_data.hasOwnProperty('default_pdf_options'))
			defaultPdfOptions = preload_data.default_pdf_options;
		
		if(data.hasOwnProperty('attachments'))
			jQuery.each(data.attachments, function(index, data) {
				addAttachment(data);
			});
		
		if(data.hasOwnProperty('mappings'))
		{
			jQuery.each(data.mappings, function(index, mapping) {
				addMapping(mapping);
			});
			refreshMappings();
		}
		
		if(data.hasOwnProperty('value_mappings'))
		{
			var mappings = getMappings();
			jQuery.each(data.value_mappings, function(index, value_mapping) {
				
				// find mapping id
				for(var i=0, l=mappings.length; i<l; i++)
				{
					if(mappings[i].pdf_field == value_mapping.pdf_field)
					{
						value_mapping.mapping_id = mappings[i].mapping_id;
						break;
					}
				}
				
				if(!value_mapping.hasOwnProperty('mapping_id'))
					return;
				
				addValueMapping(value_mapping);
			});
		}
		
		if(data.hasOwnProperty('embeds'))
		{
			jQuery.each(data.embeds, function(index, embed) { if(embed.id && embed_id_autoinc < embed.id) embed_id_autoinc = embed.id; });
			jQuery.each(data.embeds, function(index, embed) { addEmbed(embed); });
		}
	};
	
	var getMappings = function()
	{
		var mappings = getData('mappings');
		if(mappings)
			return mappings;
		else
			return [];
	};
	
	var getMapping = function(id)
	{
		var mappings = getMappings();
		for(var i=0; i<mappings.length; i++)
			if(mappings[i].mapping_id == id)
				return mappings[i];
		return undefined;
	};
	
	var getValueMappings = function()
	{
		var valueMappings = getData('value_mappings');
		if(valueMappings)
			return valueMappings;
		else
			return [];
	};
	
	var runWhenDoneTimers = {};
	var runWhenDone = function(func)
	{
		if(runWhenDoneTimers[func])
			return;
		runWhenDoneTimers[func] = setTimeout(function(func){ delete runWhenDoneTimers[func]; func(); }, 0, func);
	}
	
	var setMappings = function(mappings)
	{
		setData('mappings', mappings);
		runWhenDone(refreshPdfFields);
	};
	
	var deleteMapping = function(mapping_id)
	{
		var mappings = getMappings();
		
		for(var i=0; i<mappings.length; i++)
			if(mappings[i].mapping_id == mapping_id)
			{
				mappings.splice(i, 1);
				break;
			}
		
		deleteValueMappings(mapping_id);
		setMappings(mappings);
	};
	
	var deleteAllMappings = function()
	{
		setMappings([]);
		setValueMappings([]);
		refreshMappings();
	};
	
	var setValueMappings = function(value_mappings)
	{
		setData('value_mappings', value_mappings);
	};
	
	var deleteValueMapping = function(value_mapping_id)
	{
		var value_mappings = getValueMappings();
		
		for(var i=0; i<value_mappings.length; i++)
			if(value_mappings[i].value_mapping_id == value_mapping_id)
			{
				value_mappings.splice(i, 1);
				break;
			}
		
		setValueMappings(value_mappings);
	};
	
	var deleteValueMappings = function(mapping_id)
	{
		var value_mappings = getValueMappings();
		
		for(var i=0; i<value_mappings.length; i++)
			if(value_mappings[i].mapping_id == mapping_id)
				value_mappings.splice(i, 1);
		
		setValueMappings(value_mappings);
		runWhenDone(refreshMappings);
	};
	
	var generateId = function()
	{
		return Math.random().toString(36).substring(2) + Date.now().toString();
	}
	
	var addValueMapping = function(data) {
		
		if(typeof data.mapping_id == 'undefined'
		|| typeof data.pdf_field == 'undefined'
		|| typeof data.wpf_value == 'undefined'
		|| typeof data.pdf_value == 'undefined')
			return;
		
		data.value_mapping_id = generateId();
		pluginData["value_mappings"].push(data);
		
		runWhenDone(updatePluginDataField);
		
		addValueMappingEntry(data);
	};
	
	var addValueMappingEntry = function(data) {
		
		var mapping = getMapping(data.mapping_id);
		
		var wpfField = null;
		if(mapping && mapping.hasOwnProperty('wpf_field'))
			wpfField = getWpfFieldData(mapping.wpf_field);
		
		var pdfField = getPdfFieldData(data.pdf_field);
		
		var template = jQuery('.pdf-forms-for-wpforms-admin .pdf-mapping-row-valuemapping-template');
		var tag = template.clone().removeClass('pdf-mapping-row-valuemapping-template').addClass('pdf-valuemapping-row');
		tag.data('mapping_id', data.mapping_id);
		
		tag.find('input').data('value_mapping_id', data.value_mapping_id);
		
		if(typeof wpfField == 'object' && wpfField !== null && wpfField.hasOwnProperty('choices') && Object.values(wpfField.choices).length > 0)
		{
			var input = tag.find('input.wpf-value');
			var select = jQuery('<select>');
			select.insertAfter(input);
			input.hide();
			
			// TODO: use new custom select2DataAdapter
			select.select2({
				ajax: {},
				width: '100%',
				sharedDataElement: 'wpfFieldsChoices',
				sharedDataElementId: wpfField.id,
				tags: true,
				dropdownParent: jQuery('#wpforms-builder'),
				dataAdapter: jQuery.fn.select2.amd.require("pdf-forms-for-wpforms-shared-data-adapter"),
			});
			
			select.change(function() {
				jQuery(this).prev().val(jQuery(this).val()).trigger('change');
			});
			
			select.resetSelect2Field(data.wpf_value);
		}
		
		if(typeof pdfField == 'object' && pdfField !== null && pdfField.hasOwnProperty('options') && ((Array.isArray(pdfField.options) && pdfField.options.length > 0) || (typeof pdfField.options == 'object' && Object.values(pdfField.options).length > 0)))
		{
			var input = tag.find('input.pdf-value');
			var select = jQuery('<select>');
			select.insertAfter(input);
			input.hide();
			
			var options = [];
			var add_custom = true;
			jQuery.each(pdfField.options, function(i, option) {
				var text;
				if(typeof option == 'object' && option.hasOwnProperty('value'))
					text = String(option.value);
				else
					text = String(option);
				options.push({ id: text, text: text});
				if(text == data.pdf_value)
					add_custom = false;
			});
			if(add_custom)
				options.unshift({ id: data.pdf_value, text: data.pdf_value });
			
			select.select2({
				data: options,
				tags: true,
				width: '100%',
				dropdownParent: jQuery('#wpforms-builder')
			});
			
			select.val(data.pdf_value).trigger('change');
			
			select.change(function() {
				jQuery(this).prev().val(jQuery(this).val()).trigger('change');
			});
		}
		
		tag.find('input.wpf-value').val(data.wpf_value);
		tag.find('input.pdf-value').val(data.pdf_value);
		
		var delete_button = tag.find('.delete-valuemapping-button');
		delete_button.data('value_mapping_id', data.value_mapping_id);
		delete_button.click(function(event) {
			
			// prevent running default button click handlers
			event.stopPropagation();
			event.preventDefault();
			
			if(!confirm(pdf_forms_for_wpforms.__Confirm_Delete_Mapping))
				return;
			
			deleteValueMapping(jQuery(this).data('value_mapping_id'));
			
			jQuery(this).closest('.pdf-valuemapping-row').remove();
		});
		
		var mappingTag = jQuery('.pdf-forms-for-wpforms-admin .pdf-mapping-row[data-mapping_id="'+data.mapping_id+'"]');
		tag.insertAfter(mappingTag);
	};
	
	var addMapping = function(data)
	{
		data.mapping_id = generateId();
		pluginData["mappings"].push(data);
		
		runWhenDone(updatePluginDataField);
		runWhenDone(refreshPdfFields);
		
		addMappingEntry(data);
		
		return data.mapping_id;
	};
	
	var addMappingEntry = function(data)
	{
		var pdf_field_data = getPdfFieldData(data.pdf_field);
		var pdf_field_caption;
		if(pdf_field_data)
			pdf_field_caption = pdf_field_data.text;
		else
		{
			var field_id = data.pdf_field.substr(data.pdf_field.indexOf('-')+1);
			pdf_field_caption = base64urldecode(field_id);
		}
		
		if(data.hasOwnProperty('wpf_field'))
		{
			var wpf_field_data = getWpfFieldData(data.wpf_field);
			var wpf_field_caption = data.wpf_field;
			if (wpf_field_data)
				wpf_field_caption = wpf_field_data.text;
			
			var template = jQuery('.pdf-forms-for-wpforms-admin .pdf-mapping-row-template');
			var tag = template.clone().removeClass('pdf-mapping-row-template').addClass('pdf-mapping-row');
			
			tag.find('.wpf-field-name').text(wpf_field_caption);
			tag.find('.pdf-field-name').text(pdf_field_caption);
			
			tag.find('.convert-to-smarttags-button').data('mapping_id', data.mapping_id);
		}
		else if(data.hasOwnProperty('smart_tags'))
		{
			var template = jQuery('.pdf-forms-for-wpforms-admin .pdf-mapping-row-smarttag-template');
			var tag = template.clone().removeClass('pdf-mapping-row-smarttag-template').addClass('pdf-mapping-row');
			
			// set unique id
			tag.find('label').attr('for', 'mapping-smart-tags-'+data.mapping_id);
			tag.find('textarea.smart-tags').attr('id', 'mapping-smart-tags-'+data.mapping_id);
			
			tag.find('textarea.smart-tags').val(data.smart_tags).data('mapping_id', data.mapping_id);
			tag.find('.pdf-field-name').text(pdf_field_caption);
		}
		
		tag.attr('data-mapping_id', data.mapping_id);
		
		var delete_button = tag.find('.delete-mapping-button');
		delete_button.data('mapping_id', data.mapping_id);
		delete_button.click(function(event) {
			
			// prevent running default button click handlers
			event.stopPropagation();
			event.preventDefault();
			
			if(!confirm(pdf_forms_for_wpforms.__Confirm_Delete_Mapping))
				return;
			
			deleteMapping(jQuery(this).data('mapping_id'));
			
			jQuery(this).closest('.pdf-mapping-row').remove();
			
			var mappings = getMappings();
			if(mappings.length==0)
				jQuery('.pdf-forms-for-wpforms-admin .delete-all-row').hide();
		});
		
		var map_value_button = tag.find('.map-value-button');
		map_value_button.data('mapping_id', data.mapping_id);
		map_value_button.click(function(event) {
			
			// prevent running default button click handlers
			event.stopPropagation();
			event.preventDefault();
			
			addValueMapping({'mapping_id': data.mapping_id, 'pdf_field': data.pdf_field, 'wpf_value': "", 'pdf_value': ""});
		});
		
		tag.insertBefore(jQuery('.pdf-forms-for-wpforms-admin .pdf-fields-mapper .delete-all-row'));
		jQuery('.pdf-forms-for-wpforms-admin .delete-all-row').show();
	};
	
	var refreshMappings = function()
	{
		jQuery('.pdf-forms-for-wpforms-admin .pdf-mapping-row').remove();
		jQuery('.pdf-forms-for-wpforms-admin .pdf-valuemapping-row').remove();
		
		var mappings = getMappings();
		for(var i=0; i<mappings.length; i++)
			addMappingEntry(mappings[i]);
		
		var value_mappings = getValueMappings();
		for(var i=0; i<value_mappings.length; i++)
			addValueMappingEntry(value_mappings[i]);
		
		if(mappings.length==0)
			jQuery('.pdf-forms-for-wpforms-admin .delete-all-row').hide();
		else
			jQuery('.pdf-forms-for-wpforms-admin .delete-all-row').show();
	};
	
	var updateFieldHint = function()
	{
		var tag = jQuery('.pdf-forms-for-wpforms-admin .field-hint');
		tag.empty();
		
		var pdf_field = jQuery('.pdf-forms-for-wpforms-admin .pdf-field-list').val();
		if(!pdf_field)
			return;
		
		var pdf_field_data = getPdfFieldData(pdf_field);
		if(!pdf_field_data)
			return;
		
		var type = String(pdf_field_data.type);
		var wpf_type = getWpfType(pdf_field_data);
		if(pdf_field_data.hasOwnProperty('flags') && Array.isArray(pdf_field_data.flags))
		{
			var flags = pdf_field_data.flags;
			
			if(type == 'select')
				if(flags.indexOf('MultiSelect') != -1)
					wpf_type += " multiple choice";
			
			if(flags.indexOf('Required') != -1)
				wpf_type += " required";
		}
		
		var hint = wpf_type + " '" + pdf_field_data.name + "'";
		
		var defaultValue = null;
		if(pdf_field_data.hasOwnProperty('defaultValue'))
			defaultValue = pdf_field_data.defaultValue;
		
		if(pdf_field_data.hasOwnProperty('options') && (Array.isArray(pdf_field_data.options) || typeof pdf_field_data.options == 'object'))
		{
			var options = pdf_field_data.options;
			if(!Array.isArray(options))
				options = Object.values(options);
			
			if(options.length > 0)
			{
				hint += ", with options ";
				
				options.forEach(function(option, optionIndex) {
					
					var option_name;
					var option_value;
					var selected = false;
					
					// if the option is an object then extract option data
					if(typeof option == 'object')
					{
						if(option.hasOwnProperty('label')) option_name = option['label'];
						if(option.hasOwnProperty('value')) option_value = option['value'];
						if(option.hasOwnProperty('selected')) selected = option['selected'];
					}
					else
					{
						option_name = String(option);
						option_value = String(option);
						
						if(defaultValue != null)
						{
							if(Array.isArray(defaultValue))
								selected = defaultValue.indexOf(option_value) != -1;
							else
								selected = option_value == defaultValue || optionIndex == defaultValue;
						}
					}
					
					hint += "'" + (option_name || option_value) + "'" + (selected ? " (default)" : "") + ", ";
				});
				
				// trim commas and spaces from hint
				hint = hint.replace(/(\s|,)+$/,"");
			}
		}
		
		tag.text(hint);
	}
	
	jQuery('.pdf-forms-for-wpforms-admin .pdf-field-list').change(updateFieldHint);
	
	var getEmbeds = function()
	{
		var embeds = getData('embeds');
		if(embeds)
			return embeds;
		else
			return [];
	};
	
	var setEmbeds = function(embeds)
	{
		setData('embeds', embeds);
	};
	
	var embed_id_autoinc = 0;
	var addEmbed = function(embed)
	{
		if(!embed.hasOwnProperty('smart_tags') && !embed.hasOwnProperty('wpf_field'))
			return;
		
		var attachment_id = embed.attachment_id;
		var page = embed.page;
		
		if(!attachment_id || !page || (page != 'all' && page < 0))
			return;
		
		var attachment = null;
		if(attachment_id != 'all')
		{
			attachment = getAttachment(attachment_id);
			if(!attachment)
				return;
		}

		var wpf_field_data = null;
		if(embed.hasOwnProperty('wpf_field')) 
		{
			wpf_field_data = getWpfFieldData(embed.wpf_field);
			if (!wpf_field_data)
				return;
		}
		
		if(!embed.id)
			embed.id = ++embed_id_autoinc;
		
		var embeds = getEmbeds();
		embeds.push(embed);
		setEmbeds(embeds);

		if(embed.hasOwnProperty('smart_tags'))
			addEmbedEntry({smart_tags: embed.smart_tags, attachment: attachment, embed: embed});
		
		if(embed.hasOwnProperty('wpf_field'))
			addEmbedEntry({wpf_field_data: wpf_field_data, attachment: attachment, embed: embed});
	};
	
	var refreshEmbeds = function()
	{
		jQuery('.pdf-forms-for-wpforms-admin .image-embeds-row').remove();
		
		var embeds = getEmbeds();
		for(var i=0, l=embeds.length; i<l; i++)
		{
			var embed = embeds[i];
			
			var attachment = null;
			if(embed.attachment_id != 'all')
			{
				attachment = getAttachment(embed.attachment_id);
				if(!attachment)
					continue;
			}

			if(embed.hasOwnProperty('smart_tags'))
				addEmbedEntry({smart_tags: embed.smart_tags, attachment: attachment, embed: embed});

			if(embed.hasOwnProperty('wpf_field'))
			{
				var wpf_field_data;
				try { wpf_field_data = getWpfFieldData(embed.wpf_field); } catch(e) { }
				if (!wpf_field_data)
					continue;
				
				addEmbedEntry({wpf_field_data: wpf_field_data, attachment: attachment, embed: embed});
			}
		}
	};
	
	var addEmbedEntry = function(data)
	{
		var page = data.embed.page;
		
		if(data.hasOwnProperty('smart_tags'))
		{
			var template = jQuery('.pdf-forms-for-wpforms-admin .image-embeds-row-smarttag-template');
			var tag = template.clone().removeClass('image-embeds-row-smarttag-template').addClass('image-embeds-row');
			
			// set unique id
			tag.find('label').attr('for', 'embed-smart-tags-'+data.embed.id);
			tag.find('textarea.smart-tags').attr('id', 'embed-smart-tags-'+data.embed.id);
			
			tag.find('textarea.smart-tags').text(data.smart_tags);
			tag.find('textarea.smart-tags').data('embed_id', data.embed.id);
		}
		else
		{
			var template = jQuery('.pdf-forms-for-wpforms-admin .image-embeds-row-template');
			var tag = template.clone().removeClass('image-embeds-row-template').addClass('image-embeds-row');
			tag.find('.convert-to-smarttags-button').data('embed_id', data.embed.id);
			tag.find('span.wpf-field-name').text(data.wpf_field_data.text);
		}
		
		tag.attr('data-embed_id', data.embed.id);
		
		var delete_button = tag.find('.delete-wpf-field-embed-button');
		delete_button.data('embed_id', data.embed.id);
		delete_button.click(function(event) {
			
			// prevent running default button click handlers
			event.stopPropagation();
			event.preventDefault();
			
			if(!confirm(pdf_forms_for_wpforms.__Confirm_Delete_Embed))
				return;
			
			deleteEmbed(jQuery(this).data('embed_id'));
			
			tag.remove();
			
			return false;
		});
		
		var pdf_name = pdf_forms_for_wpforms.__All_PDFs;
		if(data.hasOwnProperty('attachment') && data.attachment)
		{
			var attachment_id = data.attachment.attachment_id;
			pdf_name = '[' + attachment_id + ']';
			var info = getAttachmentData(attachment_id);
			if(typeof info == 'object' && info.hasOwnProperty('filename'))
				pdf_name += ' ' + info.filename;
		}
		
		tag.find('.pdf-file-caption').text(pdf_name);
		tag.find('.page-caption').text(page > 0 ? page : pdf_forms_for_wpforms.__All_Pages);
		
		if(data.hasOwnProperty('attachment') && data.attachment && page > 0)
			loadPageSnapshot(data.attachment, data.embed, tag);
		else
			tag.find('.page-selector-row').addBack('.page-selector-row').hide();
		
		jQuery('.pdf-forms-for-wpforms-admin .image-embeds tbody').append(tag);
	};
	
	var deleteEmbed = function(embed_id)
	{
		var embeds = getEmbeds();
		
		for(var i=0, l=embeds.length; i<l; i++)
			if(embeds[i].id == embed_id)
			{
				embeds.splice(i, 1);
				break;
			}
		
		setEmbeds(embeds);
	};
	
	var loadPageSnapshot = function(attachment, embed, tag)
	{
		var info = getAttachmentInfo(attachment.attachment_id);
		if(!info)
			return;
		
		var pages = info.pages;
		var pageData = null;
		for(var p=0;p<pages.length;p++)
		{
			if(pages[p].number == embed.page)
			{
				pageData = pages[p];
				break;
			}
		}
		if(!pageData || !pageData.width || !pageData.height)
			return;
		
		jQuery.ajax({
			url: pdf_forms_for_wpforms.ajax_url,
			type: 'POST',
			data: {
				'action': 'pdf_forms_for_wpforms_query_page_image',
				'attachment_id': attachment.attachment_id,
				'page': embed.page,
				'nonce': pdf_forms_for_wpforms.ajax_nonce
			},
			cache: false,
			dataType: 'json',
			
			success: function(data, textStatus, jqXHR) {
				
				if(!data.success)
					return errorMessage(data.error_message);
				
				if(data.hasOwnProperty('snapshot'))
				{
					var width = 700;
					var height = Math.round((pageData.height / pageData.width) * width);
					
					var container = tag.find('.jcrop-container');
					var image = tag.find('.jcrop-page');
					
					var widthStr = width.toString();
					var heightStr = height.toString();
					var widthCss = widthStr + 'px';
					var heightCss = heightStr + 'px';
					
					jQuery(image).attr('width', widthStr).css('width', widthCss);
					jQuery(image).attr('height', heightStr).css('height', heightCss);
					jQuery(container).css('width', widthCss);
					jQuery(container).css('height', heightCss);
					
					var xPixelsPerPoint = width / pageData.width;
					var yPixelsPerPoint = height / pageData.height;
					
					var leftInput = tag.find('input[name=left]');
					var topInput = tag.find('input[name=top]');
					var widthInput = tag.find('input[name=width]');
					var heightInput = tag.find('input[name=height]');
					
					leftInput.attr('max', width / xPixelsPerPoint);
					topInput.attr('max', height / yPixelsPerPoint);
					widthInput.attr('max', width / xPixelsPerPoint);
					heightInput.attr('max', height / yPixelsPerPoint);
					
					var updateEmbedCoordinates = function(x, y, w, h)
					{
						var embeds = getEmbeds();
						for(var i=0, l=embeds.length; i<l; i++)
							if(embeds[i].id == embed.id)
							{
								embeds[i].left = embed.left = x;
								embeds[i].top = embed.top = y;
								embeds[i].width = embed.width = w;
								embeds[i].height = embed.height = h;
								
								break;
							}
						setEmbeds(embeds);
					};
					
					var updateCoordinates = function(c)
					{
						leftInput.val(Math.round(c.x / xPixelsPerPoint));
						topInput.val(Math.round(c.y / yPixelsPerPoint));
						widthInput.val(Math.round(c.w / xPixelsPerPoint));
						heightInput.val(Math.round(c.h / yPixelsPerPoint));
						
						updateEmbedCoordinates(
							leftInput.val(),
							topInput.val(),
							widthInput.val(),
							heightInput.val()
						);
					};
					
					var jcropApi;
					
					var updateRegion = function() {
						
						var leftValue = parseFloat(leftInput.val());
						var topValue = parseFloat(topInput.val());
						var widthValue = parseFloat(widthInput.val());
						var heightValue = parseFloat(heightInput.val());
						
						if(typeof leftValue == 'number'
						&& typeof topValue == 'number'
						&& typeof widthValue == 'number'
						&& typeof heightValue == 'number')
						{
							jcropApi.setSelect([
								leftValue * xPixelsPerPoint,
								topValue * yPixelsPerPoint,
								(leftValue + widthValue) * xPixelsPerPoint,
								(topValue + heightValue) * yPixelsPerPoint
							]);
							
							updateEmbedCoordinates(
								leftValue,
								topValue,
								widthValue,
								heightValue
							);
						}
					}
					
					jQuery(image).one('load', function() {
						image.Jcrop({
							onChange: updateCoordinates,
							onSelect: updateCoordinates,
							onRelease: updateCoordinates,
							boxWidth: width,
							boxHeight: height,
							trueSize: [width, height],
							minSize: [1, 1]
						}, function() {
							
							jcropApi = this;
							
							if(!embed.left)
								embed.left = Math.round(pageData.width * 0.25);
							if(!embed.top)
								embed.top = Math.round(pageData.height * 0.25);
							if(!embed.width)
								embed.width = Math.round(pageData.width * 0.5);
							if(!embed.height)
								embed.height = Math.round(pageData.height * 0.5);
							
							updateCoordinates({
								x: Math.round(embed.left * xPixelsPerPoint),
								y: Math.round(embed.top * yPixelsPerPoint),
								w: Math.round(embed.width * xPixelsPerPoint),
								h: Math.round(embed.height * yPixelsPerPoint)
							});
							
							updateRegion();
						});
					});
					
					tag.find('input.coordinate').change(updateRegion);
					
					jQuery(image).attr('src', data.snapshot);
				}
				
			},
			
			error: function(jqXHR, textStatus, errorThrown) { return errorMessage(textStatus); },
			
			beforeSend: function() { PdfFormsFillerSpinner.show(); },
			complete: function() { PdfFormsFillerSpinner.hide(); }
			
		});
	};
	
	var refreshPageList = function()
	{
		var pageList = [];
		
		pageList.push({
			id: 0,
			text: pdf_forms_for_wpforms.__All_Pages,
			lowerText: String(pdf_forms_for_wpforms.__All_Pages).toLowerCase()
		});
		
		var files = jQuery('.pdf-forms-for-wpforms-admin .image-embedding-tool .pdf-files-list');
		var info = getAttachmentInfo(files.val());
		
		if(typeof info != 'undefined' && info !== null)
		{
			jQuery.each(info.pages, function(p, page){
				pageList.push({
					id: page.number,
					text: page.number,
					lowerText: String(page.number).toLowerCase()
				});
			});
		}
		
		// TODO: use a new dynamically generated data adapter for better memory efficiency
		select2SharedData.pageList = pageList;
		
		var id = typeof info != 'undefined' && info !== null && info.pages.length > 0 ? 1 : 0;
		jQuery('.pdf-forms-for-wpforms-admin .page-list').resetSelect2Field(id);
	};
	
	var refreshPdfFilesList = function()
	{
		var id = select2SharedData.pdfSelect2Files.length > 1 ? 1 : null;
		jQuery('.pdf-forms-for-wpforms-admin .pdf-files-list').resetSelect2Field(id);
	}
	
	var refreshNotificationsFields = function()
	{
		select2SharedData.notifications = [];
		
		jQuery.each(getWpformsNotifications(), function(i, notification) {
			notification.id = i;
			if(notification.hasOwnProperty('notification_name'))
				notification.text = notification.notification_name;
			else
				notification.text = pdf_forms_for_wpforms.__Default_Notification;
			select2SharedData.notifications.push(notification);
		});
		
		jQuery('.pdf-forms-for-wpforms-admin .notification select').trigger('change');
	};
	
	var refreshConfirmationsFields = function()
	{
		select2SharedData.confirmations = [];
		
		jQuery.each(getWpformsConfirmations(), function(i, confirmation) {
			confirmation.id = i;
			if(confirmation.hasOwnProperty('name'))
				confirmation.text = confirmation.name;
			else
				confirmation.text = pdf_forms_for_wpforms.__Default_Confirmation;
			select2SharedData.confirmations.push(confirmation);
		});
		
		jQuery('.pdf-forms-for-wpforms-admin .confirmation select').trigger('change');
	};
	
	var attachPdf = function(file_id)
	{
		jQuery.ajax({
			url: pdf_forms_for_wpforms.ajax_url,
			type: 'POST',
			data: {
				'action': 'pdf_forms_for_wpforms_get_attachment_data',
				'post_id': WPFormsBuilder.settings.formID,
				'file_id': file_id,
				'nonce': pdf_forms_for_wpforms.ajax_nonce,
			},
			cache: false,
			dataType: 'json',
			
			success: function(data, textStatus, jqXHR) {
				
				if(!data.success)
					return errorMessage(data.error_message);
				
				delete data.success;
				
				if(data.hasOwnProperty('attachment_id') && data.hasOwnProperty('info') && data.hasOwnProperty('filename'))
				{
					if(!data.info.hasOwnProperty('fields')
					|| typeof data.info.fields !== 'object'
					|| Object.keys(data.info.fields).length == 0)
						if(!confirm(pdf_forms_for_wpforms.__Confirm_Attach_Empty_Pdf))
							return;
					setAttachmentData(data.attachment_id, data);
					addAttachment({'attachment_id': data.attachment_id, options: defaultPdfOptions});
				}
			},
			
			error: function(jqXHR, textStatus, errorThrown) { return errorMessage(textStatus); },
			
			beforeSend: function() { PdfFormsFillerSpinner.show(); },
			complete: function() { PdfFormsFillerSpinner.hide(); }
		});
		
		return false;
	};
	
	var showHelp = function(button, helpbox)
	{
		helpbox.show();
		button.text(pdf_forms_for_wpforms.__Hide_Help);
	}
	
	var hideHelp = function(button, helpbox)
	{
		helpbox.hide();
		button.text(pdf_forms_for_wpforms.__Show_Help);
	}

	// set up help buttons
	jQuery('.pdf-forms-for-wpforms-admin').on("click", '.help-button', function(event) {

		// prevent running default button click handlers
		event.stopPropagation();
		event.preventDefault();

		var button = jQuery(this);
		var helpbox = button.parent().find('.helpbox');

		if(helpbox.is(":visible"))
			hideHelp(button, helpbox);
		else
			showHelp(button, helpbox);

		return false;
	});
	
	jQuery('.pdf-forms-for-wpforms-admin .field-mapping-tool').on("input change", 'input.wpf-value', function(event) {
		
		var wpf_value = jQuery(this).val();
		var value_mapping_id = jQuery(this).data('value_mapping_id');
		
		var value_mappings = getValueMappings();
		for(var i=0, l=value_mappings.length; i<l; i++)
			if(value_mappings[i].value_mapping_id == value_mapping_id)
			{
				value_mappings[i].wpf_value = wpf_value;
				break;
			}
		
		setValueMappings(value_mappings);
	});
	
	jQuery('.pdf-forms-for-wpforms-admin .field-mapping-tool').on("input change", 'input.pdf-value', function(event) {
		
		var pdf_value = jQuery(this).val();
		var value_mapping_id = jQuery(this).data('value_mapping_id');
		
		var value_mappings = getValueMappings();
		for(var i=0, l=value_mappings.length; i<l; i++)
			if(value_mappings[i].value_mapping_id == value_mapping_id)
			{
				value_mappings[i].pdf_value = pdf_value;
				break;
			}
		
		setValueMappings(value_mappings);
	});
	
	var getWpfType = function(pdf_field_data)
	{
		var type = String(pdf_field_data.type);
		var wpf_type = type;
		
		if(pdf_field_data.hasOwnProperty('flags') && Array.isArray(pdf_field_data.flags))
		{
			var flags = pdf_field_data.flags;
			
			if(type == 'text')
				if(flags.indexOf('Multiline') != -1)
					wpf_type = 'textarea';
		}
		
		return wpf_type;
	};
	
	var addFormField = function(pdf_field_data)
	{
		return new Promise(function(resolve, reject) {
			
			var name = String(pdf_field_data.name);
			var type = String(pdf_field_data.type);
			
			var wpf_type = getWpfType(pdf_field_data);
			
			if(!wpf_type)
				reject(pdf_forms_for_wpforms.__PDF_Field_Type_Unsupported + ": " + type);
			
			WPFormsBuilder.fieldAdd(wpf_type)
				.then(function(result) {
					
					if(typeof result !== 'object' || !result.hasOwnProperty('success') || !result.hasOwnProperty('data')
					|| (result.success == true && (typeof result.data !== 'object' || !result.data.hasOwnProperty('field') || !result.data.field.hasOwnProperty('id'))))
						return reject("Unexpected result from WPFormsBuilder.fieldAdd()");
					
					if(result.success == false)
						return reject(result.data);
					
					var field_id = result.data.field.id;
					var valueMappings = [];
					
					// update field title
					jQuery('#wpforms-field-option-' + field_id + '-label').val(name).trigger('input');
					
					// update field classes
					var option = jQuery('#wpforms-field-option-' + field_id + '-css');
					var value = option.val();
					option.val((value.length > 0 ? value + " " : "") + name).trigger('input');
					
					// set field flags
					if(pdf_field_data.hasOwnProperty('flags') && Array.isArray(pdf_field_data.flags))
					{
						var flags = pdf_field_data.flags;
						
						if(type == 'select')
							if(flags.indexOf('MultiSelect') != -1)
								jQuery('#wpforms-field-option-' + field_id + '-multiple').prop('checked', true).trigger('change');
						
						if(flags.indexOf('Required') != -1)
							jQuery('#wpforms-field-option-' + field_id + '-required').prop('checked', true).trigger('change');
					}
					
					var defaultValue = null;
					if(pdf_field_data.hasOwnProperty('defaultValue'))
						defaultValue = pdf_field_data.defaultValue;
					
					if(type == 'radio' || type == 'select' || type == 'checkbox')
					{
						if(pdf_field_data.hasOwnProperty('options') && (Array.isArray(pdf_field_data.options) || typeof pdf_field_data.options == 'object'))
						{
							var options = Object.assign({}, pdf_field_data.options); // shallow copy
							if(!Array.isArray(options))
								options = Object.values(options);
							
							var choices_list = jQuery("ul.choices-list[data-field-id='" + field_id + "']");
							
							// removing all choices except one (required by WPForms)
							for(var i = 0, l = choices_list.children().length - 1; i < l; ++i)
								choices_list.find('a.remove').last().trigger('click');
							
							options.forEach(function(option, optionIndex) {
								
								var selected = false;
								var option_value = "";
								var option_name = "";
								
								// if the option is an object then extract option data
								if(typeof option == 'object')
								{
									if(option.hasOwnProperty('label')) option_name = option['label'];
									if(option.hasOwnProperty('value')) option_value = option['value'];
									if(option.hasOwnProperty('selected')) selected = option['selected'];
								}
								else
								{
									option_name = String(option);
									option_value = String(option);
									
									if(defaultValue != null)
									{
										if(Array.isArray(defaultValue))
											selected = defaultValue.indexOf(option_value) != -1;
										else
											selected = option_value == defaultValue || optionIndex == defaultValue;
									}
								}
								
								if(option_name == "")
									option_name = option_value;
								
								// add value mapping
								if(option_name != option_value)
									valueMappings.push({'pdf_value': option_value, 'wpf_value': option_name});
								
								// add new choice
								choices_list.find('a.add').last().trigger('click');
								var choice = choices_list.children().last();
								choice.find("input.label").val(option_name);
								
								// set choice as default
								if(selected)
									choice.find('input.default').prop('checked', true);
							});
							
							// remove first choice
							choices_list.find('a.remove').first().trigger('click');
							
							// set checkbox exclusive
							if(type == 'checkbox' && options.length > 1)
								jQuery('#wpforms-field-option-' + field_id + '-choice_limit').val(1).trigger('change');
						}
					}
					else
					{
						// set default value
						if(defaultValue)
							jQuery('#wpforms-field-option-' + field_id  + '-default_value').val(defaultValue);
					}
					
					var mapping_id = addMapping({ wpf_field: field_id, pdf_field: pdf_field_data.id });
					
					if(valueMappings.length > 0)
					{
						valueMappings.forEach(function(valueMapping) {
							valueMapping['pdf_field'] = pdf_field_data.id;
							valueMapping['mapping_id'] = mapping_id;
							addValueMapping(valueMapping);
						});
					}
					
					return resolve();
				})
				.catch(function(error) { reject(error); });
		});
	};
	
	var addFormFields = function(pdf_fields)
	{
		if(pdf_fields.length > 0)
		{
			PdfFormsFillerSpinner.show();
			
			var promise = null;
			
			pdf_fields.forEach(function(pdf_field) {
				if(promise == null)
					promise = addFormField(pdf_field);
				else
					promise = promise
						.catch(function(error) {
							errorMessage(error);
						})
						.finally(function() {
							return addFormField(pdf_field);
						});
			});
			
			promise
				.catch(function(error) {
					errorMessage(error);
				})
				.finally(function() {
					PdfFormsFillerSpinner.hide();
				});
		}
	}
	
	jQuery('.pdf-forms-for-wpforms-admin .image-embedding-tool').on("change", '.pdf-files-list', refreshPageList);
	
	// set up 'Attach a PDF File' button handler
	jQuery('.pdf-forms-for-wpforms-admin').on('click', '.attach-btn', function (event) {
		
		// prevent running default button click handlers
		event.stopPropagation();
		event.preventDefault();
		
		clearMessages();
		
		// create the pdf frame
		var pdf_frame = wp.media({
			title: pdf_forms_for_wpforms.__PDF_Frame_Title,
			multiple: false,
			library: {
				order: 'DESC',
				// we can use ['author','id','name','date','title','modified','uploadedTo','id','post__in','menuOrder']
				orderby: 'date',
				type: 'application/pdf',
				search: null,
				uploadedTo: null
			},
			button: {
				text: pdf_forms_for_wpforms.__PDF_Frame_Button
			}
		});
		// callback on the pdf frame
		pdf_frame.on('select', function() {
			var attachment = pdf_frame.state().get('selection').first().toJSON();
			if(!getAttachmentInfo(attachment.id))
				attachPdf(attachment.id);
		});
		pdf_frame.open();
	});
	
	// set up handler for changes in list of WPForms fields
	var wpfFieldsList = jQuery('.pdf-forms-for-wpforms-admin .wpf-field-list');
	if(wpfFieldsList.length > 0)
	{
		var wpfFieldsListObserver = new MutationObserver(function() { runWhenDone(refreshWpfFields); });
		wpfFieldsListObserver.observe(wpfFieldsList[0], {childList: true, subtree: true});
	}
	
	// set up handler for notifications changes
	var notificationsPanel = jQuery('#wpforms-builder-form div[data-panel="notifications"]');
	if(notificationsPanel.length > 0)
	{
		var notificationsObserver = new MutationObserver(function() { runWhenDone(refreshNotificationsFields); });
		notificationsObserver.observe(notificationsPanel[0], {childList: true, subtree: true});
	}
	
	// set up handler for confirmations changes
	var confirmationsPanel = jQuery('#wpforms-builder-form div[data-panel="confirmations"]');
	if(confirmationsPanel.length > 0)
	{
		var confirmationsObserver = new MutationObserver(function() { runWhenDone(refreshConfirmationsFields); });
		confirmationsObserver.observe(confirmationsPanel[0], {childList: true, subtree: true});
	}
	
	// set up 'Insert & link' button handler
	jQuery('.pdf-forms-for-wpforms-admin').on('click', '.insert-field-btn', function(event) {
		
		// prevent running default button click handlers
		event.stopPropagation();
		event.preventDefault();
		
		clearMessages();
		
		var pdf_field_data = getPdfFieldData(jQuery('.pdf-forms-for-wpforms-admin .pdf-field-list').val());
		
		if(!pdf_field_data)
			return;
		
		addFormFields([pdf_field_data]);
	});
	
	// set up 'Insert & Link All' button handler
	jQuery('.pdf-forms-for-wpforms-admin').on('click', '.insert-and-map-all-fields-btn', function(event) {
		
		// prevent running default button click handlers
		event.stopPropagation();
		event.preventDefault();
		
		clearMessages();
		
		var pdf_fields = getUnmappedPdfFields();
		pdf_fields = pdf_fields.filter((field) => (field.attachment_id == 'all'));
		
		addFormFields(pdf_fields);
		
		return false;
	});

	jQuery('.pdf-forms-for-wpforms-admin .field-mapping-tool').on("click", '.convert-to-smarttags-button', function(event) {

		// prevent running default button click handlers
		event.stopPropagation();
		event.preventDefault();

		var mapping_id = jQuery(this).data('mapping_id');

		var mappings = getMappings();
		for(var i=0, l=mappings.length; i<l; i++)
		{
			if(mappings[i].mapping_id == mapping_id)
			{
				mappings[i].smart_tags = '{field_id="'+mappings[i].wpf_field+'"}';
				delete mappings[i].wpf_field;
				break;
			}
		}
		setMappings(mappings);
		refreshMappings();
	});

	jQuery('.pdf-forms-for-wpforms-admin .image-embedding-tool').on("click", '.convert-to-smarttags-button', function(event) {
		
		// prevent running default button click handlers
		event.stopPropagation();
		event.preventDefault();
		
		var embed_id = jQuery(this).data('embed_id');
		
		var embeds = getEmbeds();
		for(var i=0, l=embeds.length; i<l; i++){
			if(embeds[i].id == embed_id)
			{
				embeds[i].smart_tags = '{field_id="'+embeds[i].wpf_field+'"}';
				delete embeds[i].wpf_field;
				break;
			}
		}
		setEmbeds(embeds);
		refreshEmbeds();
	});
	
	var generateValueMappings = function(mapping_id, wpf_field, pdf_field) {
		
		var mapping = getMapping(mapping_id);
		
		if(!mapping || !mapping.hasOwnProperty('wpf_field'))
			return;
		
		var wpfField = getWpfFieldData(wpf_field);
		var pdfField = getPdfFieldData(pdf_field);
		
		if(!wpfField || !pdfField)
			return;
		
		if(typeof wpfField !== 'object' || wpfField === null || !wpfField.hasOwnProperty('choices'))
			return;
		
		if(typeof pdfField != 'object' || pdfField === null || !pdfField.hasOwnProperty('options')
		|| (!Array.isArray(pdfField.options) && typeof pdfField.options != 'object'))
			return;
		
		if(Object.keys(wpfField.choices).length == 0 || Object.keys(pdfField.options).length == 0)
			return;
		
		var choices = [];
		jQuery.each(wpfField.choices, function(i, choice) {
			if(choice.hasOwnProperty('label'))
				choices.push(String(choice.label));
		});
		
		var options = [];
		jQuery.each(pdfField.options, function(i, option) {
			if(option.hasOwnProperty('value'))
				options.push(String(option.value));
		});
		
		var wpfValues = choices.filter(function(item) {
			return options.indexOf(item) < 0;
		});
		
		var pdfOptions = options.filter(function(item) {
			return choices.indexOf(item) < 0;
		});
		
		for(var i=0; i<wpfValues.length; i++)
		{
			var bestScore = 0;
			var bestValueIndex = 0;
			
			for(var j=0; j<pdfOptions.length; j++)
			{
				var score = similarity(wpfValues[i], pdfOptions[j]);
				if(score > bestScore)
				{
					bestScore = score;
					bestValueIndex = j;
				}
			}
			addValueMapping({'mapping_id': mapping_id, 'pdf_field': pdfField.id, 'wpf_value': wpfValues[i], 'pdf_value': pdfOptions[bestValueIndex]});
		}
	};
	
	// implementation of levenshtein algorithm taken from https://stackoverflow.com/a/36566052/8915264
	function similarity(s1, s2) {
		var longer = s1;
		var shorter = s2;
		if (s1.length < s2.length) {
			longer = s2;
			shorter = s1;
		}
		var longerLength = longer.length;
		if (longerLength == 0) {
			return 1.0;
		}
		return (longerLength - editDistance(longer, shorter)) / parseFloat(longerLength);
	}
	function editDistance(s1, s2) {
		s1 = s1.toLowerCase();
		s2 = s2.toLowerCase();
		
		var costs = new Array();
		for (var i = 0; i <= s1.length; i++) {
			var lastValue = i;
			for (var j = 0; j <= s2.length; j++) {
				if (i == 0)
					costs[j] = j;
				else {
					if (j > 0) {
						var newValue = costs[j - 1];
						if (s1.charAt(i - 1) != s2.charAt(j - 1))
							newValue = Math.min(Math.min(newValue, lastValue), costs[j]) + 1;
						costs[j - 1] = lastValue;
						lastValue = newValue;
					}
				}
			}
			if (i > 0)
				costs[s2.length] = lastValue;
		}
		return costs[s2.length];
	}
	
	// set up 'Add Mapping' button handler
	jQuery('.pdf-forms-for-wpforms-admin').on('click', '.add-mapping-button', function(event) {
		
		// prevent running default button click handlers
		event.stopPropagation();
		event.preventDefault();
		
		clearMessages();

		let tag = jQuery('.pdf-forms-for-wpforms-admin .pdf-fields-mapper');

		let subject = tag.find('.wpf-field-smarttag-list').val();
		let smarttags = tag.find('.wpf-field-smarttag-list').find('option:selected').data('smarttags');
		let pdf_field = tag.find('.pdf-field-list').val();
		
		if(pdf_field && subject)
		{
			if(smarttags)
				addMapping({
					smart_tags: subject,
					pdf_field: pdf_field,
				});
			else
			{
				try
				{
					var wpf_field = wpf.getField(subject);
					var mapping_id = addMapping({
						wpf_field: subject,
						wpf_field_id: wpf_field.id,
						wpf_field_label: wpf_field.label,
						pdf_field: pdf_field,
					});
					generateValueMappings(mapping_id, subject, pdf_field);
				}
				catch(e)
				{
					errorMessage(e.message);
				}
			}
		}
		
		return false;
	});
	
	// set up 'Delete All Mappings' button handler
	jQuery('.pdf-forms-for-wpforms-admin').on('click', '.delete-all-mappings-button', function(event) {
		
		// prevent running default button click handlers
		event.stopPropagation();
		event.preventDefault();
		
		clearMessages();
		
		if(!confirm(pdf_forms_for_wpforms.__Confirm_Delete_All_Mappings))
			return;
		
		deleteAllMappings();
		
		return false;
	});
	
	// set up 'Embed Image' button handler
	jQuery('.pdf-forms-for-wpforms-admin').on('click', '.add-wpf-field-embed-button', function(event) {
		
		// prevent running default button click handlers
		event.stopPropagation();
		event.preventDefault();
		
		clearMessages();
		
		let tag = jQuery('.pdf-forms-for-wpforms-admin .image-embedding-tool');
		
		let subject = tag.find('.wpf-field-smarttag-list').val();
		let smarttags = tag.find('.wpf-field-smarttag-list').find('option:selected').data('smarttags');
		let attachment_id = tag.find('.pdf-files-list').val();
		if(attachment_id == 0)
			attachment_id = 'all';
		let page = tag.find('.page-list').val();
		if(page == 0)
			page = 'all';
		
		if(subject && attachment_id && page)
		{
			if(smarttags)
				addEmbed({
					smart_tags: subject,
					attachment_id: attachment_id,
					page: page
				});
			else
				addEmbed({
					wpf_field: subject,
					attachment_id: attachment_id,
					page: page
				});
			
			// calculate scroll position until we hit an element with a scroll bar, then scroll to the calculated position
			var embedRowElement = jQuery(".pdf-forms-for-wpforms-admin .image-embeds-row:visible").last();
			var element = embedRowElement;
			var parentElement;
			if(element)
				parentElement = element.parent();
			if(element && parentElement)
			{
				var top = 0;
				do
				{
					var offset = element.offset();
					var parentOffset = parentElement.offset();
					if(offset && parentOffset)
						top += offset.top - parentOffset.top;
					element = parentElement;
					parentElement = element.parent();
				}
				while(parentElement && parentElement.get(0) && parentElement.get(0).scrollHeight <= parentElement.get(0).clientHeight);
				parentElement.animate({scrollTop: top}, 1000);
			}
		}
		
		return false;
	});
	
	jQuery('.pdf-forms-for-wpforms-admin .field-mapping-tool').on("input change", 'textarea.smart-tags', function(event) {
		
		var smart_tags = jQuery(this).val();
		var mapping_id = jQuery(this).data('mapping_id');
		
		var mappings = getMappings();
		jQuery.each(mappings, function(index, mapping) {
			if(mapping.mapping_id == mapping_id)
			{
				mappings[index].smart_tags = smart_tags;
				return false; // break
			}
		});
		
		setMappings(mappings);
	});
	
	jQuery('.pdf-forms-for-wpforms-admin .image-embedding-tool').on("input change", "textarea.smart-tags", function(event) {
		
		var smart_tags = jQuery(this).val();
		var embed_id = jQuery(this).data('embed_id');
		
		var embeds = getEmbeds();
		jQuery.each(embeds, function(index, embed) {
			if(embed.id == embed_id)
			{
				embeds[index].smart_tags = smart_tags;
				return false; // break
			}
		});
		
		setEmbeds(embeds);
	});
	
	// set up wpforms fields update handler
	jQuery(document).on('wpformsFieldUpdate', function(e, changeFields) {
		
		var fields = Object.assign({}, wpf.getFields()); // shallow copy
		
		// update wpfFields labels in mappings
		jQuery.each(getMappings(), function(i, data) {
			if(!data.hasOwnProperty('wpf_field') || !fields.hasOwnProperty(data.wpf_field))
				return;
			
			var field = fields[data.wpf_field];
			var field_caption = "Field #" + data.wpf_field;
			if(field.hasOwnProperty('label') && field.label != "")
				field_caption = field.label;
			
			jQuery(".pdf-forms-for-wpforms-admin .pdf-mapping-row[data-mapping_id='" + data.mapping_id  + "'] .wpf-field-name").text(field_caption);
		});
		
		// update value mapping drop-downs 
		select2SharedData.wpfFieldsChoices = {};
		jQuery.each(fields, function (id, field) {
			
			if(!field.hasOwnProperty('choices'))
				return;
			
			select2SharedData.wpfFieldsChoices[id] = [];
			jQuery.each(field.choices, function (i, choice) {
				var text = String(choice.label);
				select2SharedData.wpfFieldsChoices[id].push( { id: text, text: text, lowerText: text.toLowerCase() } );
			});
		});
		
		// update wpfFields labels in embeds
		jQuery.each(getEmbeds(), function(i, data) {
			if(!data.hasOwnProperty('wpf_field') || !fields.hasOwnProperty(data.wpf_field))
				return;
			
			var field = fields[data.wpf_field];
			var field_caption = "Field #" + data.wpf_field;
			if(field.hasOwnProperty('label') && field.label != "")
				field_caption = field.label;
			
			jQuery(".pdf-forms-for-wpforms-admin .image-embeds-row[data-embed_id='" + data.id  + "'] .wpf-field-caption").text(field_caption);
		});
	});
	
	// set up wpforms fields delete handler
	jQuery(document).on('wpformsFieldDelete', function(e, id, type) {
		
		var mappings = getMappings();
		for(var i=0; i<mappings.length; i++)
			if(mappings[i].hasOwnProperty('wpf_field'))
				if(mappings[i].wpf_field == id)
				{
					mappings.splice(i, 1);
					i--;
				}
		setMappings(mappings);
		refreshMappings();
		
		var embeds = getEmbeds();
		for(var i=0; i<embeds.length; i++)
		{
			if(embeds[i].hasOwnProperty('wpf_field'))
			{
				if(embeds[i].wpf_field == id)
				{
					embeds.splice(i, 1);
					i--;
				}
			}
		}
		setEmbeds(embeds);
		refreshEmbeds();
	});
	
	// poll until wpforms builder is loaded, then preload data
	// TODO: find a better way to detect when wpforms builder has initialized
	var poll = setInterval(function() {
		if(typeof WPFormsBuilder !== 'undefined'
		&& typeof WPFormsBuilder.settings !== 'undefined'
		&& typeof WPFormsBuilder.settings.formID !== 'undefined')
		{
			clearInterval(poll);
			preloadData();
		}
	}, 100);
});
