$(document).ready(function() {
    // Reusable function for button state management
    function setButtonState($btn, isLoading, originalText = null) {
        if (isLoading) {
            // Store original text if not provided
            if (!originalText) {
                originalText = $btn.data('original-text') || $btn.html();
                $btn.data('original-text', originalText);
            }
            $btn.prop('disabled', true)
                .html('<i class="fa fa-spinner fa-spin"></i> ' + LANG.table_processing)
                .css('color', 'white');
        } else {
            // Restore original text or use default
            const textToRestore = originalText || $btn.data('original-text') || '<i class="fa fa-magic"></i> ' + LANG.use_ai;
            $btn.prop('disabled', false)
                .html(textToRestore)
                .css('color', '');
        }
    }

    // Simple function for consistent AI button HTML
    function createAiButtonHtml(className, id, wrapperClass) {
        // Base button classes
        var btnClass = 'tw-dw-btn tw-dw-btn-primary tw-text-white tw-dw-btn-xs';
        if (className) btnClass += ' ' + className;
        
        // Create button HTML
        var buttonHtml = '<button type="button" class="' + btnClass + '"' +
            (id ? ' id="' + id + '"' : '') + 
            ' style="background: linear-gradient(45deg, #4a90e2, #7b68ee); margin: 5px;">' +
            '<i class="fa fa-magic"></i> ' + LANG.use_ai + 
            '</button>';
        
        // Wrap if wrapper class provided
        if (wrapperClass) {
            return '<div class="' + wrapperClass + '">' + buttonHtml + '</div>';
        }
        
        return buttonHtml;
    }

    // Dashboard AI Builder button – inserted before the "Use a template" button on the dashboard index page
    $('.template_gallery_btn').before(createAiButtonHtml('', 'use_ai_dashboard_btn'));

    // Product Description Modal Code
    $('.product-description-label').after(createAiButtonHtml('get_product_description', null, 'col-sm-4 text-right'));
 
    $('.get_product_description').click(function () {
        var productName = $('input[name="name"]').val().trim();
        var brandValue = $('select[name="brand_id"]').val();
        var brandText = $('select[name="brand_id"] option:selected').text().trim();

        var unitValue = $('select[name="unit_id"]').val();
        var unitText = $('select[name="unit_id"] option:selected').text().trim();

        var categoryValue = $('select[name="category_id"]').val();
        var categoryText = $('select[name="category_id"] option:selected').text().trim();

        var subCategoryValue = $('select[name="sub_category_id"]').val();
        var subCategoryText = $('select[name="sub_category_id"] option:selected').text().trim();

        // 🔒 Validate required fields
        if (!productName || !brandValue || !unitValue) {
            swal({
                text: LANG.fill_name_brand_unit_for_ai,
                icon: "warning",
                dangerMode: true,
            });
            return; // Stop execution
        }

        // 🧠 Prepare AI prompt
        let prompt = `Generate a product description for the following:\n`;
        prompt += `Product Name: ${productName}\n`;
        prompt += `Brand: ${brandText}\n`;
        prompt += `Unit: ${unitText}\n`;
        if (categoryValue) {
            prompt += `Category: ${categoryText}\n`;
        }
        if (subCategoryValue) {
            prompt += `Sub Category: ${subCategoryText}\n`;
        }

        var formData = {
            name: productName,
            description: prompt.trim(),
            _token: $('meta[name="csrf-token"]').attr('content')
        };

        var $btn = $(this);
        setButtonState($btn, true);

        $.ajax({
            url: base_path + "/aiassistance/generate-product-description",
            method: "post",
            data: formData,
            dataType: "json",
            success: function (response) {
                setButtonState($btn, false);

                if (response.success === true) {
                    toastr.success(response.msg);
                    $('.view_modal').html(response.html).modal('show');

                    $(document).off('click', '#use_description').on('click', '#use_description', function () {
                        var descriptionHtml = $('.view_modal .product_description_text').html();
                        tinymce.get('product_description').setContent(descriptionHtml);                        
                        $('.view_modal').modal('hide');
                    });
                } else {
                    toastr.error(response.msg || LANG.something_went_wrong);
                }
            },
            error: function () {
                setButtonState($btn, false);
                toastr.error(LANG.something_went_wrong_try_again);
            }
        });
    });

    $('.image-label').after(createAiButtonHtml('generate_product_image', null, 'col-sm-6 text-right'));

    // Image Generation Code
    $('.generate_product_image').click(function() {
        var productName = $('input[name="name"]').val().trim();
        var brandValue = $('select[name="brand_id"]').val();
        var brandText = $('select[name="brand_id"] option:selected').text().trim();
        var categoryValue = $('select[name="category_id"]').val();
        var categoryText = $('select[name="category_id"] option:selected').text().trim();

        // 🔒 Validate required fields
        if (!productName || !brandValue) {
            swal({
                text: LANG.fill_name_brand_for_image,
                icon: "warning",
                dangerMode: true,
            });
            return;
        }

        // 🎨 Prepare AI prompt
        let prompt = `Generate a high-quality, professional product image based on the following details:\n`;
        prompt += `Product Name: ${productName}\n`;
        prompt += `Brand: ${brandText}\n`;
        if (categoryValue) {
            prompt += `Category: ${categoryText}\n`;
        }
        prompt += `The image should be clean, realistic, and optimized for e-commerce platforms, ensure proper lighting.`;

        var formData = {
            prompt: prompt,
            _token: $('meta[name="csrf-token"]').attr('content')
        };

        var $btn = $(this);
        setButtonState($btn, true);

        $.ajax({
            url: base_path + "/aiassistance/generate-product-image",
            method: "post",
            data: formData,
            dataType: "json",
            success: function(response) {
                setButtonState($btn, false);
                if (response.success == true) {
                    // Show the modal with the generated image
                    $('.view_modal').html(response.html).modal('show');
                } else {
                    toastr.error(response.msg || LANG.something_went_wrong);
                }
            },
            error: function() {
                setButtonState($btn, false);
                toastr.error(LANG.something_went_wrong_try_again);
            }
        });
    });

    // Handle use image button click
    $(document).on('click', '.use-image', function() {
        var $btn = $(this);
        var imageUrl = $btn.data('image-url');
        
        // Disable button and show loading state
        setButtonState($btn, true);
        
        // Use our backend endpoint to fetch the image
        $.ajax({
            url: base_path + "/aiassistance/fetch-product-image",
            method: 'POST',
            data: { 
                url: imageUrl,
                _token: $('meta[name="csrf-token"]').attr('content')
            },
            xhrFields: {
                responseType: 'blob'
            },
            success: function(blob) {
                try {
                    // Generate professional filename
                    const timestamp = new Date().toISOString().replace(/[-:]/g, '').split('.')[0].replace('T', '_');
                    const randomString = Math.random().toString(36).substring(2, 6).toUpperCase();
                    const filename = `${timestamp}_${randomString}.png`;
                    
                    // Create a File object with professional filename
                    const file = new File([blob], filename, { type: 'image/png' });
                    
                    // Create a DataTransfer object
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(file);
                    
                    // Get the file input
                    const fileInput = document.querySelector('input[name="image"]');
                    
                    if (!fileInput) {
                        throw new Error('File input not found');
                    }
                    
                    // Set the files
                    fileInput.files = dataTransfer.files;
                    
                    // Trigger change event
                    $(fileInput).trigger('change');
                    
                    // Close the modal
                    $('.view_modal').modal('hide');
                    
                    // Show success message
                    toastr.success(LANG.image_added_successfully);
                    
                    // Reset button state
                    setButtonState($btn, false);
                } catch (error) {
                    console.error('Error processing image:', error);
                    toastr.error(LANG.something_went_wrong);
                    setButtonState($btn, false);
                }
            },
            error: function(xhr, status, error) {
                console.error('Image fetch failed:', error);
                toastr.error(LANG.something_went_wrong_try_again);
                setButtonState($btn, false);
            }
        });
    });

    // Purchase Modal Code
    $('.use_ai_btn').after(createAiButtonHtml('use_ai_purchase'));

    // Handle click on the AI purchase button
    $('.use_ai_purchase').click(function() {
        // Clear any existing missing SKU warning
        $('.missing-product-warning').empty();
        
        // Load modal content via AJAX
        $.ajax({
            url: base_path + "/aiassistance/get-purchase-modal",
            dataType: 'html',
            success: function(result) {
                $('.view_modal').html(result).modal('show');
            },
            error: function(xhr, ajaxOptions, thrownError) {
                alert("Something went wrong, please try again");
            }
        });
    });

    // Handle purchase form submission
    $(document).on('submit', '#ai_purchase_form', function(e) {
        e.preventDefault();
        var formData = new FormData(this);
        var $form = $(this);
        var $submitBtn = $form.find('button[type="submit"]');
        
        // Disable submit button and show loading state
        setButtonState($submitBtn, true);
        
        $.ajax({
            url: $(this).attr('action'),
            method: "POST",
            data: formData,
            processData: false,
            contentType: false,
            dataType: "json",
            success: function(response) {
                // Reset button state
                setButtonState($submitBtn, false);
                
                if (response.success) {
                    toastr.success(response.msg);
                    
                    // Populate invoice details
                    if (response.invoice_details) {
                        // Set supplier
                        if (response.invoice_details.supplier_id) {
                            // Create a new option for the supplier
                            var supplierOption = new Option(response.invoice_details.supplier_name, response.invoice_details.supplier_id, true, true);
                            $('#supplier_id').append(supplierOption).trigger('change');
                            
                            // Set supplier address if available
                            if (response.invoice_details.supplier_address) {
                                $('#supplier_address_div').html(response.invoice_details.supplier_address);
                            }
                        }
                        
                        // Set reference number
                        $('input[name="ref_no"]').val(response.invoice_details.reference_no);
                        
                        // Set purchase date
                        var purchaseDate = moment(response.invoice_details.purchase_date, 'DD/MM/YYYY HH:mm').format(moment_date_format + ' ' + moment_time_format);
                        $('input[name="transaction_date"]').val(purchaseDate);
                        
                        // Set purchase status
                        $('select[name="status"]').val(response.invoice_details.purchase_status).trigger('change');
                        
                        // Set business location
                        $('select[name="location_id"]').val(response.invoice_details.location_id).trigger('change');
                    }
                    
                    // Add product rows
                    var row_count = $('#row_count').val();
                    append_purchase_lines(response.html, row_count, true);
                    
                    // Display missing SKU details if any
                    if (response.missing_skus && response.missing_skus.length > 0) {
                        displayMissingSkuWarning(response.missing_skus);
                    } else {
                        // Clear any existing missing SKU warning
                        $('.missing-product-warning').empty();
                    }
                    
                    // Hide modal and reset form
                    var modal = $('.view_modal');
                    modal.modal('hide');
                    $('#ai_purchase_form')[0].reset();
                    modal.off('hidden.bs.modal').on('hidden.bs.modal', function () {
                        $('#ai_purchase_form')[0].reset();
                    });
                } else {
                    toastr.error(response.msg || LANG.something_went_wrong);
                    // Hide modal and reset form
                    var modal = $('.view_modal');
                    modal.modal('hide');
                    $('#ai_purchase_form')[0].reset();
                    modal.off('hidden.bs.modal').on('hidden.bs.modal', function () {
                        $('#ai_purchase_form')[0].reset();
                    });
                }
            },
            error: function(xhr, ajaxOptions, thrownError) {
                // Reset button state
                setButtonState($submitBtn, false);
                
                toastr.error(LANG.something_went_wrong_try_again);
                // Hide modal and reset form on error too
                var modal = $('.view_modal');
                modal.modal('hide');
                $('#ai_purchase_form')[0].reset();
                modal.off('hidden.bs.modal').on('hidden.bs.modal', function () {
                    $('#ai_purchase_form')[0].reset();
                });
            }
        });
    });  
    
    
    $('#ai-analysis-container').append(createAiButtonHtml('pull-right', 'ai_profit_loss_analysis_btn'));

    // AI Profit/Loss Analysis Button (Profit/Loss Report Page)
    $(document).on('click', '#ai_profit_loss_analysis_btn', function() {
        var $btn = $(this);
        setButtonState($btn, true);
        var location_id = $('#profit_loss_location_filter').val();
        var dateRange = $('#profit_loss_date_filter').data('daterangepicker');
        var start_date = dateRange ? dateRange.startDate.format('YYYY-MM-DD') : '';
        var end_date = dateRange ? dateRange.endDate.format('YYYY-MM-DD') : '';

        $.ajax({
            url: base_path + '/aiassistance/ai-profit-loss-analysis',
            method: 'POST',
            data: {
                location_id: location_id,
                start_date: start_date,
                end_date: end_date,
                _token: $('meta[name="csrf-token"]').attr('content')
            },
            dataType: 'json',
            success: function(response) {
                setButtonState($btn, false);
                if (response.success && response.html) {
                    // Show the returned modal HTML
                    $('.view_modal').html(response.html).modal('show');
                } else {
                    toastr.error(response.msg || LANG.something_went_wrong);
                }
            },
            error: function(xhr) {
                setButtonState($btn, false);
                toastr.error(LANG.something_went_wrong_try_again);
            }
        });
    });
    
    // Function to display missing SKU warning
    function displayMissingSkuWarning(missingSkus) {
        var warningHtml = '<div class="py-2 px-3 mb-5 text-center">' +
            '<small class="text-muted">' + LANG.missing_skus + ':</small><br>';
        
        missingSkus.forEach(function(item) {
            warningHtml += '<small> Row ' + item.row + ' - ' + item.sku + ' - ' + (item.product_name || 'N/A') + '</small><br>';
        });
        
        warningHtml += '</div>';
        
        $('.missing-product-warning').html(warningHtml);
        
        // Scroll to the warning
        $('html, body').animate({
            scrollTop: $('.missing-product-warning').offset().top - 100
        }, 500);
    }

    // Diet Plan Generation Code
    $('.diet-plan-button').after(createAiButtonHtml('generate_diet_plan', null, 'col-md-12 text-right'));

    // Event 1: Open diet plan modal
    $(document).on('click', '.generate_diet_plan', function() {        
        // Load and show the modal
        $.ajax({
            url: base_path + "/aiassistance/get-diet-plan-modal",
            dataType: 'html',
            success: function(result) {
                console.log('Modal elements found:', $('.view_modal').length);
                $('#diet_plan_modal').html(result).modal('show');
            },
            error: function(xhr, ajaxOptions, thrownError) {
                toastr.error(LANG.something_went_wrong_try_again);
            }
        });
    });

    // Event 2: Submit diet plan form (separate event)
    $(document).on('click', '#generate_diet_plan_btn', function() {
        
        // Get customer profile value
        var customerProfile = $('#customer_profile').val();
    
        customerProfile = customerProfile.trim();

        var $btn = $(this);
        setButtonState($btn, true);

        var requestData = {
            customer_profile: customerProfile,
            _token: $('meta[name="csrf-token"]').attr('content')
        };
        console.log('Sending request data:', requestData);
        
        $.ajax({
            url: base_path + "/aiassistance/generate-diet-plan",
            method: "POST",
            data: requestData,
            dataType: "json",
            success: function(response) {
                console.log('Success response received:', response);
                setButtonState($btn, false);
                
                if (response.success) {
                    // Close current modal and show review modal directly
                    // $('#diet_plan_modal').modal('hide');
                    $('#diet_plan_modal').html(response.html).modal('show');
                } else {
                    toastr.error(response.msg || 'An error occurred');
                }
            },
            error: function(xhr, status, error) {
                setButtonState($btn, false);
                toastr.error(LANG.something_went_wrong_try_again);
            }
        });
    });


    // Event 3: Apply diet plan to form
    $(document).on('click', '#apply_diet_plan_btn', function() {
        console.log('Apply diet plan button clicked');
        
        // Get all form data from review modal
        var formData = {};
        $('#diet_plan_review_form').find('textarea').each(function() {
            formData[$(this).attr('name')] = $(this).val();
        });
        
        console.log('Form data to apply:', formData);
        
        // Fill the main diet form
        fillDietFormFields(formData);
        
        // Close the review modal
        $('#diet_plan_modal').modal('hide');
        
        toastr.success(LANG.diet_plan_applied_to_form_successfully);
    });

    // Function to fill diet form fields
    function fillDietFormFields(dietData) {
        // Map the AI response to form fields
        var fieldMapping = {
            'morning': 'input[name="morning"]',
            'breakfast': 'input[name="breakfast"]',
            'before_lunch': 'input[name="before_lunch"]',
            'lunch': 'input[name="lunch"]',
            'afternoon': 'input[name="afternoon"]',
            'evening': 'input[name="evening"]',
            'dinner': 'input[name="dinner"]',
            'before_sleep': 'input[name="before_sleep"]',
            'before_workout': 'input[name="before_workout"]',
            'after_workout': 'input[name="after_workout"]',
            'remarks': 'textarea[name="remarks"]'
        };

        // Fill each field
        Object.keys(fieldMapping).forEach(function(key) {
            if (dietData[key] && dietData[key].trim()) {
                $(fieldMapping[key]).val(dietData[key].trim());
            }
        });
    }

    // Workout Plan Generation Code
    $('.workout-plan-button').append(createAiButtonHtml('generate_workout_plan', null, 'col-md-12 text-right'));

    // Event 1: Open workout plan modal
    $(document).on('click', '.generate_workout_plan', function() {        
        // Load and show the modal
        $.ajax({
            url: base_path + "/aiassistance/get-workout-plan-modal",
            dataType: 'html',
            success: function(result) {
                console.log('Modal elements found:', $('#workout_plan_modal').length);
                $('#workout_plan_modal').html(result).modal('show');
            },
            error: function(xhr, ajaxOptions, thrownError) {
                toastr.error(LANG.something_went_wrong_try_again);
            }
        });
    });

    // Event 2: Submit workout plan form (separate event)
    $(document).on('click', '#generate_workout_plan_btn', function() {
        
        // Get member profile value
        var memberProfile = $('#member_profile').val();
    
        memberProfile = memberProfile.trim();

        var $btn = $(this);
        setButtonState($btn, true);

        var requestData = {
            member_profile: memberProfile,
            contact_id: $('#contact_id').val(),
            _token: $('meta[name="csrf-token"]').attr('content')
        };
        console.log('Sending request data:', requestData);
        
        $.ajax({
            url: base_path + "/aiassistance/generate-workout-plan",
            method: "POST",
            data: requestData,
            dataType: "json",
            success: function(response) {
                console.log('Success response received:', response);
                setButtonState($btn, false);
                
                if (response.success) {
                    // Show review modal directly
                    $('#workout_plan_modal').html(response.html).modal('show');
                } else {
                    toastr.error(response.msg || 'An error occurred');
                }
            },
            error: function(xhr, status, error) {
                setButtonState($btn, false);
                toastr.error(LANG.something_went_wrong_try_again);
            }
        });
    });

    // Event 3: Apply workout plan to form
    $(document).on('click', '#apply_workout_plan_btn', function() {
        console.log('Apply workout plan button clicked');
        
        // Get all form data from review modal
        var formData = {};
        $('#workout_plan_review_form').find('textarea').each(function() {
            formData[$(this).attr('name')] = $(this).val();
        });
        
        console.log('Form data to apply:', formData);
        
        // Fill the main workout form
        fillWorkoutFormFields(formData);
        
        // Close the review modal
        $('#workout_plan_modal').modal('hide');
        
        toastr.success(LANG.workout_plan_applied_to_form_successfully);
    });

    // Function to fill workout form fields
    function fillWorkoutFormFields(workoutData) {
        // Map the AI response to form fields
        var fieldMapping = {
            'monday': 'textarea[name="monday"]',
            'tuesday': 'textarea[name="tuesday"]',
            'wednesday': 'textarea[name="wednesday"]',
            'thursday': 'textarea[name="thursday"]',
            'friday': 'textarea[name="friday"]',
            'saturday': 'textarea[name="saturday"]',
            'sunday': 'textarea[name="sunday"]',
            'warm_up': 'textarea[name="warm_up"]',
            'cool_down': 'textarea[name="cool_down"]',
            'rest_day_activities': 'textarea[name="rest_day_activities"]',
            'remarks': 'textarea[name="remarks"]'
        };

        // Fill each field
        Object.keys(fieldMapping).forEach(function(key) {
            if (workoutData[key] && workoutData[key].trim()) {
                $(fieldMapping[key]).val(workoutData[key].trim());
            }
        });
    }

    // Communicator Message Generation Code
    $('.message-container').after(createAiButtonHtml('generate_message_ai', null, 'col-md-4 text-right'));

    // Handle click on the AI message generation button
    $('.generate_message_ai').click(function() {
        // Load and show the description modal
        $.ajax({
            url: base_path + "/aiassistance/get-message-description-modal",
            method: "GET",
            dataType: "json",
            success: function(response) {
                if (response.success) {
                    $('.view_modal').html(response.html).modal('show');
                } else {
                    toastr.error(response.msg || LANG.something_went_wrong);
                }
            },
            error: function() {
                toastr.error(LANG.something_went_wrong_try_again);
            }
        });
    });

    // Handle click on generate message button in the description modal
    $(document).on('click', '#generate_message_btn', function() {
        var description = $('#message_description').val().trim();
        
        // Validate description
        if (!description) {
            swal({
                text: LANG.message_description_required,
                icon: "warning",
                dangerMode: true,
            });
            return;
        }

        var $btn = $(this);
        setButtonState($btn, true);

        var formData = {
            description: description,
            _token: $('meta[name="csrf-token"]').attr('content')
        };

        $.ajax({
            url: base_path + "/aiassistance/generate-message",
            method: "post",
            data: formData,
            dataType: "json",
            success: function(response) {
                setButtonState($btn, false);

                if (response.success === true) {
                    
                    toastr.success(response.msg);
                    $('.view_modal').html(response.html).modal('show');
                } else {
                    toastr.error(response.msg || LANG.something_went_wrong);
                }
            },
            error: function() {
                setButtonState($btn, false);
                toastr.error(LANG.something_went_wrong_try_again);
            }
        });
    });

    // Handle copy message button click
    $(document).on('click', '#copy_message_btn', function() {
        var messageText = $('#message_text').text();
        if (messageText && messageText.trim()) {
            // Try to use the existing copyToClipboard function first
            if (typeof copyToClipboard === 'function') {
                copyToClipboard('message_text');
            }
        }
    });

    // Dashboard AI Builder
    $(document).on('click', '#use_ai_dashboard_btn', function() {
        $.ajax({
            url: base_path + '/aiassistance/get-dashboard-ai-modal',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('.ai_dashboard_modal').html(response.html).modal('show');
                } else {
                    toastr.error(response.msg || LANG.something_went_wrong);
                }
            },
            error: function() {
                toastr.error(LANG.something_went_wrong_try_again);
            }
        });
    });

    $(document).on('click', '#generate_ai_dashboard_btn', function() {
        var description = $('#dashboard_ai_description').val().trim();

        if (!description) {
            swal({
                text: LANG.dashboard_description_required,
                icon: 'warning',
                dangerMode: true,
            });
            return;
        }

        var $btn = $(this);
        setButtonState($btn, true);

        $.ajax({
            url: base_path + '/aiassistance/generate-dashboard-widgets',
            method: 'POST',
            data: {
                description: description,
                _token: $('meta[name="csrf-token"]').attr('content')
            },
            dataType: 'json',
            success: function(response) {
                setButtonState($btn, false);
                if (response.success) {
                    toastr.success(response.msg);
                    $('.ai_dashboard_modal').modal('hide');
                    window.location.href = response.redirect_url;
                } else {
                    toastr.error(response.msg || LANG.something_went_wrong);
                }
            },
            error: function() {
                setButtonState($btn, false);
                toastr.error(LANG.something_went_wrong_try_again);
            }
        });
    });

});

