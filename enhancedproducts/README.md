# WooCommerce Enhanced Product Info

A comprehensive WordPress plugin that enhances WooCommerce product pages with advanced information display options including free shipping badges, enhanced stock status, custom dimensions, specifications, downloads, and warranty information.

## Features

### 🚚 Free Shipping Badge
- Display customizable "Free Shipping" badge next to product price
- Enable/disable per product
- Customizable badge text

### 📦 Enhanced Stock Status
- Color-coded stock status (green for in stock, red for out of stock)
- Display actual stock quantity
- Show expected return date for out-of-stock items
- Automatic stock depletion with orders (uses WooCommerce stock management)

### 📏 Custom Dimensions
- Display default WooCommerce dimensions (Width, Depth, Height)
- Add unlimited custom dimension fields
- Perfect for products with special measurements

### 📋 Product Specifications
- Add unlimited custom specification fields
- Display in clean, organized table format
- Ideal for technical product details

### 📄 Downloads/Manuals
- Upload or link to product PDFs
- Support for manuals, datasheets, and documentation
- Easy file management with WordPress media library

### 🚢 Shipping & Returns
- Global shipping and returns policy template
- Override with product-specific policies
- Rich text editor support

### 🛡️ Warranty Information
- Display warranty period in years
- Link to manufacturer warranty policy
- Per-product warranty settings

### 🎨 Display Modes
- **Tabs Mode**: Traditional tabbed interface (default)
- **Accordion Mode**: Collapsible accordion sections
- **Stacked Mode**: All information displayed without tabs

## Installation

### Automatic Installation
1. Download the plugin ZIP file
2. Go to WordPress Admin → Plugins → Add New
3. Click "Upload Plugin" and select the ZIP file
4. Click "Install Now" and then "Activate"

### Manual Installation
1. Download and extract the plugin files
2. Upload the `wc-enhanced-product-info` folder to `/wp-content/plugins/`
3. Activate the plugin through the 'Plugins' menu in WordPress

### Requirements
- WordPress 5.8 or higher
- WooCommerce 5.0 or higher
- PHP 7.4 or higher

## Configuration

### Global Settings

Navigate to **WooCommerce → Enhanced Product Info** to configure global settings:

#### Feature Toggles
- Enable/disable each feature globally
- Customize text labels for badges and stock status
- Set display mode (Tabs, Accordion, or Stacked)

#### Global Templates
- Set default shipping and returns policy
- This content will apply to all products unless overridden

### Product-Specific Settings

When editing a product, scroll down to the **Enhanced Product Information** meta box:

#### Free Shipping
- Check the box to enable free shipping badge for this product

#### Stock Status
- Enable "Show stock quantity" to display available quantity
- Set expected return date for out-of-stock items

#### Custom Dimensions
- Click "Add Dimension" to add custom fields
- Enter label (e.g., "Diameter") and value (e.g., "10 inches")
- Add as many as needed

#### Product Specifications
- Click "Add Specification" to add custom fields
- Enter label (e.g., "Material") and value (e.g., "Stainless Steel")
- Perfect for technical details

#### Downloads/Manuals
- Click "Add Download" to add files
- Enter title and either paste URL or click "Upload" to select from media library
- Supports PDFs, DOCs, and other document formats

#### Warranty Information
- Enter warranty period in years (supports decimals like 0.5 for 6 months)
- Add URL to manufacturer's warranty policy page

#### Custom Shipping & Returns
- Override global policy with product-specific content
- Leave empty to use global template

## Usage Examples

### Example 1: Electronics Product
```
Free Shipping: ✓ Enabled
Stock Status: In Stock (25 available)

Custom Dimensions:
- Screen Size: 15.6 inches
- Resolution: 1920x1080

Specifications:
- Processor: Intel Core i7
- RAM: 16GB DDR4
- Storage: 512GB SSD
- Graphics: NVIDIA GTX 1650

Downloads:
- User Manual (PDF)
- Quick Start Guide (PDF)
- Driver Downloads (Link)

Warranty: 2 years manufacturer warranty
```

### Example 2: Furniture Product
```
Free Shipping: ✓ Enabled
Stock Status: Out of Stock - Expected: 2024-12-15

Dimensions:
- Width: 72 inches
- Depth: 36 inches
- Height: 30 inches
- Seat Height: 18 inches
- Arm Height: 25 inches

Specifications:
- Material: Solid Oak
- Finish: Natural
- Weight Capacity: 300 lbs
- Assembly Required: Yes

Downloads:
- Assembly Instructions (PDF)
- Care Guide (PDF)

Warranty: 5 years structural warranty
```

## Display Modes

### Tabs Mode (Default)
Information is organized in traditional tabs that users can click to switch between sections.

### Accordion Mode
Each section has a header that can be clicked to expand/collapse the content. Only one section is open at a time.

### Stacked Mode
All information is displayed in a single column without tabs or accordions. Best for products with limited information.

## Styling & Customization

