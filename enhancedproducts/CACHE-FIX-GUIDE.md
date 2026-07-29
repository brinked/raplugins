# Cache Fix Guide for Product-Specific Mobile Issues

## Problem Identified
- **Specific Product**: https://extcabinets.com/shop/outdoor-grills/blaze-30-inch-built-in-gas-griddle-lte-copy/
- **Issue**: Product info (warranty, shipping, etc.) NOT in HTML source code on mobile
- **Desktop**: Shows correctly in source code
- **Other Products**: Working fine on mobile
- **Environment**: WP Rocket + Elementor

## Root Cause
This is a **product-specific mobile cache corruption** issue. The mobile version of this particular product page was cached without the enhanced product information HTML.

## Solution Steps (Try in Order)

### Step 1: Clear WP Rocket Cache for Mobile
1. Go to WP Admin > WP Rocket > Settings
2. Click on "Clear Cache" button
3. Specifically look for "Clear Mobile Cache" or "Purge Cache"
4. If available, use "Clear and Preload" to regenerate cache

### Step 2: Clear WP Rocket Cache for Specific URL
Add this parameter to the URL and visit it:
```
https://extcabinets.com/shop/outdoor-grills/blaze-30-inch-built-in-gas-griddle-lte-copy/?nowprocket=1
```

This bypasses WP Rocket cache temporarily. If it works, the cache is the issue.

### Step 3: Regenerate Elementor CSS & Data
1. Go to WP Admin > Elementor > Tools
2. Click "Regenerate CSS & Data"
3. Click "Sync Library" 
4. Wait for completion

### Step 4: Re-save the Product
1. Go to WP Admin > Products
2. Edit the problematic product
3. Scroll down - DO NOT change anything
4. Click "Update" button
5. This forces WordPress to regenerate product metadata

### Step 5: Clear WooCommerce Product Transients
Add this code temporarily to your theme's `functions.php`:

```php
// Temporary - Clear WooCommerce transients for specific product
add_action('init', function() {
    if (isset($_GET['clear_product_cache'])) {
        $product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
        if ($product_id) {
            // Clear product transients
            wc_delete_product_transients($product_id);
            
            // Clear WP Rocket cache for product
            if (function_exists('rocket_clean_post')) {
                rocket_clean_post($product_id);
            }
            
            // Clear all object cache
            wp_cache_flush();
            
            wp_die('Product cache cleared for ID: ' . $product_id);
        }
    }
});
```

Then visit (replace XXXX with actual product ID):
```
https://extcabinets.com/?clear_product_cache=1&product_id=XXXX
```

### Step 6: WP Rocket Mobile Cache Settings
1. Go to WP Rocket > Settings > Cache
2. Check "Separate cache files for mobile devices"
3. Save Settings
4. Clear cache again

### Step 7: Disable Mobile Cache Temporarily
1. Go to WP Rocket > Settings > Cache
2. UNCHECK "Separate cache files for mobile devices"
3. Save Settings
4. Clear cache
5. Test mobile
6. If it works, the issue was mobile-specific cache
7. Re-enable mobile cache after confirmation

### Step 8: Check Elementor Mobile Settings
1. Edit the product page in Elementor
2. Check if there are mobile-specific visibility settings on sections
3. Look for any "Hide on Mobile" toggles
4. Make sure product info sections are visible on all devices

### Step 9: Nuclear Option - Full Cache Clear
```php
// Add to functions.php temporarily
add_action('init', function() {
    if (isset($_GET['nuclear_cache_clear'])) {
        // Clear WP Rocket
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }
        
        // Clear Elementor
        if (class_exists('\Elementor\Plugin')) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }
        
        // Clear WooCommerce
        if (function_exists('wc_delete_product_transients')) {
            global $wpdb;
            $products = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product'");
            foreach ($products as $product_id) {
                wc_delete_product_transients($product_id);
            }
        }
        
        // Clear all WordPress transients
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_%'");
        
        // Flush object cache
        wp_cache_flush();
        
        wp_die('NUCLEAR CACHE CLEAR COMPLETE! Test your mobile now.');
    }
}, 1);
```

Visit: `https://extcabinets.com/?nuclear_cache_clear=1`

**WARNING**: Remove this code from functions.php after use!

## Quick Diagnostic Test

Add this to the problematic product page to see what's happening:

```php
// Add to functions.php temporarily
add_action('wp_footer', function() {
    if (is_product()) {
        global $product;
        $product_id = $product->get_id();
        
        echo '<!-- WCEPI DEBUG -->';
        echo '<!-- Product ID: ' . $product_id . ' -->';
        echo '<!-- Is Mobile: ' . (wp_is_mobile() ? 'YES' : 'NO') . ' -->';
        echo '<!-- Warranty: ' . get_post_meta($product_id, '_wcepi_warranty_period', true) . ' -->';
        echo '<!-- Ships In: ' . get_post_meta($product_id, '_wcepi_ships_in_days', true) . ' -->';
        echo '<!-- Free Shipping: ' . get_post_meta($product_id, '_wcepi_free_shipping', true) . ' -->';
        echo '<!-- /WCEPI DEBUG -->';
    }
});
```

Then view source on mobile - look for these HTML comments to see if data exists.

## WP Rocket Specific Commands

### Via WP-CLI (if available):
```bash
wp rocket clean --confirm
wp cache flush
```

### Via .htaccess:
Add temporarily to force cache bypass:
```apache
# Temporary - Force no cache for specific product
<If "%{REQUEST_URI} =~ m#/blaze-30-inch-built-in-gas-griddle-lte-copy/#">
    Header set Cache-Control "no-cache, no-store, must-revalidate"
    Header set Pragma "no-cache"
    Header set Expires 0
</If>
```

## Verification Steps

After each solution attempt:

1. **Clear browser cache on mobile device**
2. **Use mobile browser's "Request Desktop Site" feature temporarily**
3. **View page source** on mobile (look for `wcepi-` classes)
4. **Use browser dev tools** on mobile (if available)
5. **Try different mobile browser** (Safari vs Brave vs Chrome)
6. **Test in Incognito/Private mode**

## If Nothing Works

### Last Resort Options:

1. **Delete and recreate** the product (export/import data)
2. **Check theme's mobile.css** for hiding rules
3. **Temporarily disable WP Rocket** to confirm it's the culprit
4. **Contact WP Rocket support** with the specific URL
5. **Check server-side cache** (Redis, Memcached, Varnish)

## Prevention

After fixing, prevent future issues:

1. **Exclude product pages from aggressive caching**:
   - WP Rocket > Settings > Advanced Rules
   - Add: `wp-content/plugins/woocommerce/(.*)` to "Never cache URL(s)"
   - Or exclude: `/product/` from cache

2. **Set proper mobile cache settings**:
   - Always use "Separate cache for mobile"
   - Set shorter cache lifetime for product pages

3. **Clear cache after product updates**:
   - Use WP Rocket's "Clear cache when post is published/updated"

## Quick Reference Commands

```bash
# Via WordPress Admin Bar
Admin Bar > WP Rocket > Clear Cache

# Via WP Rocket Settings
WP Rocket > Clear Cache > Clear and Preload

# Via Elementor
Elementor > Tools > Regenerate CSS & Data

# Via Product Edit
Products > Edit Product > Update (no changes needed)
```

## Support Information

If issue persists, collect this info:
- Product ID
- WP Rocket version
- Elementor version  
- WordPress version
- WooCommerce version
- PHP version
- Theme name and version
- Screenshot of mobile view source
- Screenshot of desktop view source