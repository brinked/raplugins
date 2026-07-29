# Quick Reference Guide
## WooCommerce Enhanced Product Info

A quick reference for common tasks and features.

---

## 📍 Where to Find Things

| What | Where |
|------|-------|
| Plugin Settings | WooCommerce → Enhanced Product Info |
| Product Settings | Product Edit Page → Enhanced Product Information meta box |
| Frontend Display | Individual product pages |

---

## ⚙️ Global Settings

### Access Settings
```
WordPress Admin → WooCommerce → Enhanced Product Info
```

### Quick Settings Reference

| Setting | Options | Default |
|---------|---------|---------|
| Free Shipping Badge | Enable/Disable | Enabled |
| Stock Status | Enable/Disable | Enabled |
| Custom Dimensions | Enable/Disable | Enabled |
| Specifications | Enable/Disable | Enabled |
| Downloads | Enable/Disable | Enabled |
| Shipping & Returns | Enable/Disable | Enabled |
| Warranty | Enable/Disable | Enabled |
| Display Mode | Tabs/Accordion/Stacked | Tabs |

---

## 🛍️ Product Configuration

### Free Shipping Badge
```
☑️ Enable free shipping badge for this product
```
- Shows green badge next to price
- Customizable text in global settings

### Stock Status
```
☑️ Show stock quantity
📅 Expected Return Date: [Select Date]
```
- Green = In Stock
- Red = Out of Stock
- Shows quantity if enabled
- Shows return date if out of stock

### Custom Dimensions
```
Click "Add Dimension"
Label: [e.g., Diameter]
Value: [e.g., 10 inches]
```
- Add unlimited custom dimensions
- Displays with default WooCommerce dimensions

### Specifications
```
Click "Add Specification"
Label: [e.g., Material]
Value: [e.g., Stainless Steel]
```
- Add unlimited specifications
- Perfect for technical details

### Downloads/Manuals
```
Click "Add Download"
Title: [e.g., User Manual]
URL: [Paste URL or click Upload]
```
- Upload PDFs, DOCs, etc.
- Or link to external files

### Warranty
```
Warranty Period: [e.g., 2] years
Warranty URL: [Link to policy]
```
- Supports decimals (0.5 = 6 months)
- Link to manufacturer's page

### Shipping & Returns
```
[Rich text editor]
Leave empty to use global policy
```
- Override global policy per product
- Supports HTML formatting

---

## 🎨 Display Modes

### Tabs Mode (Default)
- Traditional tabbed interface
- Click tabs to switch sections
- Best for: Most use cases

### Accordion Mode
- Collapsible sections
- Click to expand/collapse
- Best for: Mobile-first sites

### Stacked Mode
- All content visible
- No tabs or accordions
- Best for: Simple products with limited info

---

## 🔧 Common Tasks

### Add Free Shipping to Product
1. Edit product
2. Scroll to "Enhanced Product Information"
3. Check "Enable free shipping badge"
4. Update product

### Add Product Specifications
1. Edit product
2. Find "Product Specifications" section
3. Click "Add Specification"
4. Enter Label and Value
5. Repeat as needed
6. Update product

### Upload Product Manual
1. Edit product
2. Find "Downloads/Manuals" section
3. Click "Add Download"
4. Enter title (e.g., "User Manual")
5. Click "Upload" button
6. Select PDF from media library
7. Update product

### Set Expected Return Date
1. Edit product
2. Find "Stock Status" section
3. Click date field
4. Select expected date
5. Update product

### Change Display Mode
1. Go to WooCommerce → Enhanced Product Info
2. Find "Display Mode" dropdown
3. Select: Tabs, Accordion, or Stacked
4. Save Settings

---

## 📊 Data Structure

### Custom Dimensions Array
```php
[
    ['label' => 'Diameter', 'value' => '10 inches'],
    ['label' => 'Weight', 'value' => '5 lbs']
]
```

### Specifications Array
```php
[
    ['label' => 'Material', 'value' => 'Stainless Steel'],
    ['label' => 'Color', 'value' => 'Silver']
]
```

### Downloads Array
```php
[
    ['title' => 'User Manual', 'url' => 'https://...'],
    ['title' => 'Quick Start', 'url' => 'https://...']
]
```

---

## 🎯 Best Practices

### Free Shipping
- ✅ Use for products that qualify
- ✅ Keep badge text short
- ❌ Don't enable if shipping costs apply

