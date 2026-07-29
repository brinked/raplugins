# Installation & Update Instructions

## If You're Updating from a Previous Version

### Step 1: Deactivate the Plugin
1. Go to **WordPress Admin > Plugins**
2. Find "Multi-Author Contributor Plugin"
3. Click **Deactivate**

### Step 2: Replace the Files
1. Delete the old `multi-author-plugin` folder from `/wp-content/plugins/`
2. Upload the new `multi-author-plugin` folder

### Step 3: Reactivate the Plugin
1. Go to **WordPress Admin > Plugins**
2. Find "Multi-Author Contributor Plugin"
3. Click **Activate**

### Step 4: Clear All Caches

#### A. WordPress Admin Cache
If using a caching plugin (WP Super Cache, W3 Total Cache, etc.):
1. Go to your caching plugin settings
2. Click "**Purge All Cache**" or "**Clear Cache**"

#### B. Browser Cache
Press `Ctrl + Shift + R` (Windows/Linux) or `Cmd + Shift + R` (Mac) to hard refresh

#### C. Server Cache (if applicable)
If using server-level caching (Cloudflare, etc.), purge that too

### Step 5: Verify Changes

1. **Edit a Post**
   - Go to any post
   - Look for "**Article Contributors**" meta box
   - Try adding:
     - Authors
     - Reviewers  
     - Fact Checkers (this should now work!)

2. **Check User Profile**
   - Go to **Users > Your Profile**
   - Scroll down to "**Contributor Information**"
   - You should see NEW fields:
     - Public Contact Email
     - Personal Website
     - Instagram
     - YouTube

3. **View Frontend**
   - Visit a post with contributors
   - You should see ONE unified line like:
     ```
     By [Name] | Reviewed by [Name] | Published [Date]
     ```
   - NO duplicate author at top
   - Hover over names to see popup with social icons

## Troubleshooting

### Problem: Still seeing old display
**Solution:**
```bash
# Clear browser cache completely:
Chrome: Ctrl+Shift+Delete > Clear browsing data > Cached images and files
Firefox: Ctrl+Shift+Delete > Clear cookies and site data
```

### Problem: Can't add fact checkers in admin
**Solution:**
1. Check browser console (F12 > Console tab)
2. Look for JavaScript errors
3. If you see errors, disable other plugins temporarily
4. Clear browser cache and try again

### Problem: Styles not applying
**Solution:**
1. Hard refresh: `Ctrl + Shift + R`
2. Check if CSS file loaded: View page source, search for "public-styles.css"
3. If missing, reactivate the plugin

### Problem: PHP errors on activation
**Solution:**
1. Check PHP version (must be 7.0+)
2. Check error logs at: `/wp-content/debug.log`
3. Share errors for troubleshooting

## Fresh Installation (New Sites)

1. Upload `multi-author-plugin` folder to `/wp-content/plugins/`
2. Go to **Plugins** in WordPress admin
3. Click **Activate**
4. Configure user profiles with contributor information
5. Add contributors to posts

## Quick Test After Installation

### 1. Test Admin Interface
```
1. Edit any post
2. Find "Article Contributors" box
3. Click "+ Add Author" 
4. Search for a user
5. Click to add them
6. Click "+ Add Fact Checker"
7. Add another user
8. Save post
```

### 2. Test Frontend Display
```
1. Visit the post you edited
2. Look below the title
3. You should see: "By [Author] | Fact-checked by [Name] | Published [Date]"
4. Hover over contributor names
5. Popup should appear with bio and social icons
```

### 3. Test Schema Markup
```
1. View page source (Ctrl+U)
2. Search for: "application/ld+json"
3. Look for:
   - "author": [{"@type": "Person"...
   - "reviewedBy": [{"@type": "Person"...
   - "email": if you set public contact email
   - "sameAs": array with social media URLs
```

## Still Having Issues?

If fact checkers still don't work:

### Check JavaScript Console
1. Press `F12` to open developer tools
2. Click "Console" tab
3. Look for errors in red
4. Common issues:
   - jQuery not loaded
   - Conflicting plugins
   - Theme JavaScript errors

### Check File Permissions
```bash
# Files should be readable:
chmod 644 multi-author-plugin/*.php
chmod 644 multi-author-plugin/includes/*.php
chmod 644 multi-author-plugin/admin/css/*.css
chmod 644 multi-author-plugin/admin/js/*.js
chmod 644 multi-author-plugin/public/css/*.css
chmod 644 multi-author-plugin/public/js/*.js
```

### Verify File Structure
Make sure you have all these files:
```
multi-author-plugin/
├── multi-author-plugin.php
├── includes/
│   ├── class-meta-boxes.php
│   ├── class-schema-generator.php
│   ├── class-frontend-display.php
│   └── class-user-profile.php
├── admin/
│   ├── css/admin-styles.css
│   └── js/admin-scripts.js
├── public/
│   ├── css/public-styles.css
│   └── js/hover-popup.js
└── templates/
    ├── contributor-badges.php
    ├── contributor-popup.php
    └── sources-list.php
```

## Force Refresh Everything

If nothing else works, try this nuclear option:

### 1. In WordPress Admin
```sql
-- Run this in phpMyAdmin if needed:
DELETE FROM wp_options WHERE option_name LIKE '%transient%';
```

### 2. Clear Object Cache
```bash
# If using Redis/Memcached:
redis-cli FLUSHALL
# or
echo 'flush_all' | nc localhost 11211
```

### 3. Regenerate Files
1. Deactivate plugin
2. Delete plugin folder completely
3. Re-upload fresh copy
4. Activate again

## Expected Behavior After Update

### ✅ Admin (Post Editor)
- Meta box with 3 sections: Authors, Reviewers, Fact Checkers
- All 3 "Add" buttons work
- Drag to reorder contributors
- X button removes contributors

### ✅ Admin (User Profile)
- New "Contributor Information" section
- Fields: Short Bio, Job Title, Editorial Link
- Fields: Email, Website, Twitter, LinkedIn, Facebook, Instagram, YouTube

### ✅ Frontend (Post Display)
- Single line with all info: `By [Name] | Reviewed by [Name] | Fact-checked by [Name] | Published [Date]`
- NO duplicate "By Author" at top
- NO separate "About the Author" box conflicts

### ✅ Frontend (Hover Popup)
- Profile photo
- Name and job title
- Short bio
- Email and website icons (if set)
- Social media icon row (if set)
- "View Profile" and "Editorial Process" links

### ✅ Schema Markup
- JSON-LD in page source
- Author, reviewer, contributor schemas
- Email, website, social URLs included

---

**Need Help?**
If you're still having issues after following these steps, there may be a conflict with your theme or another plugin. Try:
1. Switch to a default WordPress theme (Twenty Twenty-Four) temporarily
2. Disable all other plugins
3. Test if it works
4. Re-enable plugins one by one to find the conflict