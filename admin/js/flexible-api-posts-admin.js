(function($) {
    'use strict';

    $(document).ready(function() {
        console.log('Flexible API Posts admin script loaded');

        var $apiConfigs = $('#api-configs');
        console.log('API Configs container:', $apiConfigs.length);

        $('#add-api').on('click', function() {
            console.log('Add API button clicked');
            if (typeof flexibleApiPosts === 'undefined' || !flexibleApiPosts.apiConfigTemplate) {
                console.error('flexibleApiPosts object or apiConfigTemplate is not defined');
                return;
            }
            var newApiConfig = flexibleApiPosts.apiConfigTemplate.replace(/\{\{index\}\}/g, Date.now());
            $apiConfigs.append(newApiConfig);
            console.log('New API config added');
        });

        $(document).on('click', '.remove-api', function() {
            console.log('Remove API button clicked');
            $(this).closest('.api-config').remove();
        });

        $(document).on('change', '.auth-type-select', function() {
            var $apiConfig = $(this).closest('.api-config');
            var authType = $(this).val();
            var apiSlug = $apiConfig.find('input[name$="[name]"]').val().toLowerCase().replace(/[^a-z0-9]+/g, '-');
        
            console.log('Auth type changed:', authType);
            console.log('API slug:', apiSlug);
        
            var data = {
                action: 'get_auth_fields',
                nonce: flexibleApiPosts.nonce,
                auth_type: authType,
                api_slug: apiSlug
            };
        
            console.log('Sending AJAX request:', data);
        
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: data,
                dataType: 'json'
            })
            .done(function(response) {
                console.log('AJAX response:', response);
                if (response.success) {
                    $apiConfig.find('.auth-fields').html(response.data.fields).attr('data-auth-type', authType);
                } else {
                    console.error('Error:', response.data.message);
                    alert('Error: ' + response.data.message);
                }
            })
            .fail(function(jqXHR, textStatus, errorThrown) {
                console.error('AJAX request failed:', textStatus, errorThrown);
                console.log('Response:', jqXHR.responseText);
                alert('Error: ' + textStatus);
            });
        });

        $(document).on('click', '.test-api', function() {
            var $apiConfig = $(this).closest('.api-config');
            var $spinner = $apiConfig.find('.spinner');
            var $results = $apiConfig.find('.api-test-results');
            var $response = $results.find('.api-response');
        
            $spinner.css('display', 'inline-block');
            $results.hide();
        
            var data = {
                action: 'test_api',
                nonce: flexibleApiPosts.nonce,
                url: $apiConfig.find('input[name$="[url]"]').val(),
                method: $apiConfig.find('select[name$="[method]"]').val(),
                headers: $apiConfig.find('textarea[name$="[headers]"]').val(),
                body: $apiConfig.find('textarea[name$="[body]"]').val(),
                auth_type: $apiConfig.find('select[name$="[auth_type]"]').val(),
                auth_data: {}
            };
        
            $apiConfig.find('.auth-fields input, .auth-fields select').each(function() {
                var name = $(this).attr('name').match(/\[auth_data\]\[(.*?)\]/)[1];
                data.auth_data[name] = $(this).val();
            });
        
            console.log('Sending API test request:', data);
        
            $.post(ajaxurl, data, function(response) {
                console.log('API test response:', response);
                $spinner.hide();
                $results.show();
        
                var resultHtml = '<h4>API Test Results:</h4>';
                if (response.success) {
                    resultHtml += '<p><strong>Status:</strong> Success</p>';
                    resultHtml += '<p><strong>Message:</strong> ' + (response.data.message || 'No message provided') + '</p>';
                    resultHtml += '<p><strong>Response Code:</strong> ' + (response.data.response_code || 'Not provided') + '</p>';
                    resultHtml += '<h5>Response Body:</h5>';
                    resultHtml += '<pre>' + htmlEncode(JSON.stringify(response.data.response_body, null, 2)) + '</pre>';
                    
                    // Update the API response structure
                    updateApiResponseStructure(JSON.stringify(response.data.response_body));
                } else {
                    resultHtml += '<p><strong>Status:</strong> Error</p>';
                    resultHtml += '<p><strong>Message:</strong> ' + (response.data.message || 'No error message provided') + '</p>';
                    if (response.data.response_code) {
                        resultHtml += '<p><strong>Response Code:</strong> ' + response.data.response_code + '</p>';
                    }
                    if (response.data.response_body) {
                        resultHtml += '<h5>Response Body:</h5>';
                        resultHtml += '<pre>' + htmlEncode(response.data.response_body) + '</pre>';
                    }
                    if (response.data.trace) {
                        resultHtml += '<h5>Error Trace:</h5>';
                        resultHtml += '<pre>' + htmlEncode(response.data.trace) + '</pre>';
                    }
                }
        
                $response.html(resultHtml);
            }).fail(function(jqXHR, textStatus, errorThrown) {
                console.error('AJAX request failed:', jqXHR.responseText);
                $spinner.hide();
                $results.show();
                $response.html('<p>Failed to test API. Error: ' + textStatus + ' - ' + errorThrown + '</p><pre>' + htmlEncode(jqXHR.responseText) + '</pre>');
            });
        });
        
        function updateApiResponseStructure(apiResponse) {
            const $structure = $('.api-response-structure');
            $structure.empty();
            
            function buildStructure(obj, path = '') {
                let $list = $('<ul>');
                for (const [key, value] of Object.entries(obj)) {
                    const fullPath = path ? `${path}.${key}` : key;
                    let $item = $('<li>');
                    if (typeof value === 'object' && value !== null) {
                        let $summary = $('<summary>').text(key);
                        let $details = $('<details>').append($summary);
                        $item.append($details);
                        $details.append(buildStructure(value, fullPath));
                    } else {
                        $item.append($('<div>').addClass('field').attr('data-path', fullPath).text(`${key}: ${typeof value}`));
                    }
                    $list.append($item);
                }
                return $list;
            }
        
            try {
                const parsedResponse = JSON.parse(apiResponse);
                $structure.append(buildStructure(parsedResponse));
                initializeDragAndDrop();
            } catch (error) {
                console.error('Failed to parse API response:', error);
                $structure.html('<p>Error: Unable to parse API response</p>');
            }
        }
        
        function htmlEncode(str) {
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        $(document).on('change', 'select[name$="[post_type]"]', function() {
            var postType = $(this).val();
            updatePostFields(postType);
        });

        // Add this new function to clear mappings
        function clearFieldMappings() {
            $('.post-fields .field').removeClass('mapped').text(function() {
                return $(this).data('field');
            });
            $('textarea[name$="[mapping]"]').val('{}');
        }

        function updatePostFields(postType) {
            var data = {
                action: 'get_post_fields',
                nonce: flexibleApiPosts.nonce,
                post_type: postType
            };

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: data,
                dataType: 'json'
            })
            .done(function(response) {
                if (response.success) {
                    const $postFields = $('.post-fields');
                    $postFields.empty();
                    response.data.fields.forEach(field => {
                        $postFields.append(`<div class="field" data-field="${field}">${field}</div>`);
                    });
                    initializeDragAndDrop();
                    clearFieldMappings();
                } else {
                    console.error('Error:', response.data.message);
                }
            })
            .fail(function(jqXHR, textStatus, errorThrown) {
                console.error('AJAX request failed:', textStatus, errorThrown);
            });
        }

        $('form').on('submit', function(e) {
            $('.api-config').each(function(index) {
                var $this = $(this);
                var apiName = $this.find('input[id$="-name"]').val();
                var slug = apiName.toLowerCase().replace(/[^a-z0-9]+/g, '-');
                $this.find('input, select, textarea').each(function() {
                    var name = $(this).attr('name');
                    $(this).attr('name', name.replace(/\[.*?\]/, '[' + slug + ']'));
                });
            });
        });
    });

    function initializeDragAndDrop() {
        $('.api-response-structure .field').draggable({
            helper: 'clone',
            revert: 'invalid',
            start: function(event, ui) {
                $(this).addClass('dragging');
                console.log('Drag started:', $(this).data('path'));
            },
            stop: function(event, ui) {
                $(this).removeClass('dragging');
                console.log('Drag stopped');
            }
        });
    
        $('.post-fields .field').droppable({
            accept: '.api-response-structure .field',
            hoverClass: 'drop-hover',
            drop: function(event, ui) {
                const apiField = ui.draggable.data('path');
                const postField = $(this).data('field');
                console.log('Drop event - API Field:', apiField, 'Post Field:', postField);
                updateFieldMapping(apiField, postField);
            }
        });
    
        console.log('Drag and drop initialized');
        console.log('Draggable elements:', $('.api-response-structure .field').length);
        console.log('Droppable elements:', $('.post-fields .field').length);
    }
    
    function updateFieldMapping(apiField, postField) {
        console.log('Updating field mapping:', apiField, '->', postField);
        const $mappingInput = $('textarea[name$="[mapping]"]');
        let mapping = {};
        try {
            mapping = JSON.parse($mappingInput.val());
        } catch (error) {
            console.error('Failed to parse existing mapping:', error);
        }
    
        // Remove the array index from the apiField
        const cleanApiField = apiField.replace(/\.\d+\./, '.');
        console.log('Cleaned API field:', cleanApiField);
    
        mapping[postField] = cleanApiField;
        $mappingInput.val(JSON.stringify(mapping, null, 2));
    
        // Update the visual representation
        const $postField = $(`.post-fields .field[data-field="${postField}"]`);
        $postField.addClass('mapped').text(`${postField} ⟶ ${cleanApiField}`);
        console.log('Updated visual representation for:', postField);
    }

    function updateObjectPath() {
        const $objectPathInput = $('input[name$="[object_path]"]');
        const $mappingInput = $('textarea[name$="[mapping]"]');
        let mapping = {};
        try {
            mapping = JSON.parse($mappingInput.val());
        } catch (error) {
            console.error('Failed to parse existing mapping:', error);
            return;
        }
    
        const objectPath = $objectPathInput.val().trim();
        console.log('Updating object path:', objectPath);
        
        // Don't modify the mapping if object path is empty
        if (objectPath === '') {
            console.log('Object path is empty, not modifying mapping');
            return;
        }
    
        // Update all mappings to include the object path
        for (const [postField, apiField] of Object.entries(mapping)) {
            // Only prepend the object path if it's not already there
            if (!apiField.startsWith(objectPath)) {
                mapping[postField] = `${objectPath}.${apiField.replace(/^\./, '')}`;
            }
        }
    
        $mappingInput.val(JSON.stringify(mapping, null, 2));
        console.log('Updated mapping:', mapping);
    
        // Update visual representation
        $('.post-fields .field.mapped').each(function() {
            const postField = $(this).data('field');
            $(this).text(`${postField} ⟶ ${mapping[postField]}`);
        });
    }
    
    // Add event listener for object path input
    $(document).on('input', 'input[name$="[object_path]"]', updateObjectPath);
    
    // Make sure this function is called when the document is ready
    $(document).ready(function() {
        console.log('Document ready, initializing drag and drop');
        initializeDragAndDrop();
        updateObjectPath(); // Initialize object path if it's already set
    });

})(jQuery);