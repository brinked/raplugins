# Simple Upload Instructions - Mobile Debug

## ✅ Good News!
The file `wc-enhanced-product-info.php` in your local directory ALREADY has the debug code built in!

## 📤 What You Need to Do:

### Step 1: Upload the File
Upload this file to your server, replacing the existing one:
```
FROM: C:\xampp\htdocs\shinyprints\enhancedproducts\wc-enhanced-product-info.php
TO: wp-content/plugins/wc-enhanced-product-info/wc-enhanced-product-info.php
```

### Step 2: Enable Debug Mode
Edit `wp-config.php` on your server and add this line (near the top):
```php
define('WP_DEBUG', true);
```

### Step 3: Test
1. Visit any product page on your mobile device
2. You should see a **black debug panel** at the bottom
3. Screenshot it and share the values

## 🎯 That's It!

Just upload the one file and enable WP_DEBUG. The debug panel will automatically appear on product pages.

## 📱 What the Debug Panel Shows:
- Screen width
- Viewport width
- Badge display property
- Badge width
- Badge float
- Number of icons found
- Icon width

This will tell us exactly what's wrong with the mobile display!

## ⚠️ After Debugging:
Once we fix the issue, you can:
1. Set `WP_DEBUG` back to `false` in wp-config.php
2. The debug panel will automatically disappear