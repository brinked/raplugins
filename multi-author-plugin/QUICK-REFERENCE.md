# Quick Reference Guide - Multi-Author Plugin

## 🎯 Where to Edit What

### **User Profile Settings**
**Location:** WordPress Admin → Users → [Select User] → Edit Profile

| What You Want | Field to Edit | Section |
|---------------|---------------|---------|
| **Mini bio for hover** | Short Bio (200 chars) | Contributor Information |
| **Full bio page** | Biographical Info | About the User |
| **Job title under name** | Job Title / Role | Contributor Information |
| **Social media links** | Twitter, LinkedIn, Facebook, Instagram, YouTube | Social Media Profiles |
| **Contact email** | Public Contact Email | Contributor Information |
| **Personal website** | Personal Website | Contributor Information |
| **Editorial process link** | Editorial Process Link | Contributor Information |

---

## 📍 What Displays Where

### **Main Article (Inline Display):**
```
┌─────────────────────────────────────────────────────────┐
│ [Photo] Written by        [Photo] Reviewed by           │
│        JOHN DOE                  JANE SMITH             │
│        Senior Editor             Medical Reviewer       │
└─────────────────────────────────────────────────────────┘
```

### **Hover Popup (When Mouse Over Name):**
```
┌──────────────────────────────────┐
│ [Large Photo]  JOHN DOE          │
│                Senior Editor      │
├──────────────────────────────────┤
│ Mini bio text appears here...    │
├──────────────────────────────────┤
│ 📧 Email  🌐 Website             │
├──────────────────────────────────┤
│ [Twitter] [LinkedIn] [Facebook]  │ ← Social icons
├──────────────────────────────────┤
│ [View Profile] [Editorial Process]│
└──────────────────────────────────┘
```

---

## ✅ Quick Checklist for New Contributors

- [ ] Set Display Name
- [ ] Add Short Bio (for hover popup)
- [ ] Add Full Biographical Info
- [ ] Set Job Title / Role
- [ ] Add Social Media profiles (optional)
- [ ] Add Public Contact Email (optional)
- [ ] Set up Gravatar for photo (gravatar.com)

---

## 🎨 Display Rules

### **What Shows:**
✅ Photo thumbnail (from Gravatar)  
✅ Role label (Written by, Reviewed by, etc.)  
✅ Name (clickable)  
✅ Job title (if filled in)  

### **What's Optional:**
- Social media icons (only if URLs provided)
- Contact email (only if provided)
- Personal website (only if provided)
- Editorial process link (only if provided)

### **To Hide Something:**
Simply leave the field **blank** in the user profile.

---

## 🔧 Common Tasks

### **Add a New Contributor to a Post:**
1. Edit the post
2. Scroll to "Article Contributors" meta box
3. Click "+ Add Co-Author" (or Reviewer/Fact Checker)
4. Search for user and select
5. Update post

### **Change Contributor Order:**
1. Edit the post
2. In "Article Contributors" meta box
3. Drag contributors using the ⋮⋮ handle
4. Update post

### **Update Contributor Info:**
1. Go to Users → All Users
2. Click on user name
3. Scroll to "Contributor Information"
4. Update fields
5. Click "Update Profile"

### **Set Up Gravatar Photo:**
1. Go to https://gravatar.com
2. Sign up with the same email as WordPress
3. Upload photo
4. Wait 5-10 minutes for cache to clear

---

## 📱 Mobile Display

- **Desktop:** All contributors in a row
- **Tablet:** Smaller photos, still in a row
- **Mobile:** Stacks vertically for easy reading

---

## 🐛 Quick Troubleshooting

| Problem | Solution |
|---------|----------|
| Photo not showing | Set up Gravatar at gravatar.com |
| Job title missing | Fill in "Job Title / Role" field |
| Social icons not appearing | Add full URLs to social media fields |
| Hover popup not working | Clear browser cache, check JavaScript enabled |
| Changes not showing | Clear WordPress cache and browser cache |

---

## 📞 Need Help?

1. Check [`INLINE-DISPLAY-UPDATE.md`](INLINE-DISPLAY-UPDATE.md) for detailed documentation
2. Verify all user profile fields are correctly filled
3. Clear all caches (WordPress + browser)
4. Test in different browser

---

**Quick Tip:** The "Short Bio" field is limited to 200 characters - make it count! Focus on credentials and expertise.