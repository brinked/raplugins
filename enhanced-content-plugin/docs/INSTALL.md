# Quick Installation & Setup Guide

## Installation (3 Minutes)

### Step 1: Upload Plugin
1. Download and extract the plugin files
2. Upload the `multi-author-plugin` folder to `/wp-content/plugins/`
3. Go to **Plugins** in WordPress admin
4. Click **Activate** next to "Multi-Author Contributor Plugin"

### Step 2: Configure User Profiles (2 Minutes)
1. Go to **Users > All Users**
2. Edit each user who will be a contributor
3. Scroll to **Contributor Information** section
4. Fill in:
   - Short Bio (max 200 characters)
   - Job Title/Role
   - Editorial Process Link (optional)
   - Social Media URLs (optional)
5. Click **Update Profile**

### Step 3: Add Contributors to a Post (1 Minute)
1. Create or edit a post
2. Find the **Article Contributors** meta box (right side or below editor)
3. Click **+ Add Author** (or Reviewer/Fact Checker)
4. Search for a user by name
5. Click on the user to add them
6. Click **Add Selected**
7. Publish or update your post

### Step 4: Add Sources (Optional, 1 Minute)
1. In the same post, find **Article Sources & Citations** meta box
2. Click **+ Add Source**
3. Enter the source URL
4. Optionally add a label
5. Click **+ Add Source** for more citations
6. Publish or update your post

## Quick Test

Visit your published post to see:
- ✅ Contributor badges below the title
- ✅ Hover over names to see popup with bio
- ✅ Sources section at the bottom
- ✅ View page source to see Schema.org markup

## Common First-Time Setup

### Recommended Settings
1. **Add Editorial Process Page**:
   - Create a page explaining your review process
   - Add the URL to reviewer profiles

2. **Set Up User Roles**:
   - Ensure contributors have at least "Author" role
   - Editors can assign any user as contributor

3. **Test on Mobile**:
   - Visit a post on mobile device
   - Tap contributor names to see popups
   - Verify responsive design

### Best Practices
- Keep short bios under 150 characters for best display
- Add job titles to establish credibility
- Always include at least one source for fact-based articles
- Use descriptive labels for sources when possible

## Troubleshooting

**Not seeing contributor badges?**
- Make sure you added contributors in the meta box
- Check that the post is published (not draft)
- Clear your cache

**Popups not appearing?**
- Check browser console for JavaScript errors
- Ensure jQuery is loaded
- Try disabling other plugins temporarily

**Need help?**
- Check the full README.md for detailed documentation
- Review the code comments for developer guidance

## Next Steps

1. **Customize Appearance**: Add custom CSS to match your theme
2. **Set Up All Users**: Add contributor info to all user profiles
3. **Update Old Posts**: Go back and add contributors to existing content
4. **Monitor Schema**: Use Google Search Console to verify markup

## File Structure Reference

```
multi-author-plugin/
├── multi-author-plugin.php    # Main plugin file
├── includes/                   # Core classes
│   ├── class-meta-boxes.php
│   ├── class-schema-generator.php
│   ├── class-frontend-display.php
│   └── class-user-profile.php
├── admin/                      # Admin assets
│   ├── css/
│   └── js/
├── public/                     # Frontend assets
│   ├── css/
│   └── js/
├── templates/                  # Display templates
│   ├── contributor-badges.php
│   ├── contributor-popup.php
│   └── sources-list.php
├── README.md                   # Full documentation
└── INSTALL.md                  # This file
```

## Support

Need more help? See README.md for:
- Detailed feature documentation
- Customization examples
- Developer functions
- FAQ section
- Troubleshooting guide

---

**Setup Complete!** 🎉

Your site now has professional multi-author attribution with Schema.org markup.