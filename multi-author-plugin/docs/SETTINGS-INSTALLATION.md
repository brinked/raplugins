# Settings Feature Installation Guide

## Overview

This guide covers the installation and activation of the new admin settings page feature for the Multi-Author Plugin.

## What's New in Version 1.2.0

The plugin now includes a comprehensive settings page that allows you to:

1. **Customize Role Labels** - Change the text for contributor roles
2. **Adjust Styling** - Modify avatar sizes, font sizes, and colors
3. **Easy Configuration** - All settings accessible from WordPress admin

## Files Added/Modified

### New Files Created:
- [`includes/class-settings.php`](includes/class-settings.php) - Settings page functionality
- [`admin/css/settings-styles.css`](admin/css/settings-styles.css) - Settings page styling
- [`admin/js/settings-scripts.js`](admin/js/settings-scripts.js) - Color picker and validation
- [`SETTINGS-GUIDE.md`](SETTINGS-GUIDE.md) - Comprehensive settings documentation

### Modified Files:
- [`multi-author-plugin.php`](multi-author-plugin.php) - Loads settings class
- [`templates/contributor-badges.php`](templates/contributor-badges.php) - Uses dynamic settings
- [`README.md`](README.md) - Updated with settings information

## Installation Steps

### For New Installations:

1. Upload the complete plugin folder to `/wp-content/plugins/`
2. Activate the plugin through WordPress admin
3. Navigate to **Settings → Multi-Author**
4. Configure your preferred settings
5. Click **Save Settings**

### For Existing Installations (Upgrade):

1. **Backup your site** (recommended before any plugin update)
2. Deactivate the current version of the plugin
3. Replace the plugin folder with the new version
4. Reactivate the plugin
5. Navigate to **Settings → Multi-Author**
6. Review and configure settings as needed

**Note**: Your existing contributor data and post assignments will not be affected by the upgrade.

## Verification Steps

After installation, verify the settings page is working:

1. **Check Settings Page Access**:
   - Go to WordPress admin
   - Click **Settings** in the left menu
   - Look for **Multi-Author** submenu item
   - Click it to open the settings page

2. **Test Settings Functionality**:
   - Change a role label (e.g., "Written by" to "Author:")
   - Click **Save Settings**
   - Look for the success message
   - View a post with contributors to see the change

3. **Test Styling Options**:
   - Adjust avatar size to 80px
   - Change name color using the color picker
   - Click **Save Settings**
   - View a post to see the visual changes

4. **Verify Color Picker**:
   - Click on any color field
   - The WordPress color picker should appear
   - Select a color and confirm it saves

## Default Settings

If you don't configure any settings, the plugin will use these defaults:

```
Role Labels:
- Written by: "Written by"
- Co-Author: "Co-Author"
- Reviewed by: "Reviewed by"
- Fact-Checked by: "Fact-Checked by"

Styling:
- Avatar Size: 60px
- Name Font Size: 16px
- Title Font Size: 14px
- Role Label Font Size: 12px
- Name Color: #000000 (Black)
- Title Color: #666666 (Dark Gray)
- Role Label Color: #999999 (Light Gray)
```

## Troubleshooting

### Settings Page Not Appearing

**Problem**: Can't find "Multi-Author" under Settings menu

**Solutions**:
1. Ensure you're logged in as an administrator
2. Clear browser cache and refresh
3. Deactivate and reactivate the plugin
4. Check for PHP errors in WordPress debug log

### Settings Not Saving

**Problem**: Changes don't persist after clicking Save

**Solutions**:
1. Check file permissions on the WordPress installation
2. Verify database connection is working
3. Disable other plugins temporarily to check for conflicts
4. Check browser console for JavaScript errors

### Color Picker Not Working

**Problem**: Color picker doesn't appear when clicking color fields

**Solutions**:
1. Ensure WordPress is version 5.0 or higher
2. Check that JavaScript is enabled in browser
3. Clear browser cache
4. Try a different browser
5. Check for JavaScript conflicts with other plugins

### Styling Not Applied

**Problem**: Changes to styling options don't appear on frontend

**Solutions**:
1. Clear all caches (browser, WordPress, CDN)
2. Verify you're viewing a post (not a page)
3. Check that the post has contributors assigned
4. Inspect the page source to verify custom CSS is being output
5. Check for theme CSS conflicts

## Database Information

### Settings Storage

All settings are stored in a single WordPress option:

- **Option Name**: `map_settings`
- **Location**: `wp_options` table
- **Format**: Serialized PHP array

### Resetting Settings

To reset all settings to defaults:

1. Go to **Settings → Multi-Author**
2. Clear all fields or set them to default values
3. Click **Save Settings**

Or via database (advanced users):

```sql
DELETE FROM wp_options WHERE option_name = 'map_settings';
```

After deleting, the plugin will use default values.

## Compatibility

### WordPress Versions
- Tested with WordPress 5.0 - 6.4
- Requires WordPress 5.0 minimum for color picker

### PHP Versions
- Tested with PHP 7.0 - 8.2
- Requires PHP 7.0 minimum

### Browser Compatibility
- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+
- Mobile browsers (iOS Safari, Chrome Mobile)

## Migration from Manual Label Changes

If you previously changed role labels by editing template files:

1. Note your current custom labels
2. Install/upgrade to version 1.2.0
3. Go to **Settings → Multi-Author**
4. Enter your custom labels in the appropriate fields
5. Save settings
6. Your template files will now use the settings automatically

**Important**: Do not edit template files directly anymore. Use the settings page instead.

## Support and Documentation

For more information:

- **Settings Guide**: [SETTINGS-GUIDE.md](SETTINGS-GUIDE.md)
- **Main Documentation**: [README.md](README.md)
- **Installation Guide**: [INSTALL.md](INSTALL.md)
- **Troubleshooting**: [TROUBLESHOOTING-DISPLAY-ISSUE.md](TROUBLESHOOTING-DISPLAY-ISSUE.md)

## Changelog

### Version 1.2.0 (Current)
- ✅ Added admin settings page
- ✅ Customizable role labels
- ✅ Adjustable styling options
- ✅ Color picker integration
- ✅ Settings validation
- ✅ Comprehensive documentation

### Version 1.1.x (Previous)
- Inline display layout
- Hover popups
- Schema markup
- Sources management

## Next Steps

After successful installation:

1. Review the [SETTINGS-GUIDE.md](SETTINGS-GUIDE.md) for detailed usage instructions
2. Customize role labels to match your site's style
3. Adjust styling to complement your theme
4. Test on various devices and browsers
5. Configure user profiles with contributor information

## Questions?

If you encounter any issues not covered in this guide:

1. Check the [SETTINGS-GUIDE.md](SETTINGS-GUIDE.md) troubleshooting section
2. Review WordPress debug logs
3. Test with default WordPress theme
4. Disable other plugins to check for conflicts