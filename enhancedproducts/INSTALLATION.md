# Installation Guide
## WooCommerce Enhanced Product Info Plugin

This guide will walk you through the complete installation and setup process for the WooCommerce Enhanced Product Info plugin.

## Prerequisites

Before installing the plugin, ensure your WordPress site meets these requirements:

- ✅ WordPress 5.8 or higher
- ✅ WooCommerce 5.0 or higher installed and activated
- ✅ PHP 7.4 or higher
- ✅ MySQL 5.6 or higher / MariaDB 10.0 or higher

## Installation Methods

### Method 1: WordPress Admin Upload (Recommended)

1. **Download the Plugin**
   - Download the `wc-enhanced-product-info.zip` file

2. **Upload via WordPress Admin**
   - Log in to your WordPress admin dashboard
   - Navigate to **Plugins → Add New**
   - Click the **Upload Plugin** button at the top
   - Click **Choose File** and select the downloaded ZIP file
   - Click **Install Now**

3. **Activate the Plugin**
   - After installation completes, click **Activate Plugin**
   - You should see a success message

### Method 2: FTP/File Manager Upload

1. **Extract the ZIP File**
   - Extract the downloaded ZIP file on your computer
   - You should see a folder named `wc-enhanced-product-info`

2. **Upload via FTP**
   - Connect to your server via FTP (using FileZilla, Cyberduck, etc.)
   - Navigate to `/wp-content/plugins/`
   - Upload the entire `wc-enhanced-product-info` folder

3. **Activate via WordPress Admin**
   - Log in to WordPress admin
   - Go to **Plugins → Installed Plugins**
   - Find "WooCommerce Enhanced Product Info"
   - Click **Activate**

### Method 3: cPanel File Manager

1. **Access cPanel**
   - Log in to your hosting cPanel
   - Open **File Manager**

2. **Navigate to Plugins Directory**
   - Go to `public_html/wp-content/plugins/`
   - (Path may vary: `www/`, `httpdocs/`, etc.)

3. **Upload and Extract**
   - Click **Upload** button
   - Select the ZIP file
   - After upload, select the ZIP file
   - Click **Extract**
   - Delete the ZIP file after extraction

4. **Activate the Plugin**
   - Go to WordPress Admin → Plugins
   - Activate "WooCommerce Enhanced Product Info"

## Initial Configuration

### Step 1: Access Plugin Settings

After activation:
1. Go to **WooCommerce → Enhanced Product Info** in your WordPress admin menu
2. You'll see the plugin settings page

### Step 2: Configure Global Settings

#### Enable Features
Check the boxes for features you want to use:
- ☑️ Enable Free Shipping Badge
- ☑️ Enable Enhanced Stock Status
- ☑️ Enable Custom Dimensions
- ☑️ Enable Product Specifications
- ☑️ Enable Downloads/Manuals
- ☑️ Enable Shipping & Returns
- ☑️ Enable Warranty Information

#### Customize Text Labels
- **Free Shipping Text**: Default is "Free Shipping"
- **In Stock Text**: Default is "In Stock"
- **Out of Stock Text**: Default is "Out of Stock"

#### Choose Display Mode
Select how information should be displayed:
- **Tabs**: Traditional tabbed interface (recommended)
- **Accordion**: Collapsible sections
- **Stacked**: All content visible without tabs

#### Set Global Templates
- Add your default **Shipping & Returns Policy**
- This will apply to all products unless overridden

### Step 3: Save Settings
Click **Save Settings** at the bottom of the page.

## Configuring Your First Product

### Step 1: Edit a Product
1. Go to **Products → All Products**
2. Click on a product to edit it
3. Scroll down to find the **Enhanced Product Information** meta box

### Step 2: Configure Product Features

#### Free Shipping
- ☑️ Check "Enable free shipping badge for this product"

#### Stock Status
- ☑️ Check "Show stock quantity" to display available stock
- Set **Expected Return Date** if product is out of stock

#### Custom Dimensions
1. Click **Add Dimension**
2. Enter Label (e.g., "Diameter")
3. Enter Value (e.g., "10 inches")
4. Add more as needed

#### Product Specifications
1. Click **Add Specification**
2. Enter Label (e.g., "Material")
3. Enter Value (e.g., "Stainless Steel")
4. Add more specifications

#### Downloads/Manuals
1. Click **Add Download**
2. Enter Title (e.g., "User Manual")
3. Either:
   - Paste a URL directly, OR
   - Click **Upload** to select from media library
