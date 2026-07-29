<?php
/**
 * Schema Generator Class
 * Generates JSON-LD structured data for articles
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class MAP_Schema_Generator {
    
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
        add_action('wp_head', array($this, 'output_schema'), 5);
    }
    
    /**
     * Output schema markup in head
     */
    public function output_schema() {
        if (!is_singular('post')) {
            return;
        }
        
        global $post;
        
        $schema = $this->generate_article_schema($post);
        
        if ($schema) {
            echo '<script type="application/ld+json" class="map-schema">';
            echo wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            echo '</script>' . "\n";
        }
    }
    
    /**
     * Generate article schema
     */
    private function generate_article_schema($post) {
        $contributors = get_post_meta($post->ID, '_article_contributors', true);
        
        if (!is_array($contributors)) {
            $contributors = array(
                'authors' => array(),
                'reviewers' => array(),
                'fact_checkers' => array()
            );
        }
        
        // Base schema structure
        $schema = array(
            '@context' => 'http://schema.org',
            '@type' => 'Article',
            'headline' => get_the_title($post),
            'datePublished' => get_the_date('c', $post),
            'dateModified' => get_the_modified_date('c', $post),
        );
        
        // Add main image if exists
        if (has_post_thumbnail($post)) {
            $image_id = get_post_thumbnail_id($post);
            $image_url = wp_get_attachment_image_url($image_id, 'full');
            if ($image_url) {
                $schema['image'] = $image_url;
            }
        }
        
        // Add description/excerpt
        if ($post->post_excerpt) {
            $schema['description'] = wp_strip_all_tags($post->post_excerpt);
        }
        
        // Add article body
        $schema['articleBody'] = wp_strip_all_tags($post->post_content);
        
        // Add URL
        $schema['url'] = get_permalink($post);
        
        // Add publisher information (using site info)
        $schema['publisher'] = array(
            '@type' => 'Organization',
            'name' => get_bloginfo('name'),
            'url' => home_url()
        );
        
        // Add logo if available
        $custom_logo_id = get_theme_mod('custom_logo');
        if ($custom_logo_id) {
            $logo_url = wp_get_attachment_image_url($custom_logo_id, 'full');
            if ($logo_url) {
                $schema['publisher']['logo'] = array(
                    '@type' => 'ImageObject',
                    'url' => $logo_url
                );
            }
        }
        
        // Add authors - always include post author as primary, plus co-authors
        $author_ids = array($post->post_author); // Start with post author
        
        // Add co-authors if any
        if (!empty($contributors['authors'])) {
            $author_ids = array_merge($author_ids, $contributors['authors']);
        }
        
        // Remove duplicates in case post author is also listed as co-author
        $author_ids = array_unique($author_ids);
        
        $schema['author'] = $this->get_person_schemas($author_ids);
        
        // Add reviewers and fact checkers as contributors
        // Schema.org Article supports "contributor" property for additional contributors
        $all_contributors = array();
        
        // Add reviewers as contributors with role
        if (!empty($contributors['reviewers'])) {
            $reviewer_schemas = $this->get_person_schemas($contributors['reviewers'], 'Reviewer');
            if (!empty($reviewer_schemas)) {
                $all_contributors = array_merge($all_contributors, is_array($reviewer_schemas) && isset($reviewer_schemas['@type']) ? array($reviewer_schemas) : $reviewer_schemas);
            }
        }
        
        // Add fact checkers as contributors with role
        if (!empty($contributors['fact_checkers'])) {
            $fact_checker_schemas = $this->get_person_schemas($contributors['fact_checkers'], 'Fact Checker');
            if (!empty($fact_checker_schemas)) {
                $all_contributors = array_merge($all_contributors, is_array($fact_checker_schemas) && isset($fact_checker_schemas['@type']) ? array($fact_checker_schemas) : $fact_checker_schemas);
            }
        }
        
        // Add all contributors if any exist
        if (!empty($all_contributors)) {
            $schema['contributor'] = $all_contributors;
        }
        
        // Add word count
        $word_count = str_word_count(strip_tags($post->post_content));
        if ($word_count > 0) {
            $schema['wordCount'] = $word_count;
        }
        
        // Add keywords/tags
        $tags = get_the_tags($post);
        if ($tags && !is_wp_error($tags)) {
            $keywords = array();
            foreach ($tags as $tag) {
                $keywords[] = $tag->name;
            }
            if (!empty($keywords)) {
                $schema['keywords'] = implode(', ', $keywords);
            }
        }
        
        // Add article section (category)
        $categories = get_the_category($post);
        if ($categories && !is_wp_error($categories)) {
            $schema['articleSection'] = $categories[0]->name;
        }
        
        return $schema;
    }
    
    /**
     * Get person schemas from user IDs
     *
     * @param array $user_ids Array of user IDs
     * @param string $role Optional role to add (e.g., 'Reviewer', 'Fact Checker')
     */
    private function get_person_schemas($user_ids, $role = '') {
        $persons = array();
        
        foreach ($user_ids as $user_id) {
            $user = get_userdata($user_id);
            if (!$user) {
                continue;
            }
            
            $person = array(
                '@type' => 'Person',
                'name' => $user->display_name,
                'url' => get_author_posts_url($user_id)
            );
            
            // Add description if available
            $description = get_user_meta($user_id, 'description', true);
            if ($description) {
                $person['description'] = wp_trim_words($description, 50, '...');
            }
            
            // Add short bio if available (custom field) - takes precedence
            $short_bio = get_user_meta($user_id, '_user_short_bio', true);
            if ($short_bio) {
                $person['description'] = $short_bio;
            }
            
            // Add job title if available (from user profile, not role parameter)
            $job_title = get_user_meta($user_id, 'job_title', true);
            if ($job_title) {
                $person['jobTitle'] = $job_title;
            } elseif ($role) {
                // Only use role parameter if no job title is set
                $person['jobTitle'] = $role;
            }
            
            // Add image/avatar - just the URL, not as ImageObject to avoid schema errors
            $avatar_url = get_avatar_url($user_id, array('size' => 200));
            if ($avatar_url) {
                $person['image'] = $avatar_url;
            }
            
            // Add email if public contact email is set
            $contact_email = get_user_meta($user_id, '_contact_email', true);
            if ($contact_email) {
                $person['email'] = $contact_email;
            }
            
            // Add website if set (personal website takes precedence)
            $website_url = get_user_meta($user_id, '_website_url', true);
            if ($website_url) {
                $person['url'] = $website_url;
            }
            
            // Add social profiles if available
            $social_urls = array();
            
            // Check for common social media user meta
            $twitter = get_user_meta($user_id, 'twitter', true);
            if ($twitter) {
                // Handle both @username and full URL
                if (strpos($twitter, 'http') === 0) {
                    $social_urls[] = $twitter;
                } else {
                    $social_urls[] = 'https://twitter.com/' . ltrim($twitter, '@');
                }
            }
            
            $linkedin = get_user_meta($user_id, 'linkedin', true);
            if ($linkedin) {
                $social_urls[] = $linkedin;
            }
            
            $facebook = get_user_meta($user_id, 'facebook', true);
            if ($facebook) {
                $social_urls[] = $facebook;
            }
            
            $instagram = get_user_meta($user_id, 'instagram', true);
            if ($instagram) {
                // Handle both @username and full URL
                if (strpos($instagram, 'http') === 0) {
                    $social_urls[] = $instagram;
                } else {
                    $social_urls[] = 'https://instagram.com/' . ltrim($instagram, '@');
                }
            }
            
            $youtube = get_user_meta($user_id, 'youtube', true);
            if ($youtube) {
                $social_urls[] = $youtube;
            }
            
            if (!empty($social_urls)) {
                $person['sameAs'] = $social_urls;
            }
            
            $persons[] = $person;
        }
        
        // Return single person or array based on count
        if (count($persons) === 1) {
            return $persons[0];
        }
        
        return $persons;
    }
    
    /**
     * Get schema for a single contributor
     * Public method for use in templates
     */
    public static function get_contributor_schema($user_id) {
        $generator = self::get_instance();
        $schemas = $generator->get_person_schemas(array($user_id));
        return is_array($schemas) ? $schemas : array($schemas);
    }
}