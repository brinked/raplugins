# Multi-Author Plugin - Settings Guide

## Overview

The Multi-Author Plugin now includes a comprehensive settings page that allows you to customize role labels and styling options for contributor badges displayed on your posts.

## Accessing Settings

1. Log in to your WordPress admin dashboard
2. Navigate to **Settings → Multi-Author**
3. The settings page will display all available customization options

## Settings Sections

### 1. Role Labels

Customize the text labels displayed for different contributor roles:

#### Available Label Settings:

- **Written By Label** (Default: "Written by")
  - Label shown for the primary author of the article
  
- **Co-Author Label** (Default: "Co-Author")
  - Label shown for additional authors who contributed to the article
  
- **Reviewed By Label** (Default: "Reviewed by")
  - Label shown for reviewers who reviewed the article
  
- **Fact-Checked By Label** (Default: "Fact-Checked by")
  - Label shown for fact-checkers who verified the article's accuracy

#### Example Use Cases:

- Change "Written by" to "Author:" for a more formal tone
- Use "Authored by" instead of "Written by" for academic content
- Customize to match your site's language or style guide

### 2. Styling Options

Adjust the visual appearance of contributor badges:

#### Avatar/Thumbnail Settings:

- **Avatar/Thumbnail Size** (Default: 60px)
  - Range: 30px - 150px
  - Controls the size of contributor profile images
  - Larger sizes work better for sites with prominent author displays
  - Smaller sizes are ideal for compact layouts

#### Font Size Settings:

- **Name Font Size** (Default: 16px)
  - Range: 10px - 30px
  - Controls the size of contributor names
  
- **Job Title Font Size** (Default: 14px)
  - Range: 10px - 24px
  - Controls the size of job titles displayed under names
  
- **Role Label Font Size** (Default: 12px)
  - Range: 10px - 20px
  - Controls the size of role labels (e.g., "Written by")

#### Color Settings:

