# Multi-Author Plugin - Complete File Structure

## Overview
This document provides a complete reference of all files in the plugin and their purposes.

## Directory Structure

```
multi-author-plugin/
├── multi-author-plugin.php          # Main plugin file (entry point)
├── README.md                        # Full documentation
├── INSTALL.md                       # Quick installation guide
├── PLUGIN-STRUCTURE.md              # This file
│
├── includes/                        # Core PHP classes
│   ├── class-meta-boxes.php         # Admin meta boxes for contributors & sources
│   ├── class-schema-generator.php   # JSON-LD Schema.org markup generator
│   ├── class-frontend-display.php   # Frontend display logic
│   └── class-user-profile.php       # User profile field extensions
│
├── admin/                           # Admin-side assets
│   ├── css/
│   │   └── admin-styles.css         # Styles for meta boxes and admin UI
│   └── js/
│       └── admin-scripts.js         # JavaScript for sortable lists, user search, etc.
│
├── public/                          # Frontend assets
│   ├── css/
│   │   └── public-styles.css        # Styles for contributor badges, popups, sources
│   └── js/
│       └── hover-popup.js           # JavaScript for hover/click popups
│
└── templates/                       # Display templates
    ├── contributor-badges.php       # Contributor badges display
    ├── contributor-popup.php        # Popup content template
    └── sources-list.php             # Sources/citations display
```

## File Purposes

### Main Plugin File
**multi-author-plugin.php**
- Plugin header with metadata
- Main plugin class initialization
- Dependency loading
- Hook registration
- Asset enqueuing
- Activation/deactivation hooks

### Core Classes

**includes/class-meta-boxes.php**
- Creates meta boxes in post editor
- Handles contributor selection interface
- Manages sources repeater fields
- AJAX user search functionality
- Saves contributor and source data
- Provides drag-and-drop ordering

**includes/class-schema-generator.php**
- Generates JSON-LD structured data
- Creates Article schema
- Adds author, reviewer, and contributor schemas
- Includes publisher information
- Outputs Schema.org markup in page head
- Supports social media profiles in schema

**includes/class-frontend-display.php**
- Displays contributor badges on posts
- Renders sources section
- Handles AJAX for popup content
- Provides helper functions for templates
- Manages content filtering
- Checks for contributors and sources

**includes/class-user-profile.php**
- Adds custom fields to user profiles
- Short bio field (200 char limit)
- Job title field
- Editorial process link
- Social media fields (Twitter, LinkedIn, Facebook)
- Character counter for bio field
- Saves and retrieves user meta data

### Admin Assets

**admin/css/admin-styles.css**
- Meta box styling
- Contributor list styles
- Sortable item appearance
- User search modal design
- Source repeater field styles
- Responsive admin design
- Loading and hover states

**admin/js/admin-scripts.js**
- Drag-and-drop sortable functionality
- User search modal logic
- AJAX user search
- Add/remove contributors
- Sources repeater functionality
- Source renumbering
- Modal management

### Public Assets

**public/css/public-styles.css**
- Contributor badge styling
- Popup design and positioning
- Sources section layout
- Hover effects and animations
- Responsive design for mobile
- Touch device support
- Print styles
- Dark mode support

**public/js/hover-popup.js**
- Hover event handling
- Click events for mobile
- Popup positioning logic
- AJAX popup content loading
- Popup caching
- Keyboard navigation (ESC to close)
- Window resize handling

### Templates

**templates/contributor-badges.php**
- Renders contributor badges
- Formats author/reviewer/fact-checker display
- Includes editorial process link
- Supports multiple contributors per role

**templates/contributor-popup.php**
- Displays popup content
- Shows avatar, name, job title
- Renders short bio
- Includes profile and editorial links

**templates/sources-list.php**
- Renders numbered source list
- Creates clickable source links
- Handles optional labels
- Formats URLs for display
- Adds external link indicators

## Key Features by File

