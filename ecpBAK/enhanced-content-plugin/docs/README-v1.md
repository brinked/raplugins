# Multi-Author Contributor Plugin

A comprehensive WordPress plugin that allows multiple contributors (authors, reviewers, and fact checkers) for articles, with automatic Schema.org markup generation and sources/citations management.

## Features

- **Multiple Contributor Types**: Support for Authors, Reviewers, and Fact Checkers
- **Fact-Check Dates & Corrections Log**: Show when an article was last verified and log corrections publicly
- **Editorial Team Page**: Shortcode/block showcasing your hand-picked review board with article credits
- **Shortcodes & Blocks**: Place any section manually — `[map_contributors]`, `[map_sources]`, `[map_faq]`, `[map_corrections]`, `[map_editorial_team]`
- **Bulk Assignment**: Add a reviewer or fact checker to many posts at once from the posts list
- **Admin Settings Page**: Customize role labels and styling options from WordPress admin
- **Customizable Role Labels**: Change text for "Written by", "Co-Author", "Reviewed by", "Fact-Checked by"
- **Styling Options**: Adjust avatar sizes, font sizes, and text colors
- **Interactive Hover Popups**: Display contributor bios and information on hover
- **Schema.org Markup**: Automatic JSON-LD structured data for better SEO
- **Sources Management**: Add and display article sources with citations
- **User Profile Extensions**: Add short bios, job titles, and social media profiles
- **Responsive Design**: Mobile-friendly interface and popups
- **Touch Support**: Click-to-view popups on mobile devices
- **Customizable**: Hooks and filters for developers

## Requirements

- WordPress 5.0 or higher
- PHP 7.0 or higher
- jQuery (included with WordPress)

## Installation

### Method 1: Manual Installation

1. Download the plugin files
2. Upload the `multi-author-plugin` folder to `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Configure user profiles with contributor information

### Method 2: Upload via WordPress Admin

1. Go to **Plugins > Add New** in WordPress admin
2. Click **Upload Plugin**
3. Choose the plugin ZIP file
4. Click **Install Now**
5. Activate the plugin

## Usage

### Configuring Plugin Settings

1. Go to **Settings > Multi-Author** in WordPress admin
2. Customize role labels to match your site's style
3. Adjust styling options (avatar size, font sizes, colors)
4. Click **Save Settings**
5. View any post with contributors to see the changes

For detailed information, see [docs/SETTINGS-GUIDE.md](docs/SETTINGS-GUIDE.md)

### Setting Up User Profiles

1. Go to **Users > Your Profile** (or edit any user)
2. Scroll to the **Contributor Information** section
3. Fill in the following fields:
   - **Short Bio**: A brief bio (max 200 characters) for hover popups
   - **Job Title/Role**: Professional title (e.g., "Senior Editor")
   - **Editorial Process Link**: Optional link to editorial process page
   - **Social Media Profiles**: Twitter, LinkedIn, Facebook URLs

### Adding Contributors to a Post

1. Create or edit a post
2. Find the **Article Contributors** meta box
3. Click **+ Add Author**, **+ Add Reviewer**, or **+ Add Fact Checker**
4. Search for users by name or email
5. Select users from the search results
6. Drag to reorder contributors (they appear in the order shown)
7. Remove contributors by clicking the X button

### Adding Sources to a Post

1. In the post editor, find the **Article Sources & Citations** meta box
2. Click **+ Add Source**
3. Enter the source URL (required)
4. Optionally enter a label/description for the source
5. Add multiple sources as needed
6. Remove sources by clicking the trash icon

### Frontend Display

**Contributor Badges**: Display below the article title showing:
- Written by [Author Name]
- Reviewed by [Reviewer Name]
- Fact-checked by [Fact Checker Name]

**Hover Popups**: When hovering over a contributor name, a popup shows:
- Profile photo
- Name and job title
- Short bio
- Link to full profile
- Editorial process link (if set)

**Sources Section**: Displays at the bottom of the article:
- Numbered list of sources
- Clickable links
- Optional labels for each source

### Schema.org Markup

The plugin automatically generates JSON-LD structured data including:
- Article information (headline, dates, description)
- Author schema with profile URLs
- ReviewedBy schema for reviewers
- Contributor schema for fact checkers
- Publisher information
- Image and metadata

## Customization

### Filters

```php
// Change editorial process link label
add_filter('map_editorial_process_label', function($label) {
    return 'Our Review Process';
});

// Change sources section title
add_filter('map_sources_title', function($title) {
    return 'References & Citations';
});

// Add or remove supported post types programmatically
add_filter('map_supported_post_types', function($types) {
    $types[] = 'guide';
    return $types;
});

// Disable the Article JSON-LD (e.g. when your SEO plugin outputs it)
add_filter('map_output_schema', '__return_false');

// Reroute a template to a custom location
add_filter('map_template_path', function($path, $template) {
    return $path;
}, 10, 2);
```

### Template Overrides

Copy template files to your theme to customize:

```
your-theme/
└── multi-author-plugin/
    ├── contributor-badges.php
    ├── sources-list.php
    ├── faq-section.php
    └── ai-disclaimer-badge.php
```

### CSS Customization

Override styles in your theme's CSS:

```css
/* Customize contributor badges */
.map-contributors-section {
    background: #your-color;
    border-left-color: #your-brand-color;
}

/* Customize popup appearance */
.map-popup-content {
    border-radius: 10px;
    box-shadow: 0 5px 25px rgba(0,0,0,0.2);
}

