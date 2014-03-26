(function() {
    window.componentForm = {
        taskBoxId : null,
        filePath : null,
        url : null,
        valid : false,
        id : false,
        type : null,

        init: function(componentType, formWindow) {
            if (componentType !='NewFile' && componentType != 'NoForm' && componentType != 'Chart' && componentType != 'UploadedFile' && componentType != 'Matrix') {
                this.update(formWindow);
            }
        },

        update: function(dialog, datasetId) {

            var form = dialog.find(".dynamic-container");
            if (form.length == 0)
                var form = $("<div class=\"dynamic-container\"></div>");

            var componentInput = dialog.find(".component-selection select");
            this.id = componentInput.val();

            dialog.append(form);
            window.utils.showProgress();
            dialog.closest(".ui-dialog").find("button").attr("disabled", "disabled");

            this.url = Routing.generate('component_form', {id : componentInput.val() });
            if(datasetId != null)
                this.url += '?dataset_id=' + datasetId;
            $.ajax({
                url: this.url,
                context: form
            }).done(function(resp) {
                $(this).html(resp);
                window.utils.countChange($(this));
                window.utils.hideProgress();
                var buttons = window.componentForm.allButtons();
                dialog.dialog("option", "buttons", buttons);
                dialog.dialog("option", "min-width", 0);
                dialog.dialog("option", "width", "auto");
            });
        },

        allButtons: function() {
            var buttons = [{
                "text": Translator.trans('OK', {}, 'ExperimentBundle'),
                "class": "btn btn-primary",
                "click": function(ev) {
                    window.componentForm.doPost($(this));
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
        },

        doPost: function(context) {
            var data = context.find('input[type=text],input[type=radio]:checked,input[type=hidden],input[type=number],select').serialize();
            $.post(this.url, data, function(resp) {
                context.find(".dynamic-container").html(resp);
                window.componentForm.isValid(context);
                if(window.componentForm.valid) {
                    var params = context.find('input[name=params]').val();
                    var _params = JSON.parse(params);
                    for(i in _params) {
                        window.params.addParam(window.taskBoxes.currentBoxId, i, _params[i])
                    }
                    window.params.addParam
                    context.dialog('close');
                }
            });
        },

        isValid: function(context) {
            if(context.find('li').length == 0)
                window.componentForm.valid = true;
            else
                window.componentForm.valid = false;
        }
    }
})();