### Data Storage
- **Post Meta**: `_article_contributors` (array), `_article_sources` (array)
- **User Meta**: `_user_short_bio`, `_user_editorial_process_link`, `job_title`, social profiles

### WordPress Hooks Used
- `add_meta_boxes` - Register meta boxes
- `save_post` - Save meta box data
- `the_content` - Add contributors and sources to content
- `wp_head` - Output schema markup
- `wp_enqueue_scripts` - Load public assets
- `admin_enqueue_scripts` - Load admin assets
- `show_user_profile` / `edit_user_profile` - Add user fields
- `personal_options_update` / `edit_user_profile_update` - Save user data

### AJAX Actions
- `map_search_users` - Search for users to add as contributors
- `map_get_contributor_popup` - Load popup content for a contributor

### CSS Classes (Main)
- `.map-contributors-section` - Contributor badges container
- `.map-contributor-badge` - Individual contributor badge
- `.map-contributor-popup` - Hover popup
- `.map-sources-section` - Sources container
- `.map-source-item` - Individual source item

### JavaScript Objects
- `MAP_Admin` - Admin functionality
- `MAP_Public` - Public/frontend functionality

## Database Schema

### Post Meta
```sql
wp_postmeta
├── meta_key: _article_contributors
│   └── meta_value: serialized array
│       ├── authors: [user_id, user_id, ...]
│       ├── reviewers: [user_id, user_id, ...]
│       └── fact_checkers: [user_id, user_id, ...]
│
└── meta_key: _article_sources
    └── meta_value: serialized array
        └── [
            ['url' => 'https://...', 'label' => 'Label'],
            ['url' => 'https://...', 'label' => ''],
            ...
        ]
```

### User Meta
```sql
wp_usermeta
├── meta_key: _user_short_bio (text, max 200 chars)
├── meta_key: _user_editorial_process_link (url)
├── meta_key: job_title (text)
├── meta_key: twitter (text)
├── meta_key: linkedin (url)
└── meta_key: facebook (url)
```

## Integration Points

### Theme Integration
- Filters content via `the_content` hook
- Uses WordPress template hierarchy
- Respects theme styles and structure
- Can be overridden by theme templates

### Plugin Compatibility
- Uses WordPress standards
- Namespaced with MAP_ prefix
- No global scope pollution
- Compatible with caching plugins
- Works with page builders

### SEO Integration
- Outputs valid Schema.org markup
- Supports social media profiles
- Proper article metadata
- Enhanced search result appearance

## Customization Hooks

### Filters
- `map_editorial_process_label` - Change editorial link label
- `map_sources_title` - Change sources section title

### Template Override
Copy templates to theme:
```
your-theme/multi-author-plugin/
├── contributor-badges.php
├── contributor-popup.php
└── sources-list.php
```

## Security Measures

- Nonce verification for all form submissions
- Capability checks before saving data
- Data sanitization on input
- Output escaping for display
- AJAX nonce verification
- SQL injection prevention via WordPress APIs

## Performance Optimizations

- Popup content caching
- Minimal database queries
- Only loads on singular post pages
- Efficient sortable implementation
- Deferred JavaScript loading
- CSS minification ready

## Browser Support

- Modern browsers (Chrome, Firefox, Safari, Edge)
- IE11 with graceful degradation
- Mobile browsers (iOS Safari, Chrome Mobile)
- Touch device optimizations
- Responsive breakpoints at 782px and 480px

## Accessibility Features

- ARIA labels on interactive elements
- Keyboard navigation support
- Screen reader friendly
- Focus indicators
- Semantic HTML structure
- Alternative text for images

## Future Enhancement Points

- Custom post type support
- Bulk contributor assignment
- REST API endpoints
- WP-CLI commands
- Gutenberg block
- Import/export functionality
- Advanced analytics
- Custom contributor types

---

**Version**: 1.0.0
**Total Files**: 15
**Lines of Code**: ~3,500+
**WordPress Required**: 5.0+
**PHP Required**: 7.0+