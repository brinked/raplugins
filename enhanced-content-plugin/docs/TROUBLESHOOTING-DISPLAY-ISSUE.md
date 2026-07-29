# Troubleshooting Display Issue

If `map-contributors-inline` is not appearing in the page source, the template file is not being loaded. Here's how to diagnose and fix:

## 🔍 Step 1: Verify Plugin is Active

1. Go to **WordPress Admin → Plugins**
2. Find "Multi-Author Contributor Plugin"
3. Ensure it shows **"Active"** (not "Inactive")
4. If inactive, click **"Activate"**

## 🔍 Step 2: Test Template Loading

1. Edit the post at: https://extcabinets.com/wp-admin/post.php?post=[POST_ID]&action=edit
2. Add this shortcode to the content (temporarily):
   ```
   [test_map_template]
   ```
3. Update the post
4. View the post on the frontend
5. You should see a yellow box with diagnostic information
6. Check if it says "Template File Exists: YES"

## 🔍 Step 3: Clear ALL Caches

### WordPress Object Cache:
If you have Redis, Memcached, or object caching:
```php
// Add to wp-config.php temporarily
define('WP_CACHE', false);
```

### Page Cache Plugins:
- **WP Super Cache**: Settings → WP Super Cache → Delete Cache
- **W3 Total Cache**: Performance → Dashboard → Empty All Caches  
- **WP Rocket**: Settings → WP Rocket → Clear Cache
- **LiteSpeed Cache**: LiteSpeed Cache → Toolbox → Purge All

### Server Cache:
If using Cloudflare, Sucuri, or other CDN:
1. Log into your CDN dashboard
2. Find "Purge Cache" or "Clear Cache"
3. Purge everything

## 🔍 Step 4: Check File Permissions

The template file must be readable by the web server:

```bash
# Via SSH or file manager, check:
/wp-content/plugins/multi-author-plugin/templates/contributor-badges.php

# Should have permissions: 644 or 755
```

## 🔍 Step 5: Verify Contributors Are Set

1. Edit the post in WordPress admin
2. Scroll down to "Article Contributors" meta box
3. Ensure you have added contributors (authors, reviewers, or fact-checkers)
4. If empty, the template won't display anything

## 🔍 Step 6: Check for Theme Conflicts

Some themes override plugin templates. Try:

1. Temporarily switch to a default WordPress theme (Twenty Twenty-Four)
2. View the post
3. If it works, your theme is overriding the plugin

## 🔍 Step 7: Check for PHP Errors

1. Enable WordPress debug mode
2. Add to `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```
3. Check `/wp-content/debug.log` for errors

## 🔍 Step 8: Manual Template Check

View the page source (Ctrl+U) and search for:
- `map-contributors-section`
- `map-contributors-inline`  
- `map-contributor-card`

If NONE of these appear, the template is not loading at all.

## 🔧 Quick Fix: Force Template Load

If nothing else works, try deactivating and reactivating the plugin:

1. Go to **Plugins**
2. Click **"Deactivate"** under Multi-Author Plugin
3. Wait 5 seconds
4. Click **"Activate"**
5. Clear all caches again
6. View the post

## 📞 Need More Help?

If still not working, provide:
1. Screenshot of the [test_map_template] output
2. Any errors from debug.log
3. List of active plugins
4. WordPress version
5. PHP version (from Site Health)