4. Add more downloads as needed

#### Warranty Information
- Enter **Warranty Period** in years (e.g., 2 or 0.5 for 6 months)
- Add **Warranty Policy URL** (link to manufacturer's page)

#### Custom Shipping & Returns
- Add product-specific policy if different from global
- Leave empty to use global template

### Step 3: Update Product
Click **Update** to save your changes.

## Verification

### Check Frontend Display
1. Visit the product page on your site
2. Verify the following:
   - Free shipping badge appears (if enabled)
   - Stock status shows correctly with color
   - All tabs/sections display properly
   - Downloads are clickable
   - Information is formatted correctly

### Test Functionality
- Click through all tabs/accordion sections
- Test download links
- Verify responsive design on mobile
- Check different display modes

## Troubleshooting Installation

### Plugin Won't Activate

**Error: "Plugin requires WooCommerce"**
- Solution: Install and activate WooCommerce first

**Error: "PHP version too old"**
- Solution: Contact your host to upgrade PHP to 7.4+

**Error: "Plugin files missing"**
- Solution: Re-upload the plugin ensuring all files are present

### Settings Page Not Showing

1. **Clear Cache**
   - Clear WordPress cache
   - Clear browser cache
   - Clear any CDN cache

2. **Check Permissions**
   - Ensure you have "manage_woocommerce" capability
   - Try logging out and back in

3. **Deactivate/Reactivate**
   - Deactivate the plugin
   - Reactivate it
   - Check if menu appears

### Meta Box Not Showing on Products

1. **Screen Options**
   - On product edit page, click "Screen Options" at top
   - Ensure "Enhanced Product Information" is checked

2. **User Role**
   - Ensure you have permission to edit products
   - Try with Administrator account

3. **Conflict Check**
   - Temporarily deactivate other plugins
   - Switch to default theme (Storefront)
   - Check if meta box appears

### Styles Not Loading

1. **Clear All Caches**
   - WordPress cache
   - Browser cache
   - CDN/hosting cache

2. **Check File Permissions**
   - Ensure CSS files are readable (644)
   - Check folder permissions (755)

3. **Regenerate Assets**
   - Deactivate and reactivate plugin
   - Visit a product page to trigger asset loading

### JavaScript Not Working

1. **Check Console**
   - Open browser Developer Tools (F12)
   - Check Console tab for errors
   - Note any error messages

2. **jQuery Conflict**
   - Ensure jQuery is loaded
   - Check for jQuery conflicts with other plugins

3. **Script Loading**
   - Verify scripts are enqueued on product pages
   - Check if files exist in `/assets/js/` folder

## Post-Installation Checklist

- [ ] Plugin activated successfully
- [ ] Global settings configured
- [ ] Display mode selected
- [ ] Global shipping policy added
- [ ] Test product configured
- [ ] Frontend display verified
- [ ] All features working correctly
- [ ] Mobile responsive checked
- [ ] No JavaScript errors
- [ ] Cache cleared

## Getting Help

If you encounter issues not covered in this guide:

1. **Check Documentation**
   - Read the README.md file
   - Review troubleshooting section

2. **Contact Support**
   - Email: support@shinyprints.com
   - Include WordPress version, WooCommerce version, and PHP version
   - Describe the issue with screenshots if possible

3. **Common Resources**
   - WordPress Codex: https://codex.wordpress.org/
   - WooCommerce Docs: https://woocommerce.com/documentation/

## Next Steps

After successful installation:

1. **Configure All Products**
   - Add enhanced information to your products
   - Use bulk edit for common specifications

2. **Customize Styling**
   - Add custom CSS if needed
   - Match your theme colors

3. **Test Thoroughly**
   - Test on different devices
   - Check all product types
   - Verify with real customers

4. **Monitor Performance**
   - Check page load times
   - Monitor for any conflicts
   - Gather user feedback

## Uninstallation

If you need to remove the plugin:

1. **Deactivate First**
   - Go to Plugins page
   - Click "Deactivate" under the plugin

2. **Delete Plugin**
   - After deactivation, click "Delete"
   - Confirm deletion

3. **Data Cleanup**
   - Plugin data is stored in post meta
   - Data will remain in database even after deletion
   - Use a database cleanup plugin if needed

**Note**: Uninstalling will not delete your product data, but the enhanced information will no longer display on the frontend.

---

**Installation Complete!** 🎉

Your WooCommerce store now has enhanced product information capabilities. Start adding detailed information to your products to improve customer experience and increase conversions.