### Stock Status
- ✅ Enable quantity display for transparency
- ✅ Set realistic return dates
- ✅ Update dates as inventory changes
- ❌ Don't forget to update return dates

### Dimensions
- ✅ Use consistent units
- ✅ Include all relevant measurements
- ✅ Be specific (e.g., "Seat Height" not just "Height")
- ❌ Don't duplicate default dimensions

### Specifications
- ✅ Group related specs together
- ✅ Use clear, descriptive labels
- ✅ Keep values concise
- ❌ Don't include marketing copy

### Downloads
- ✅ Use descriptive titles
- ✅ Keep file sizes reasonable
- ✅ Use PDFs when possible
- ❌ Don't link to broken URLs

### Warranty
- ✅ Be accurate with warranty period
- ✅ Link to official policy
- ✅ Update if warranty changes
- ❌ Don't make false claims

---

## 🚨 Troubleshooting Quick Fixes

### Badge Not Showing
```
1. Check feature is enabled in settings
2. Verify checkbox is checked on product
3. Clear cache
```

### Stock Quantity Not Displaying
```
1. Enable "Manage stock" in WooCommerce
2. Check "Show stock quantity" in plugin
3. Set stock quantity in WooCommerce
```

### Tabs Not Working
```
1. Check browser console for errors
2. Ensure jQuery is loaded
3. Try different display mode
4. Deactivate other plugins temporarily
```

### Upload Button Not Working
```
1. Check file permissions
2. Verify media library works
3. Check file size limits
4. Try different file type
```

### Styles Not Loading
```
1. Clear all caches
2. Hard refresh browser (Ctrl+F5)
3. Check file permissions
4. Verify files exist in /assets/
```

---

## 💡 Pro Tips

### Bulk Editing
- Use specifications for common attributes across products
- Create templates for similar products
- Copy/paste specification arrays between products

### SEO Benefits
- Rich product information improves SEO
- Specifications help with long-tail keywords
- Downloads provide additional indexed content

### Customer Experience
- More information = fewer support questions
- Clear specifications reduce returns
- Downloads build trust and confidence

### Performance
- Plugin only loads on product pages
- No impact on other pages
- Compatible with caching plugins

---

## 📱 Mobile Optimization

### Recommended Settings for Mobile
```
Display Mode: Accordion
Stock Quantity: Enabled
Free Shipping: Enabled
```

### Mobile-Specific Tips
- Keep specification labels short
- Use accordion mode for better mobile UX
- Test on actual devices
- Ensure downloads are mobile-friendly

---

## 🔗 Useful Links

- **Main Documentation**: README.md
- **Installation Guide**: INSTALLATION.md
- **Full Summary**: PLUGIN_SUMMARY.md
- **Support**: support@shinyprints.com

---

## ⌨️ Keyboard Shortcuts (Admin)

| Action | Shortcut |
|--------|----------|
| Save Product | Ctrl/Cmd + S |
| Add New Row | Tab (on last field) |
| Remove Row | Click Remove button |

---

## 📋 Checklist: New Product Setup

- [ ] Enable free shipping (if applicable)
- [ ] Set stock quantity display preference
- [ ] Add expected return date (if out of stock)
- [ ] Add custom dimensions (if needed)
- [ ] Add product specifications
- [ ] Upload manuals/downloads
- [ ] Set warranty information
- [ ] Review shipping policy (use global or custom)
- [ ] Preview on frontend
- [ ] Test on mobile device

---

## 🎓 Training New Staff

### 5-Minute Training
1. Show settings page location
2. Demonstrate adding specifications
3. Show how to upload files
4. Preview on frontend
5. Answer questions

### Key Points to Emphasize
- Always update product after changes
- Use consistent formatting
- Test on frontend after saving
- Keep information accurate and current

---

## 📞 Getting Help

### Before Contacting Support
1. Check this quick reference
2. Review INSTALLATION.md
3. Check browser console for errors
4. Try with default theme
5. Deactivate other plugins

### What to Include in Support Request
- WordPress version
- WooCommerce version
- PHP version
- Theme name
- Description of issue
- Screenshots if applicable
- Browser console errors

---

**Last Updated**: 2024
**Plugin Version**: 1.0.0
**Compatibility**: WordPress 5.8+, WooCommerce 5.0+