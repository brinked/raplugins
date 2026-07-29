<?php
/**
 * Template Loading Test
 * 
 * Add this to your post content temporarily to test if the plugin is loading:
 * [test_map_template]
 */

// Add shortcode for testing
add_shortcode('test_map_template', function() {
    ob_start();
    ?>
    <div style="background: #ffffcc; padding: 20px; border: 2px solid #ff0000; margin: 20px 0;">
        <h3>Plugin Template Test</h3>
        <p><strong>Plugin Directory:</strong> <?php echo MAP_PLUGIN_DIR; ?></p>
        <p><strong>Template File Exists:</strong> <?php echo file_exists(MAP_PLUGIN_DIR . 'templates/contributor-badges.php') ? 'YES' : 'NO'; ?></p>
        <p><strong>Plugin Version:</strong> <?php echo MAP_VERSION; ?></p>
        <p><strong>Current Post ID:</strong> <?php echo get_the_ID(); ?></p>
        
        <?php
        global $post;
        $contributors = get_post_meta($post->ID, '_article_contributors', true);
        ?>
        <p><strong>Contributors Data:</strong> <?php echo !empty($contributors) ? 'Found' : 'Not Found'; ?></p>
        
        <?php if (!empty($contributors)) : ?>
            <pre><?php print_r($contributors); ?></pre>
        <?php endif; ?>
        
        <p><strong>Template Path:</strong> <?php echo MAP_PLUGIN_DIR . 'templates/contributor-badges.php'; ?></p>
    </div>
    <?php
    return ob_get_clean();
});