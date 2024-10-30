<?php
// Check user capabilities
if (!current_user_can('manage_options')) {
    return;
}

// Get existing API configurations
$apis = get_option('flexible_api_posts_apis', array());
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    <form action="options.php" method="post">
        <?php
        settings_fields('flexible_api_posts_options');
        do_settings_sections('flexible_api_posts');
        ?>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    $('#trigger-fetch').on('click', function() {
        var $button = $(this);
        var originalText = $button.text();
        $button.text('Processing...').prop('disabled', true);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'trigger_api_fetch',
                nonce: flexibleApiPosts.nonce
            },
            success: function(response) {
                $button.text(originalText).prop('disabled', false);
                if (response.success) {
                    var message = response.data.message + "\n\nDebug Output:\n" + response.data.debug_output;
                    alert(message);
                    console.log('API Fetch Debug Output:', response.data.debug_output);
                } else {
                    var errorMessage = 'Error: ' + response.data.message;
                    if (response.data.trace) {
                        errorMessage += '\n\nStack Trace:\n' + response.data.trace;
                    }
                    alert(errorMessage);
                    console.error('API Fetch Error:', response.data);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                $button.text(originalText).prop('disabled', false);
                var errorMessage = 'An error occurred while triggering the API fetch.\n';
                errorMessage += 'Status: ' + textStatus + '\n';
                errorMessage += 'Error: ' + errorThrown;
                alert(errorMessage);
                console.error('AJAX Error:', jqXHR.responseText);
            }
        });
    });
});
</script>