### CSS Customization
The plugin includes comprehensive CSS that can be customized. Add custom CSS in your theme or use:

```css
/* Customize free shipping badge */
.wcepi-free-shipping-badge {
    background: #your-color;
    color: #your-text-color;
}

/* Customize stock status colors */
.wcepi-in-stock {
    color: #your-green;
}

.wcepi-out-of-stock {
    color: #your-red;
}
```

### Template Overrides
Copy template files from the plugin to your theme to customize:
```
your-theme/woocommerce/wcepi/
```

## Frequently Asked Questions

### Does this work with my theme?
Yes! The plugin is designed to work with any WooCommerce-compatible theme.

### Will it slow down my site?
No. The plugin only loads assets on product pages and is optimized for performance.

### Can I use it with variable products?
Yes, all features work with simple and variable products.

### Does it support translations?
Yes, the plugin is translation-ready with .pot file included.

### How do I disable a feature for specific products?
Simply don't add content for that feature on the product edit page. Empty sections won't display.

## Troubleshooting

### Free shipping badge not showing
1. Check that the feature is enabled in settings
2. Verify the checkbox is checked on the product edit page
3. Clear any caching plugins

### Stock quantity not displaying
1. Ensure "Manage stock" is enabled in WooCommerce product settings
2. Check that "Show stock quantity" is enabled in the plugin meta box
3. Verify stock quantity is set in WooCommerce

### Tabs not working
1. Check for JavaScript conflicts in browser console
2. Ensure jQuery is loaded
3. Try switching to Accordion or Stacked mode

### File uploads not working
1. Check WordPress media library permissions
2. Verify file size limits in php.ini
3. Ensure file type is allowed in WordPress

## Support

For support, feature requests, or bug reports:
- Email: support@shinyprints.com
- Documentation: https://shinyprints.com/docs/wc-enhanced-product-info

## Changelog

### Version 1.3.0
- New: Warranty Templates — save a product's warranty setup (duration, type, policy link, document, and content) as a named template right on the product edit page, then apply it to any other product with one click. Applied values can be tweaked per product before saving. Ideal for brands that share the same warranty across many products.
- New: Shipping & Returns Policy Templates — the same create/apply/delete template workflow on the Custom Shipping Policy and Custom Returns Policy editors, for reusing per-brand shipping and returns terms across products.
- New: Accordion mode can now open the Description section automatically on page load (on by default; configurable via "Accordion: Default Open Section" in General settings).
- New: live badge previews on the Styling tab — each badge section (Free Shipping, Warranty, In Stock, Out of Stock, plus a combined listing-badge strip) shows a real-time preview that updates as you change colors, icons, fonts, shapes, and padding, before saving.
- Improved: settings page — hover "?" help tips throughout all five tabs explaining how each option works (including which options also need a per-product setting); Save button now at the top and bottom of the page.
- Removed: the non-functional "Badge Position" dropdown, "Custom Priority" field, and "Hook Priority Reference" table from the Layout tab. These options were never connected to the storefront — badge placement is (and was) controlled by the per-badge Above/Below/Next-to-Price selectors in Badge Order & Position.
- Fixed: structured data reported a wrong return window (e.g. "2026 days") for products with an expected-restock date set; the return policy schema now always uses the Return Window setting from the SEO/Schema tab.
- Fixed: corrected the Default Brand Name description (an empty value omits brand from schema; it never fell back to the site name).

### Version 1.2.0
- Security: SVG uploads are now restricted to trusted users and content-checked for scripts before being accepted
- Fixed: stock display now uses the current `woocommerce_get_stock_html` filter (the old hook was removed in WooCommerce 3.0, which caused a duplicate stock line on product pages)
- Fixed: payment method icons for Venmo, Afterpay, Klarna, Stripe, Cash, Check, Bank Transfer, Cirrus, and Worldpay (previously rendered as broken images); missing icon files are now skipped gracefully
- Added: "Enable Product Schema Output" toggle (SEO/Schema tab) so the plugin's JSON-LD can be turned off when an SEO plugin already outputs Product schema
- Added: WooCommerce HPOS (High-Performance Order Storage) compatibility declaration
- Added: uninstall cleanup — plugin options are removed on delete (product data is preserved)
- Improved: WooCommerce detection now works with multisite network activation
- Improved: hardened product save handler (post type and revision checks) and settings sanitization
- Improved: removed debug console output from the storefront script; browser scroll restoration is no longer disabled in Tabs mode
- Dev/debug utilities moved to the `dev/` folder and excluded from distribution builds

### Version 1.0.0
- Initial release
- Free shipping badge functionality
- Enhanced stock status with quantity and return dates
- Custom dimensions system
- Product specifications
- Downloads/Manuals section
- Shipping & Returns templates
- Warranty information
- Multiple display modes (Tabs, Accordion, Stacked)
- Fully responsive design
- Translation ready

## Credits

Developed by ShinyPrints
https://shinyprints.com

## License

This plugin is licensed under the GPL v2 or later.