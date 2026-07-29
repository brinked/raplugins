# Cache Clearing Instructions

The plugin has been updated to version 1.1.1 to force browsers to load the new CSS. However, you may still need to manually clear caches.

## 🔄 Steps to Clear All Caches

### 1. **WordPress Plugin Cache** (If you have a caching plugin)

#### WP Super Cache:
1. Go to: **Settings → WP Super Cache**
2. Click **"Delete Cache"**

#### W3 Total Cache:
1. Go to: **Performance → Dashboard**
2. Click **"Empty All Caches"**

#### WP Rocket:
1. Go to: **Settings → WP Rocket**
2. Click **"Clear Cache"**

#### LiteSpeed Cache:
1. Go to: **LiteSpeed Cache → Toolbox**
2. Click **"Purge All"**

### 2. **Browser Cache** (REQUIRED)

#### Chrome / Edge:
1. Press **Ctrl + Shift + Delete** (Windows) or **Cmd + Shift + Delete** (Mac)
2. Select **"Cached images and files"**
3. Click **"Clear data"**
4. Then go to the page and press **Ctrl + F5** (Windows) or **Cmd + Shift + R** (Mac)

#### Alternative - Disable Cache in DevTools:
1. Press **F12** to open Developer Tools
2. Go to **Network** tab
3. Check **"Disable cache"**
4. Keep DevTools open and refresh the page

### 3. **Server Cache** (If applicable)

If you're using server-level caching (Cloudflare, etc.):

#### Cloudflare:
1. Log into Cloudflare
2. Go to **Caching → Configuration**
3. Click **"Purge Everything"**

### 4. **Verify CSS is Loading**

After clearing caches:

1. Open the page in Chrome/Edge
2. Press **F12** to open Developer Tools
3. Go to **Network** tab
4. Refresh the page
5. Look for `public-styles.css?ver=1.1.1`
6. Click on it to verify it contains the new styles

Look for these lines in the CSS:
```css
.map-contributors-inline {
    display: flex !important;
    flex-direction: row !important;
```

## 🐛 If Still Not Working

### Check if CSS File Exists:
Visit directly in browser:
```
https://extcabinets.com/wp-content/plugins/multi-author-plugin/public/css/public-styles.css
```

### Check for PHP Errors:
1. Enable WordPress debug mode
2. Add to `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```
3. Check `/wp-content/debug.log` for errors

### Verify Plugin is Active:
1. Go to **Plugins** in WordPress admin
2. Ensure "Multi-Author Contributor Plugin" is **Active**
3. Try deactivating and reactivating

## 📞 Still Having Issues?

If contributors still display vertically after all cache clearing:

1. Take a screenshot of the page
2. Open browser DevTools (F12)
3. Go to **Elements** tab
4. Find the `.map-contributors-inline` element
5. Check what CSS is being applied
6. Look for any red strikethrough styles (overridden)
7. Share this information for further troubleshooting