- **Name Text Color** (Default: #000000 - Black)
  - Color of contributor names
  - Click the color picker to choose a custom color
  
- **Job Title Text Color** (Default: #666666 - Dark Gray)
  - Color of job titles
  - Should be slightly muted compared to names
  
- **Role Label Text Color** (Default: #999999 - Light Gray)
  - Color of role labels
  - Typically the most subtle of the three text elements

## How to Use the Settings

### Changing Role Labels

1. Navigate to **Settings → Multi-Author**
2. Scroll to the **Role Labels** section
3. Enter your desired text in any of the label fields
4. Click **Save Settings**
5. View any post with contributors to see the changes

### Adjusting Styling

1. Navigate to **Settings → Multi-Author**
2. Scroll to the **Styling Options** section
3. Adjust any of the following:
   - Use number fields to set sizes (type or use arrows)
   - Click color fields to open the color picker
   - Select colors visually or enter hex codes
4. Click **Save Settings**
5. The changes will be applied immediately to all posts

### Using the Color Picker

1. Click on any color field
2. The WordPress color picker will appear
3. Choose a color by:
   - Clicking on the color spectrum
   - Adjusting the hue slider
   - Entering a hex color code (e.g., #FF5733)
4. Click outside the picker or press Enter to confirm
5. Click **Clear** to reset to the default color

## Best Practices

### Role Labels

- **Keep them concise**: Short labels work best in the layout
- **Be consistent**: Use similar formatting across all labels
- **Consider your audience**: Match the tone to your site's style
- **Test readability**: Ensure labels make sense in context

### Styling

- **Maintain hierarchy**: Names should be most prominent, followed by titles, then labels
- **Ensure contrast**: Text colors should be readable against your theme's background
- **Test responsiveness**: Check how your settings look on mobile devices
- **Match your theme**: Choose colors that complement your site's design

### Recommended Settings by Use Case

#### Professional/Corporate Site
```
Labels:
- Written By: "Author:"
- Co-Author: "Contributing Author:"
- Reviewed By: "Reviewed by:"
- Fact-Checked By: "Verified by:"

Styling:
- Avatar Size: 50px
- Name Font: 15px
- Title Font: 13px
- Label Font: 11px
- Colors: Match corporate brand colors
```

#### News/Magazine Site
```
Labels:
- Written By: "By"
- Co-Author: "With"
- Reviewed By: "Edited by"
- Fact-Checked By: "Fact-checked by"

Styling:
- Avatar Size: 60px
- Name Font: 16px
- Title Font: 14px
- Label Font: 12px
- Colors: High contrast for readability
```

#### Academic/Research Site
```
Labels:
- Written By: "Authored by"
- Co-Author: "Co-Authored by"
- Reviewed By: "Peer Reviewed by"
- Fact-Checked By: "Verified by"

Styling:
- Avatar Size: 70px
- Name Font: 17px
- Title Font: 15px
- Label Font: 13px
- Colors: Professional, muted tones
```

## Technical Details

### Database Storage

- All settings are stored in the WordPress options table
- Option name: `map_settings`
- Settings are cached for performance
- Changes take effect immediately after saving

### Default Values

If you clear a setting or it's not set, the plugin will use these defaults:

```php
'label_written_by' => 'Written by'
'label_coauthor' => 'Co-Author'
'label_reviewed_by' => 'Reviewed by'
'label_fact_checked_by' => 'Fact-Checked by'
'avatar_size' => 60
'name_font_size' => 16
'title_font_size' => 14
'role_label_font_size' => 12
'name_color' => '#000000'
'title_color' => '#666666'
'role_label_color' => '#999999'
```

### CSS Implementation

The plugin outputs custom CSS in the `<head>` section of single post pages:

```css
.map-contributor-avatar {
    width: [avatar_size]px !important;
    height: [avatar_size]px !important;
}
.map-contributor-name,
.map-contributor-name a {
    font-size: [name_font_size]px !important;
    color: [name_color] !important;
}
/* Additional styles... */
```

### Programmatic Access

Developers can access settings programmatically:

```php
// Get all settings
$settings = MAP_Settings::get_instance()->get_settings();

// Get a specific setting
$label = MAP_Settings::get_setting('label_written_by', 'Written by');

// Get a role label
$role_label = MAP_Settings::get_role_label('author');
```

## Troubleshooting

### Settings Not Saving

1. Check that you have admin permissions
2. Ensure JavaScript is enabled in your browser
3. Check for plugin conflicts
4. Try deactivating and reactivating the plugin

### Changes Not Appearing

1. Clear your browser cache
2. Clear any WordPress caching plugins
3. Check that you're viewing a post (not a page)
4. Verify the post has contributors assigned

### Color Picker Not Working

1. Ensure WordPress is up to date
2. Check browser console for JavaScript errors
3. Try a different browser
4. Disable other plugins temporarily to check for conflicts

### Styling Conflicts

If your theme's CSS is overriding the plugin settings:

1. The plugin uses `!important` flags for most styles
2. Check your theme's CSS for conflicting rules
3. Contact your theme developer if issues persist
4. Consider using a child theme to add custom CSS

## Support

For additional help:

1. Check the main [README.md](README.md) file
2. Review [TROUBLESHOOTING-DISPLAY-ISSUE.md](TROUBLESHOOTING-DISPLAY-ISSUE.md)
3. See [HOW-TO-CHANGE-ROLE-LABELS.md](HOW-TO-CHANGE-ROLE-LABELS.md) (now deprecated in favor of settings page)

## Changelog

### Version 1.2.0
- Added comprehensive settings page
- Customizable role labels
- Adjustable avatar sizes
- Customizable font sizes
- Color picker for text elements
- Real-time validation for number fields
- Unsaved changes warning

## Future Enhancements

Planned features for future versions:

- Live preview of settings changes
- Import/export settings
- Multiple style presets
- Per-post style overrides
- Custom CSS field for advanced users
- Font family selection
- Border and shadow options