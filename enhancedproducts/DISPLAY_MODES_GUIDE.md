# Display Modes Guide
## How to Switch Between Tabs, Accordion, and Stacked Views

This guide explains how to change the display mode for your product information.

---

## Quick Answer

**To change the display mode:**

1. Go to **WooCommerce → Enhanced Product Info** in your WordPress admin
2. Scroll down to **Display Settings** section
3. Find the **Display Mode** dropdown
4. Select your preferred mode:
   - **Tabs** (default) - Traditional tabbed interface
   - **Accordion** - Collapsible sections
   - **Stacked** - All content visible without tabs
5. Click **Save Settings** at the bottom

---

## Display Mode Options

### 1. Tabs Mode (Default)
**Best for**: Most websites, desktop users

**How it looks**:
- Traditional horizontal tabs at the top
- Click a tab to switch between sections
- Only one section visible at a time
- Clean, organized appearance

**When to use**:
- Standard e-commerce sites
- Desktop-focused audience
- When you have many information sections

---

### 2. Accordion Mode
**Best for**: Mobile-first sites, long product pages

**How it looks**:
- Vertical list of collapsible headers
- Click a header to expand/collapse content
- Only one section open at a time
- Mobile-friendly design

**When to use**:
- Mobile-heavy traffic
- Touch-screen devices
- When you want to save vertical space
- Modern, app-like interface

---

### 3. Stacked Mode (No Tabs)
**Best for**: Simple products, print-friendly pages

**How it looks**:
- All information displayed in a single column
- No tabs or accordions
- Everything visible at once
- Scroll to see all content

**When to use**:
- Products with limited information
- When you want everything visible
- Print-friendly layouts
- Simple, straightforward presentation

---

## Step-by-Step Instructions

### Accessing Settings

1. **Log in** to your WordPress admin dashboard
2. In the left sidebar, hover over **WooCommerce**
3. Click on **Enhanced Product Info**
4. You'll see the settings page

### Changing Display Mode

1. On the settings page, scroll down to find **Display Settings**
2. Look for the **Display Mode** dropdown menu
3. Click the dropdown to see options:
   ```
   ☐ Tabs
   ☐ Accordion  
   ☐ Stacked (No Tabs)
   ```
4. Click your preferred option
5. Scroll to the bottom of the page
6. Click the blue **Save Settings** button

### Verifying the Change

1. Go to any product page on your site
2. Scroll down to the product information section
3. You should see the new display mode in action
4. If you don't see changes:
   - Clear your browser cache (Ctrl+F5 or Cmd+Shift+R)
   - Clear any caching plugins
   - Wait a few minutes for CDN cache to clear

---

## Comparison Table

| Feature | Tabs | Accordion | Stacked |
|---------|------|-----------|---------|
| Mobile Friendly | ⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐ |
| Desktop Friendly | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐ |
| Space Efficient | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐ |
| Print Friendly | ⭐⭐ | ⭐⭐ | ⭐⭐⭐⭐⭐ |
| SEO Friendly | ⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ |
| User Interaction | Click tabs | Click headers | Just scroll |
| Content Visibility | One at a time | One at a time | All visible |

---

## Troubleshooting

### "I changed the setting but don't see any difference"

**Solution 1: Clear Cache**
```
1. Clear browser cache (Ctrl+F5 or Cmd+Shift+R)
2. If using a caching plugin:
   - WP Super Cache: Settings → Delete Cache
   - W3 Total Cache: Performance → Purge All Caches
   - WP Rocket: Clear Cache button in admin bar
```

**Solution 2: Check if you saved**
```
1. Go back to WooCommerce → Enhanced Product Info
2. Check if your selection is still there
3. If not, select it again and click Save Settings
```

**Solution 3: Disable other plugins temporarily**
```
1. Go to Plugins → Installed Plugins
2. Deactivate other plugins one by one
3. Check if display mode works
4. Reactivate plugins once you find the conflict
```

### "The accordion/tabs aren't working (not clickable)"

**Possible causes**:
- JavaScript conflict with another plugin
- Theme compatibility issue
- Browser console errors

**Solution**:
```
1. Open browser Developer Tools (F12)
2. Go to Console tab
3. Look for red error messages
4. Share these errors with support
```

### "I want different modes for different products"

**Current limitation**: The display mode is global (applies to all products).

**Workaround**: You can use custom CSS to hide/show sections differently per product category.

---

## Tips & Best Practices

### For E-commerce Stores
- **Use Tabs** for desktop-heavy traffic
- **Use Accordion** for mobile-heavy traffic
- **Use Stacked** for simple products with few details

### For Mobile Optimization
- Accordion mode works best on mobile devices
- Test on actual mobile devices, not just browser resize
- Consider your mobile traffic percentage

### For SEO
- Stacked mode is best for SEO (all content visible to crawlers)
- Tabs and Accordion are also SEO-friendly (content is in HTML)
- All modes are indexed by search engines

### For User Experience
- Match your theme's style
- Consider your audience's tech-savviness
- Test with real users if possible
- Monitor bounce rates after changing

---

## Advanced: Testing Different Modes

Want to see how each mode looks before committing?

1. **Take screenshots** of a product page in current mode
2. **Change to new mode** and save
3. **View product page** and take new screenshots
4. **Compare** side by side
5. **Choose** the one that looks best
6. **Ask customers** for feedback if unsure

---

## Need Help?

If you're still having trouble:

1. **Check documentation**: README.md file
2. **Contact support**: support@shinyprints.com
3. **Include**:
   - Current display mode setting
   - What you expected to see
   - What you actually see
   - Screenshots if possible
   - Browser and device info

---

## Quick Reference Card

```
┌─────────────────────────────────────────┐
│  HOW TO CHANGE DISPLAY MODE             │
├─────────────────────────────────────────┤
│  1. WooCommerce → Enhanced Product Info │
│  2. Find "Display Mode" dropdown        │
│  3. Select: Tabs / Accordion / Stacked  │
│  4. Click "Save Settings"               │
│  5. Clear cache                         │
│  6. View product page                   │
└─────────────────────────────────────────┘
```

---

**Last Updated**: 2024  
**Plugin Version**: 1.0.0  
**Applies to**: All WooCommerce products