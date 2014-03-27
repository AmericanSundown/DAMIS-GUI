(function() {
	window.existingFile = {
		init: function(componentType, formWindow) {
			if (componentType == 'UploadedFile') {
				window.existingFile.update(formWindow);
			}
		},

		// send request to the server to obtain file upload form
		update: function(dialog, url, id) {
            var componentInput = dialog.find(".component-id input");
			if (!url) {
                url = Routing.generate('existing_file', {'id' : id});
			}
			var container = dialog.find(".dynamic-container");
			var fileList;
			if (container.length == 0) {
				container = $("<div class=\"dynamic-container\"></div>");
				dialog.append(container);
			} else {
				fileList = container.find(".file-list");
			}
			var outParam = dialog.find("input[value=OUTPUT_CONNECTION]").parent().find("input[name$=value]");
			var data = {}
			if (outParam.val()) {
				data['dataset_url'] = outParam.val();
			}
			dialog.closest(".ui-dialog").find("button").attr("disabled", "disabled");
			window.utils.showProgress();
			$.ajax({
				url: url,
				data: data,
				context: container
			}).done(function(resp) {
				var container = $(this);
				container.html(resp);

				// bind paging handler
				container.find(".pagination a, th a").on("click", function(ev) {
					ev.preventDefault();
					var page_url = $(this).attr("href");
					if (!page_url.match(/.*#.*/g)) {
						window.existingFile.update(dialog, page_url);
					}
				});

				window.utils.initToggleSectionBtn(container);

				dialog.dialog("option", "buttons", window.existingFile.allButtons());
				dialog.dialog("option", "minWidth", 0);
				dialog.dialog("option", "width", "auto");
				window.utils.hideProgress();
			});
		},

		// all buttons of this component dialog
		allButtons: function() {
			var buttons = [{
				"text": Translator.trans('OK', {}, 'ExperimentBundle'),
				"class": "btn btn-primary",
				"click": function(ev) {
					var container = $(this).find(".dynamic-container");
					var datasetInput = container.find("input[name=dataset_pk]:checked");
					if (datasetInput.val()) {
						var fileUrl = $(datasetInput).val();

						// set OUTPUT_CONNECTION value for this component
						var connectionInput = $(this).find(".parameter-values input[value=OUTPUT_CONNECTION]");
						var valueInput = connectionInput.parent().find("input[name$=value]");
						valueInput.val(fileUrl);
						window.existingFile.update($(this), null, fileUrl);
					}
					$(this).dialog("close");
				}
			},
			{
				"text": Translator.trans('Cancel', {}, 'ExperimentBundle'),
				"class": "btn",
				"click": function(ev) {
					$(this).dialog("close");
				}

			}];
			return buttons;
		}
	}
})();

