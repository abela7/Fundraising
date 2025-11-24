# Error Handling & Debugging Guide

## The "Frozen Page" Problem

### Symptoms
- Page loads but buttons don't work (no dropdowns, modals, accordions)
- Page looks incomplete or broken
- JavaScript functionality is completely dead
- Usually happens when there's a database error

### Root Cause
When PHP encounters a fatal error (like a database connection failure or missing column), it:
1. **Stops rendering mid-page** → Incomplete HTML
2. **Never loads Bootstrap JavaScript** → No button functionality
3. **Never closes `</body>` and `</html>` tags** → Broken DOM

**Result:** A "frozen" page with no errors visible to help you debug.

---

## ✅ Solution Implemented

We've added a **global error handler** that catches all errors and displays a beautiful, functional error page instead of a frozen page.

### Files Modified
- `shared/error_handler.php` - Global error handling system
- `config/env.php` - Automatically loads error handler on startup

### What It Does
1. **Catches all PHP errors and exceptions** (including database errors)
2. **Shows a user-friendly error page** with:
   - Clear error description
   - Suggested solutions
   - Working buttons (Go Back, Dashboard, Database Check)
   - Stack trace (in local environment only)
3. **Handles AJAX requests** gracefully (returns JSON errors instead of HTML)
4. **Logs errors** to PHP error log for debugging

---

## 🛠️ Diagnostic Tools

### 1. Database Health Check (Quick)
**URL:** `admin/tools/database-health-check.php`

- ✅ Fast 5-second check
- ✅ Tests database connection
- ✅ Verifies required tables exist
- ✅ Checks critical columns in donors table
- ✅ Shows server info

**Use when:** You need a quick diagnosis of what's wrong.

---

### 2. Database Readiness Check (Comprehensive)
**URL:** `admin/call-center/check-database.php`

- ✅ Checks ALL call center tables
- ✅ Verifies ALL required columns
- ✅ Generates SQL fix scripts automatically
- ✅ Shows detailed schema comparison

**Use when:** You need to fix missing tables/columns.

---

## 🔧 How to Debug Errors

### Step 1: Enable Error Display (Temporary)
Already enabled in `config/env.php` for local environment. Errors will show automatically.

### Step 2: Check the Error Page
When a page freezes or breaks, the new error handler will show:
- **Error type** (Database, Schema, Application)
- **Exact error message** (in local environment)
- **Suggested solutions** based on error type
- **Quick action buttons** to fix the issue

### Step 3: Run Diagnostic Tools
1. Go to `admin/tools/database-health-check.php`
2. Check which component is failing
3. Follow the suggested fix

### Step 4: Fix Schema Issues
If the error is "Unknown column" or "Table doesn't exist":
1. Go to `admin/call-center/check-database.php`
2. Copy the generated SQL
3. Run it in phpMyAdmin
4. Refresh the broken page

---

## 📋 Common Errors & Solutions

| Error | Cause | Solution |
|-------|-------|----------|
| "Unknown column 'donor_type'" | Missing column in donors table | Run `admin/donor-management/add_donor_type.sql` |
| "Unknown column 'agent_id'" | Missing column in donors table | Run `admin/call-center/fix_assign_donors_schema.sql` |
| "Table 'donors' doesn't exist" | Database not initialized | Import `abunetdg_fundraising.sql` |
| "No connection could be made" | MySQL/MariaDB not running | Start XAMPP MySQL service |
| "Access denied for user" | Wrong database credentials | Check `config/env.php` credentials |
| Page frozen, no error shown | Old error handling (before this fix) | Error handler now catches this |

---

## 🎯 Best Practices

### For Development (Local)
```php
// config/env.php automatically sets this for local:
ini_set('display_errors', '1');
error_reporting(E_ALL);
```
✅ Errors are shown clearly
✅ Stack traces are visible
✅ Easier to debug

### For Production (Server)
```php
// config/env.php automatically sets this for production:
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE);
```
✅ Errors hidden from users
✅ User sees friendly error page
✅ Details logged to error_log

---

## 🚨 Emergency Debugging

If the error handler itself is broken:

### Option 1: Direct PHP Error Display
Add to the **very top** of the broken page:
```php
<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
?>
```

### Option 2: Check PHP Error Log
Location: `C:\xampp\apache\logs\error.log` (Windows)

Open it to see the exact error that occurred.

### Option 3: Browser Developer Console
1. Press **F12** in browser
2. Go to **Console** tab
3. Look for JavaScript errors (red text)
4. Go to **Network** tab
5. Refresh page
6. Check if any request is returning 500 error

---

## 📝 Creating Error-Resistant Pages

### Template for Database Queries
```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

try {
    $db = db();
    
    // Your queries here
    $result = $db->query("SELECT * FROM donors WHERE id = 1");
    
    if (!$result) {
        throw new Exception("Query failed: " . $db->error);
    }
    
    $data = $result->fetch_assoc();
    
} catch (Throwable $e) {
    // Error handler will automatically catch this and show nice error page
    throw $e;
}
?>
```

### Always Use Try-Catch for Critical Operations
```php
try {
    // Risky operation
    $db->query("UPDATE donors SET balance = 0 WHERE id = ?");
} catch (mysqli_sql_exception $e) {
    // Handle gracefully
    error_log("Failed to update donor: " . $e->getMessage());
    throw new RuntimeException("Could not update donor balance. Please try again.");
}
```

---

## 🎨 Customizing Error Pages

Edit `shared/error_handler.php`:
- Change colors: Modify the `$color` variable in `showErrorPage()`
- Add more detection: Modify `$isDatabaseError` conditions
- Change buttons: Edit the "action-buttons" section in the HTML

---

## 📞 Support

If you're still stuck:
1. Check `admin/tools/database-health-check.php`
2. Check `C:\xampp\apache\logs\error.log`
3. Check browser console (F12)
4. Share the error message from the error page

---

**Last Updated:** November 24, 2025  
**Version:** 1.0

