# How to Change Role Labels

You can customize how the contributor roles are displayed on your website.

## 📍 Where to Edit

**File:** [`templates/contributor-badges.php`](templates/contributor-badges.php)

## 🔧 Current Role Labels

The role labels are defined on these lines:

### Line 38: Primary Author
```php
'role_label' => __('Written by', 'multi-author-plugin'),
```

### Line 51: Co-Authors
```php
'role_label' => __('Co-Author', 'multi-author-plugin'),
```

### Line 67: Reviewers
```php
'role_label' => __('Reviewed by', 'multi-author-plugin'),
```

### Line 83: Fact Checkers
```php
'role_label' => __('Fact-Checked by', 'multi-author-plugin'),
```

## ✏️ How to Change Them

### Example 1: Change "Fact-Checked by" to "Fact Checked by"

**Find line 83:**
```php
'role_label' => __('Fact-Checked by', 'multi-author-plugin'),
```

**Change to:**
```php
'role_label' => __('Fact Checked by', 'multi-author-plugin'),
```

### Example 2: Change "Written by" to "Author"

**Find line 38:**
```php
'role_label' => __('Written by', 'multi-author-plugin'),
```

**Change to:**
```php
'role_label' => __('Author', 'multi-author-plugin'),
```

### Example 3: Change "Reviewed by" to "Edited by"

**Find line 67:**
```php
'role_label' => __('Reviewed by', 'multi-author-plugin'),
```

**Change to:**
```php
'role_label' => __('Edited by', 'multi-author-plugin'),
```

## 📋 All Customizable Labels

You can change any of these to whatever you want:

| Current Label | Line | What It Shows |
|---------------|------|---------------|
| "Written by" | 38 | Primary author label |
| "Co-Author" | 51 | Additional authors |
| "Reviewed by" | 67 | Reviewers/Editors |
| "Fact-Checked by" | 83 | Fact checkers |

## 🔄 After Making Changes

1. Save the file
2. Clear WP Rocket cache (**Settings → WP Rocket → Clear Cache**)
3. Refresh the page (Ctrl + F5)

## 💡 Tips

- Keep labels short and clear
- Use title case for consistency
- The `__()` function is for translation support - keep it there
- Don't change the `'multi-author-plugin'` part (text domain)

## 🎨 Styling the Labels

The labels are styled with the `.map-contributor-role-label` CSS class.

To change their appearance, edit [`public/css/public-styles.css`](public/css/public-styles.css) around line 95:

```css
.map-contributor-role-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #666;
    font-weight: 600;
}
```

You can change:
- `font-size` - Make text bigger/smaller
- `text-transform` - Remove `uppercase` to use normal case
- `color` - Change the color
- `font-weight` - Make it bolder or lighter