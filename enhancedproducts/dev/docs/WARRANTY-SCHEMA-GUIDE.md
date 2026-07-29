# Warranty Schema Markup Guide

## Overview
The Enhanced Product Info plugin now automatically generates Schema.org structured data for product warranties. This helps search engines understand your warranty information and can improve your product listings in search results.

## Features Added

### 1. Warranty Duration Fields
- **Number Input**: Enter the warranty period (e.g., 1, 2, 5, 30)
- **Unit Dropdown**: Select the time unit:
  - **Years** (default) - For warranties like "2 Years"
  - **Months** - For warranties like "18 Months"
  - **Days** - For warranties like "90 Days"
- **Lifetime Checkbox**: For lifetime warranties (overrides duration fields)

### 2. Warranty Type Field
Select the type of warranty for proper schema markup:

| Warranty Type | Description | Schema Value |
|--------------|-------------|--------------|
| **Full Warranty (Parts and Labor)** | Complete coverage | `FullWarranty` |
| **Limited Warranty** | Partial coverage with conditions | `LimitedWarranty` |
| **Parts Warranty** | Parts coverage only | `PartsWarranty` |
| **Labor Warranty** | Labor coverage only | `LaborWarranty` |
| **Lifetime Warranty** | Coverage for product lifetime | `LifetimeWarranty` |
| **Replacement Warranty** | Replacement-only coverage | `ReplacementWarranty` |

### 3. Automatic Schema Output
The plugin automatically generates Schema.org JSON-LD markup in the `<head>` section of product pages when warranty information is present.

## Schema Structure

