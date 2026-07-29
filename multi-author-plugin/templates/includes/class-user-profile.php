<?php
/**
 * User Profile Class
 * Adds custom fields to user profiles for contributor information
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class MAP_User_Profile {
    
    /**
     * Instance of this class
     */
    private static $instance = null;
    
    /**
     * Get instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        // Add custom fields to user profile
        add_action('show_user_profile', array($this, 'add_custom_user_fields'));
        add_action('edit_user_profile', array($this, 'add_custom_user_fields'));
        
        // Save custom fields
        add_action('personal_options_update', array($this, 'save_custom_user_fields'));
        add_action('edit_user_profile_update', array($this, 'save_custom_user_fields'));
        
        // Add columns to users list
        add_filter('manage_users_columns', array($this, 'add_user_columns'));
        add_filter('manage_users_custom_column', array($this, 'render_user_column_content'), 10, 3);
    }
    
    /**
     * Add custom fields to user profile
     */
    public function add_custom_user_fields($user) {
        $short_bio = get_user_meta($user->ID, '_user_short_bio', true);
        $editorial_link = get_user_meta($user->ID, '_user_editorial_process_link', true);
        $job_title = get_user_meta($user->ID, 'job_title', true);
        $contact_email = get_user_meta($user->ID, '_contact_email', true);
        $website_url = get_user_meta($user->ID, '_website_url', true);
        $twitter = get_user_meta($user->ID, 'twitter', true);
        $linkedin = get_user_meta($user->ID, 'linkedin', true);
        $facebook = get_user_meta($user->ID, 'facebook', true);
        $instagram = get_user_meta($user->ID, 'instagram', true);
        $youtube = get_user_meta($user->ID, 'youtube', true);
        ?>
        
        <h2><?php _e('Contributor Information', 'multi-author-plugin'); ?></h2>
        <p class="description">
            <?php _e('This information is used when you are listed as a contributor on articles.', 'multi-author-plugin'); ?>
        </p>
        
        <table class="form-table">
            <tr>
                <th>
                    <label for="user_short_bio"><?php _e('Short Bio', 'multi-author-plugin'); ?></label>
                </th>
                <td>
                    <textarea 
                        name="user_short_bio" 
                        id="user_short_bio" 
                        rows="3" 
                        cols="50" 
                        class="regular-text"
                        maxlength="200"
                    ><?php echo esc_textarea($short_bio); ?></textarea>
                    <p class="description">
                        <?php _e('A brief bio (max 200 characters) that appears in the contributor hover popup. This should be concise and highlight your expertise.', 'multi-author-plugin'); ?>
                    </p>
                    <p class="description">
                        <strong><?php _e('Character count:', 'multi-author-plugin'); ?></strong> 
                        <span id="user-short-bio-count"><?php echo strlen($short_bio); ?></span>/200
                    </p>
                </td>
            </tr>
            
            <tr>
                <th>
                    <label for="job_title"><?php _e('Job Title / Role', 'multi-author-plugin'); ?></label>
                </th>
                <td>
                    <input 
                        type="text" 
                        name="job_title" 
                        id="job_title" 
                        value="<?php echo esc_attr($job_title); ?>" 
                        class="regular-text"
                    />
                    <p class="description">
                        <?php _e('Your professional title or role (e.g., "Senior Editor", "Fact Checker").', 'multi-author-plugin'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th>
                    <label for="user_editorial_process_link"><?php _e('Editorial Process Link', 'multi-author-plugin'); ?></label>
                </th>
                <td>
                    <input 
                        type="url" 
                        name="user_editorial_process_link" 
                        id="user_editorial_process_link" 
                        value="<?php echo esc_url($editorial_link); ?>" 
                        class="regular-text"
                        placeholder="https://"
                    />
                    <p class="description">
                        <?php _e('Optional: Link to a page describing your editorial process or review methodology.', 'multi-author-plugin'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th>
                    <label for="contact_email"><?php _e('Public Contact Email', 'multi-author-plugin'); ?></label>
                </th>
                <td>
                    <input
                        type="email"
                        name="contact_email"
                        id="contact_email"
                        value="<?php echo esc_attr($contact_email); ?>"
                        class="regular-text"
                        placeholder="contact@example.com"
                    />
                    <p class="description">
                        <?php _e('Optional: Public email address to display in contributor popup (different from WordPress login email).', 'multi-author-plugin'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th>
                    <label for="website_url"><?php _e('Personal Website', 'multi-author-plugin'); ?></label>
                </th>
                <td>
                    <input
                        type="url"
                        name="website_url"
                        id="website_url"
                        value="<?php echo esc_url($website_url); ?>"
                        class="regular-text"
                        placeholder="https://yourwebsite.com"
                    />
                    <p class="description">
                        <?php _e('Optional: Your personal website or portfolio URL.', 'multi-author-plugin'); ?>
                    </p>
                </td>
            </tr>
        </table>
        
        <h3><?php _e('Social Media Profiles', 'multi-author-plugin'); ?></h3>
        <p class="description">
            <?php _e('Social media profiles will be included in structured data (Schema.org) for better SEO.', 'multi-author-plugin'); ?>
        </p>
        
        <table class="form-table">
            <tr>
                <th>
                    <label for="twitter"><?php _e('Twitter Username', 'multi-author-plugin'); ?></label>
                </th>
                <td>
                    <input 
                        type="text" 
                        name="twitter" 
                        id="twitter" 
                        value="<?php echo esc_attr($twitter); ?>" 
                        class="regular-text"
                        placeholder="@username"
                    />
                    <p class="description">
                        <?php _e('Your Twitter/X username (with or without @).', 'multi-author-plugin'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th>
                    <label for="linkedin"><?php _e('LinkedIn Profile', 'multi-author-plugin'); ?></label>
                </th>
                <td>
                    <input 
                        type="url" 
                        name="linkedin" 
                        id="linkedin" 
                        value="<?php echo esc_url($linkedin); ?>" 
                        class="regular-text"
                        placeholder="https://linkedin.com/in/username"
                    />
                    <p class="description">
                        <?php _e('Full URL to your LinkedIn profile.', 'multi-author-plugin'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th>
                    <label for="facebook"><?php _e('Facebook Profile', 'multi-author-plugin'); ?></label>
                </th>
                <td>
                    <input 
                        type="url" 
                        name="facebook" 
                        id="facebook" 
                        value="<?php echo esc_url($facebook); ?>" 
                        class="regular-text"
                        placeholder="https://facebook.com/username"
                    />
                    <p class="description">
                        <?php _e('Full URL to your Facebook profile.', 'multi-author-plugin'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th>
                    <label for="instagram"><?php _e('Instagram Profile', 'multi-author-plugin'); ?></label>
                </th>
                <td>
                    <input
                        type="text"
                        name="instagram"
                        id="instagram"
                        value="<?php echo esc_attr($instagram); ?>"
                        class="regular-text"
                        placeholder="@username or https://instagram.com/username"
                    />
                    <p class="description">
                        <?php _e('Your Instagram username (with or without @) or full URL.', 'multi-author-plugin'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th>
                    <label for="youtube"><?php _e('YouTube Channel', 'multi-author-plugin'); ?></label>
                </th>
                <td>
                    <input
                        type="url"
                        name="youtube"
                        id="youtube"
                        value="<?php echo esc_url($youtube); ?>"
                        class="regular-text"
                        placeholder="https://youtube.com/@username"
                    />
                    <p class="description">
                        <?php _e('Full URL to your YouTube channel.', 'multi-author-plugin'); ?>
                    </p>
                </td>
            </tr>
        </table>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Character counter for short bio
            $('#user_short_bio').on('input', function() {
                var length = $(this).val().length;
                $('#user-short-bio-count').text(length);
                
                if (length > 200) {
                    $('#user-short-bio-count').css('color', 'red');
                } else {
                    $('#user-short-bio-count').css('color', 'inherit');
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Save custom user fields
     */
    public function save_custom_user_fields($user_id) {
        if (!current_user_can('edit_user', $user_id)) {
            return false;
        }
        
        // Save short bio
        if (isset($_POST['user_short_bio'])) {
            $short_bio = sanitize_textarea_field($_POST['user_short_bio']);
            // Limit to 200 characters
            $short_bio = substr($short_bio, 0, 200);
            update_user_meta($user_id, '_user_short_bio', $short_bio);
        }
        
        // Save editorial process link
        if (isset($_POST['user_editorial_process_link'])) {
            $editorial_link = esc_url_raw($_POST['user_editorial_process_link']);
            update_user_meta($user_id, '_user_editorial_process_link', $editorial_link);
        }
        
        // Save job title
        if (isset($_POST['job_title'])) {
            update_user_meta($user_id, 'job_title', sanitize_text_field($_POST['job_title']));
        }
        
        // Save contact email
        if (isset($_POST['contact_email'])) {
            update_user_meta($user_id, '_contact_email', sanitize_email($_POST['contact_email']));
        }
        
        // Save website URL
        if (isset($_POST['website_url'])) {
            update_user_meta($user_id, '_website_url', esc_url_raw($_POST['website_url']));
        }
        
        // Save social media
        if (isset($_POST['twitter'])) {
            $twitter = sanitize_text_field($_POST['twitter']);
            update_user_meta($user_id, 'twitter', $twitter);
        }
        
        if (isset($_POST['linkedin'])) {
            update_user_meta($user_id, 'linkedin', esc_url_raw($_POST['linkedin']));
        }
        
        if (isset($_POST['facebook'])) {
            update_user_meta($user_id, 'facebook', esc_url_raw($_POST['facebook']));
        }
        
        if (isset($_POST['instagram'])) {
            $instagram = sanitize_text_field($_POST['instagram']);
            update_user_meta($user_id, 'instagram', $instagram);
        }
        
        if (isset($_POST['youtube'])) {
            update_user_meta($user_id, 'youtube', esc_url_raw($_POST['youtube']));
        }
    }
    
    /**
     * Add custom columns to users list
     */
    public function add_user_columns($columns) {
        $columns['contributor_info'] = __('Contributor Info', 'multi-author-plugin');
        return $columns;
    }
    
    /**
     * Render custom column content
     */
    public function render_user_column_content($value, $column_name, $user_id) {
        if ('contributor_info' !== $column_name) {
            return $value;
        }
        
        $short_bio = get_user_meta($user_id, '_user_short_bio', true);
        $job_title = get_user_meta($user_id, 'job_title', true);
        
        if ($job_title) {
            echo '<strong>' . esc_html($job_title) . '</strong><br>';
        }
        
        if ($short_bio) {
            echo '<span class="description">' . esc_html(wp_trim_words($short_bio, 15)) . '</span>';
        } else {
            echo '<span class="description" style="color: #999;">' . __('No bio set', 'multi-author-plugin') . '</span>';
        }
        
        return $value;
    }
    
    /**
     * Get user's complete contributor data
     */
    public static function get_contributor_data($user_id) {
        $user = get_userdata($user_id);
        
        if (!$user) {
            return null;
        }
        
        return array(
            'id' => $user_id,
            'display_name' => $user->display_name,
            'email' => $user->user_email,
            'avatar_url' => get_avatar_url($user_id),
            'profile_url' => get_author_posts_url($user_id),
            'short_bio' => get_user_meta($user_id, '_user_short_bio', true),
            'bio' => get_user_meta($user_id, 'description', true),
            'job_title' => get_user_meta($user_id, 'job_title', true),
            'editorial_link' => get_user_meta($user_id, '_user_editorial_process_link', true),
            'contact_email' => get_user_meta($user_id, '_contact_email', true),
            'website_url' => get_user_meta($user_id, '_website_url', true),
            'twitter' => get_user_meta($user_id, 'twitter', true),
            'linkedin' => get_user_meta($user_id, 'linkedin', true),
            'facebook' => get_user_meta($user_id, 'facebook', true),
            'instagram' => get_user_meta($user_id, 'instagram', true),
            'youtube' => get_user_meta($user_id, 'youtube', true),
        );
    }
}