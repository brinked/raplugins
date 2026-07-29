# Custom Sections Feature

## Overview
The Custom Sections feature allows you to create additional product information sections beyond the default ones (Specifications, Downloads, Warranty, etc.). Each custom section can be configured as either:

1. **Specification Fields** - Two-column layout with labels on the left and values on the right (similar to the existing Specifications section)
2. **Rich Text Area** - WYSIWYG editor for free-form content (similar to the Shipping & Returns section)

## How to Use

### Enabling the Feature
1. Go to **WooCommerce > Enhanced Product Info** in your WordPress admin
2. Check the **Enable Custom Sections** checkbox
3. Click **Save Settings**

### Adding Custom Sections to Products
1. Edit any product in WooCommerce
2. Scroll down to the **Enhanced Product Information** meta box
3. Find the **Custom Sections** section at the bottom
4. Click **Add Custom Section**

### Configuring a Custom Section

#### Section Name
- Enter a descriptive name for your section (e.g., "Technical Details", "Care Instructions", "Compatibility")
- This name will appear as the tab title or section heading on the frontend

#### Section Type

**Option 1: Specification Fields (Label/Value)**
- Choose this for structured data with labels and values
- Click **Add Field** to add label/value pairs
- Example uses:
  - Technical specifications
  - Compatibility information
  - Material composition
  - Certifications

**Option 2: Rich Text Area (WYSIWYG)**
- Choose this for free-form content with formatting
- Use the WYSIWYG editor to add formatted text, lists, links, etc.
- Example uses:
  - Care instructions
  - Usage guidelines
  - Safety information
  - Additional product details

### Managing Custom Sections

#### Adding Fields (for Specification Fields type)
1. Click **Add Field** within the section
2. Enter a **Label** (e.g., "Processor", "Memory", "Storage")
3. Enter a **Value** (e.g., "Intel Core i7", "16GB DDR4", "512GB SSD")
4. Repeat for additional fields

#### Removing Items
- Click **Remove** next to any field to delete it
- Click **Remove Section** to delete the entire custom section

#### Switching Section Types
- Use the dropdown to switch between "Specification Fields" and "Rich Text Area"
- Note: Switching types will hide the previous content but won't delete it until you save

## Frontend Display

### Tabs Mode (Default)
Custom sections appear as additional tabs after the standard tabs (Description, Dimensions, Specifications, etc.)

### Accordion Mode
Custom sections appear as collapsible accordion items

### Stacked Mode
Custom sections appear as regular content blocks in sequence

## Examples

### Example 1: Technical Specifications Section
**Section Name:** "Technical Specifications"
**Type:** Specification Fields

Fields:
- Processor: Intel Core i7-12700K
- RAM: 32GB DDR5
- Storage: 1TB NVMe SSD
- Graphics: NVIDIA RTX 3080
- Power Supply: 850W 80+ Gold

### Example 2: Care Instructions Section
**Section Name:** "Care Instructions"
**Type:** Rich Text Area

Content:
```
**Washing Instructions:**
- Machine wash cold with like colors
- Use mild detergent
- Do not bleach
- Tumble dry low
- Iron on low heat if needed

**Storage:**
Store in a cool, dry place away from direct sunlight.
```

### Example 3: Compatibility Section
**Section Name:** "Device Compatibility"
**Type:** Specification Fields

Fields:
- iPhone Models: iPhone 12, 13, 14, 15 (all variants)
- Android: Samsung Galaxy S21-S24, Google Pixel 6-8
- Tablets: iPad Pro 2020 and newer
- Operating Systems: iOS 14+, Android 11+

## Tips

1. **Keep section names concise** - They appear as tab titles, so shorter is better
2. **Use consistent formatting** - If using multiple custom sections, maintain a consistent style
3. **Don't duplicate existing sections** - Use custom sections for information that doesn't fit in the standard sections
4. **Order matters** - Custom sections appear in the order you create them
5. **Test on frontend** - Always preview your product page to see how sections display

## Technical Notes

- Custom sections are stored as post meta: `_wcepi_custom_sections`
- Data structure is an array of section objects
- Each section contains: name, type, and either fields array or content string
- Frontend display respects the global display mode setting (tabs/accordion/stacked)
- Custom sections appear after all standard sections (priority 45+)

## Troubleshooting

**Custom sections not appearing:**
- Verify the feature is enabled in settings
- Check that the section has a name
- For specification fields, ensure at least one field has both label and value
- For rich text, ensure content is not empty

**Sections not saving:**
- Check WordPress user permissions
- Verify no JavaScript errors in browser console
- Ensure WooCommerce is active and up to date

**Display issues:**
- Clear browser cache
- Check theme compatibility
- Verify display mode setting in plugin settings