/* Customize sources section */
.map-sources-section {
    background: #your-color;
}
```

## Developer Functions

### Check if post has contributors

```php
if (MAP_Frontend_Display::has_contributors($post_id)) {
    // Post has contributors
}
```

### Get all contributors for a post

```php
$contributors = MAP_Frontend_Display::get_all_contributors($post_id);
// Returns array with 'authors', 'reviewers', 'fact_checkers'
```

### Get sources for a post

```php
$sources = MAP_Frontend_Display::get_sources($post_id);
// Returns array of source objects with 'url' and 'label'
```

### Get contributor data

```php
$data = MAP_User_Profile::get_contributor_data($user_id);
// Returns array with all contributor information
```

## Troubleshooting

### Contributors not showing

1. Ensure the plugin is activated
2. Check that contributors are assigned in the post meta box
3. Verify the post is published (not draft)
4. Clear any caching plugins

### Hover popups not working

1. Check that jQuery is loaded
2. Ensure no JavaScript errors in browser console
3. Verify scripts are enqueued (check page source)
4. Try disabling other plugins to check for conflicts

### Schema markup not appearing

1. View page source and search for `application/ld+json`
2. Use Google's Rich Results Test to validate
3. Ensure posts have contributors assigned
4. Check that the post type is 'post'

### Styles not applying

1. Clear browser cache and WordPress cache
2. Check that CSS file is enqueued
3. Inspect elements to see if styles are overridden
4. Try adding `!important` to custom CSS if needed

## Support

For issues, feature requests, or questions:
- Create an issue on GitHub
- Contact support at your-email@example.com
- Visit documentation at your-site.com/docs

## Shortcodes

| Shortcode | What it renders |
|---|---|
| `[map_contributors]` | Contributor badges for the current post |
| `[map_sources]` | Sources list for the current post |
| `[map_faq]` | FAQ accordion for the current post |
| `[map_corrections]` | Corrections & Updates log for the current post |
| `[map_editorial_team include="3,7" columns="3"]` | Editorial team cards |

The Editorial Team is never auto-populated: a person appears only if an administrator enables **"Show on Editorial Team page"** on their profile, or if their user ID is passed explicitly in `include` (which also controls order). Use `exclude` to hide someone from the opt-in list.

Each section also exists as a Gutenberg block (Contributors, Sources & Citations, FAQ Section, Corrections Log, Editorial Team). Set placement to "Manual" under **Settings > Multi-Author > Display Options** to stop the automatic insertion when you place sections yourself.

## Changelog

### Version 1.3.0
- Fact-checked dates, corrections log, editorial team, shortcodes/blocks, bulk assignment, live settings preview, author archive credits, batched recalculation + WP-CLI, privacy tools, Site Health checks, SEO-plugin conflict detection

### Version 1.2.0
- Custom post type support (Settings > Post Types, `map_supported_post_types` filter)
- Theme template overrides and `map_template_path` filter
- `map_output_schema` filter for SEO-plugin compatibility
- Clean uninstall (removes all plugin options and metadata)
- Reviewers/fact-checkers expressed as schema.org Roles for stronger E-E-A-T signals
- Keyboard and touch accessibility for popups and badges
- Article Health dashboard performance overhaul
- Security hardening: XSS, user-enumeration, shortcode-injection, and schema-leak fixes

### Version 1.0.0
- Initial release
- Multiple contributor types (authors, reviewers, fact checkers)
- Hover popups with contributor information
- Schema.org JSON-LD markup
- Sources/citations management
- User profile extensions
- Responsive design
- Touch device support

## Credits

Developed by Your Name
Icon design by Designer Name

## License

This plugin is licensed under the GPL v2 or later.

```
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
```

## Screenshots

### Admin Interface
- Contributor meta box with drag-and-drop ordering
- Sources meta box with repeater fields
- User profile extensions

### Frontend Display
- Contributor badges below article title
- Hover popup with bio and links
- Sources section at bottom of article

### Schema Markup
- JSON-LD structured data in page source
- Google Rich Results validation

## Frequently Asked Questions

**Q: Can I have multiple authors on one article?**
A: Yes! You can add as many authors, reviewers, and fact checkers as needed.

**Q: Will this work with my theme?**
A: Yes, the plugin is designed to work with any WordPress theme. Styles may need minor adjustments.

**Q: Does this affect SEO?**
A: Yes, positively! The plugin adds proper Schema.org markup which helps search engines understand your content better.

**Q: Can I customize the appearance?**
A: Absolutely! Use the provided CSS classes or copy templates to your theme for complete control.

**Q: Does it work on mobile devices?**
A: Yes, the plugin is fully responsive with touch-friendly interactions.

**Q: Can I use this for custom post types?**
A: Yes! Enable any public post type under **Settings > Multi-Author > Post Types** (or use the `map_supported_post_types` filter).

**Q: Is it compatible with page builders?**
A: Yes, it works alongside popular page builders like Elementor, Gutenberg, and others.

**Q: How do I translate the plugin?**
A: The plugin is translation-ready. Use a plugin like Loco Translate or create .po/.mo files in the languages directory.

## Roadmap

Future features planned:
- Bulk contributor assignment
- Contributor statistics dashboard
- Email notifications for contributors
- Custom contributor role types
- Import/export functionality
- WP-CLI commands
- REST API endpoints

## Contributing

Contributions are welcome! Please:
1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Submit a pull request

## Support the Project

If you find this plugin useful, please:
- Rate it on WordPress.org
- Share it with others
- Contribute to development
- Report bugs and suggest features