The generated schema follows the [Schema.org WarrantyPromise](https://schema.org/WarrantyPromise) specification:

```json
{
  "@context": "https://schema.org",
  "@type": "Product",
  "name": "Your Product Name",
  "hasWarrantyPromise": {
    "@type": "WarrantyPromise",
    "durationOfWarranty": {
      "@type": "QuantitativeValue",
      "value": "P2Y"
    },
    "warrantyScope": "FullWarranty"
  }
}
```

## How to Use

### Setting Up Warranty Information

1. **Edit a Product** in WordPress admin
2. Scroll to **"Enhanced Product Information"** meta box
3. Find the **"Warranty Information"** section

### Example 1: 2-Year Full Warranty
```
☐ Lifetime Warranty
Warranty Period: 2 [Years]
Warranty Type: Full Warranty (Parts and Labor)
```

**Generated Schema Duration**: `P2Y`
**Generated Schema Scope**: `FullWarranty`

### Example 2: 90-Day Limited Warranty
```
☐ Lifetime Warranty
Warranty Period: 90 [Days]
Warranty Type: Limited Warranty
```

**Generated Schema Duration**: `P90D`
**Generated Schema Scope**: `LimitedWarranty`

### Example 3: 18-Month Parts Warranty
```
☐ Lifetime Warranty
Warranty Period: 18 [Months]
Warranty Type: Parts Warranty
```

**Generated Schema Duration**: `P18M`
**Generated Schema Scope**: `PartsWarranty`

### Example 4: Lifetime Warranty
```
☑ Lifetime Warranty
Warranty Period: [disabled]
Warranty Type: Lifetime Warranty
```

**Generated Schema Duration**: `P100Y` (represents lifetime)
**Generated Schema Scope**: `LifetimeWarranty`

## ISO 8601 Duration Format

The plugin converts your warranty period into ISO 8601 duration format for schema markup:

| Duration | Format | Example |
|----------|--------|---------|
| Years | `PnY` | `P2Y` = 2 years |
| Months | `PnM` | `P18M` = 18 months |
| Days | `PnD` | `P90D` = 90 days |
| Lifetime | `P100Y` | Represented as 100 years |

## Viewing the Schema Output

To verify the schema markup is working:

### Method 1: View Page Source
1. Visit a product page with warranty information
2. Right-click > **View Page Source**
3. Search for `"WarrantyPromise"`
4. You should see the JSON-LD schema block

Example output:
```html
<!-- WC Enhanced Product Info - Warranty Schema -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Product",
  "name": "Blaze 30-inch Built-in Gas Griddle",
  "hasWarrantyPromise": {
    "@type": "WarrantyPromise",
    "durationOfWarranty": {
      "@type": "QuantitativeValue",
      "value": "P1Y"
    },
    "warrantyScope": "FullWarranty"
  }
}
</script>
<!-- /WC Enhanced Product Info - Warranty Schema -->
```

### Method 2: Google's Rich Results Test
1. Visit [Google Rich Results Test](https://search.google.com/test/rich-results)
2. Enter your product page URL
3. Click "Test URL"
4. Look for `Product` with `hasWarrantyPromise` property

### Method 3: Schema Markup Validator
1. Visit [Schema.org Validator](https://validator.schema.org/)
2. Enter your product page URL
3. Verify the WarrantyPromise is detected without errors

## Benefits of Warranty Schema

### For Search Engines
- **Better Understanding**: Search engines can accurately parse warranty information
- **Rich Snippets**: Potential for enhanced search result listings
- **Product Knowledge Graph**: Contributes to Google's product data

### For Customers
- **Trust Signals**: Warranty info may appear in search results
- **Quick Reference**: Easy to find warranty details
- **Comparison Shopping**: Helps customers compare warranties across products

## Best Practices

### 1. Be Accurate
- Only add warranty information if you actually offer a warranty
- Match the schema warranty type to your actual warranty terms
- Keep the duration accurate and up-to-date

### 2. Combine with Display
- The schema complements the visible warranty tab/section
- Always provide detailed warranty terms in the warranty content area
- Include links to full warranty documents

### 3. Consistency
- Use consistent warranty types across similar products
- Standardize warranty periods when possible (e.g., all appliances = 1 year)
- Update schema when warranty terms change

## Troubleshooting

### Schema Not Appearing

**Check 1: Warranty Data Exists**
- Verify warranty period is greater than 0 OR lifetime is checked
- Ensure the product is published (not draft)

**Check 2: Product Page Only**
- Schema only outputs on single product pages
- Won't appear on shop pages or archives

**Check 3: Cache Issues**
- Clear WooCommerce cache
- Clear page cache (WP Rocket, etc.)
- Clear browser cache

### Schema Validation Errors

**Error: Invalid Duration Format**
- Plugin automatically formats duration correctly
- If seeing this error, it may be from another plugin

**Error: Missing Required Properties**
- Ensure product has a name/title
- Check that warranty type is selected

### Schema Not Recognized by Google

**Wait Period**
- Google may take several days to weeks to recognize new schema
- Regular crawling is required

**Submit Sitemap**
- Ensure product pages are in your XML sitemap
- Submit sitemap to Google Search Console

**Check Robots.txt**
- Verify product pages aren't blocked from crawling

## Technical Details

### Schema Output Location
- Output in `<head>` section via `wp_head` hook
- Priority: 10 (after most other head elements)
- Only outputs on single product pages (`is_product()`)

### Data Source
- Product meta fields: `_wcepi_warranty_*`
- Dynamic product name from WooCommerce
- No caching of schema (always fresh)

### Compatibility
- Works with all WooCommerce themes
- Compatible with WooCommerce product types
- No conflicts with other schema plugins (outputs separate JSON-LD block)

## Advanced Customization

### Filter: Modify Schema Output
```php
// Customize warranty schema before output
add_filter('wcepi_warranty_schema', function($schema, $product) {
    // Add additional properties
    $schema['hasWarrantyPromise']['description'] = 'Custom warranty description';
    
    // Add brand information
    $schema['brand'] = array(
        '@type' => 'Brand',
        'name' => 'Your Brand'
    );
    
    return $schema;
}, 10, 2);
```

### Action: Before Schema Output
```php
// Run custom code before schema is output
add_action('wcepi_before_warranty_schema', function($product) {
    // Log schema generation
    error_log('Warranty schema generated for: ' . $product->get_name());
});
```

## FAQ

**Q: Does this affect my existing warranty display?**
A: No, this only adds hidden structured data. Your warranty tab/section display remains unchanged.

**Q: Will this improve my SEO rankings?**
A: Schema markup helps search engines understand your content but isn't a direct ranking factor. It may lead to rich snippets which can improve click-through rates.

**Q: Can I use this without showing warranty info on the frontend?**
A: Yes, schema can be present even if you hide the warranty tab. However, best practice is to show the information.

**Q: Does it work with variable products?**
A: Yes, warranty information is set at the product level and works with all WooCommerce product types.

**Q: Can I have different warranties per variation?**
A: Currently, warranty is set at the parent product level. Variation-specific warranties would require custom development.

**Q: What if I don't select a warranty type?**
A: The plugin defaults to "LimitedWarranty" if no type is specified and warranty duration exists.

## Updates and Support

### Version History
- **v1.2.0**: Added warranty schema markup feature
  - Duration field with days/months/years options
  - Warranty type dropdown
  - Automatic JSON-LD schema generation
  - ISO 8601 duration formatting

### Testing Checklist
- [ ] Add warranty info to a test product
- [ ] View product page source - verify schema present
- [ ] Test with Google Rich Results Test
- [ ] Validate with Schema.org validator
- [ ] Check different warranty types
- [ ] Test lifetime warranty checkbox
- [ ] Verify schema updates when warranty changes

### Additional Resources
- [Schema.org WarrantyPromise Spec](https://schema.org/WarrantyPromise)
- [Google Merchant Center - Product Data Spec](https://support.google.com/merchants/answer/6324461)
- [ISO 8601 Duration Format](https://en.wikipedia.org/wiki/ISO_8601#Durations)