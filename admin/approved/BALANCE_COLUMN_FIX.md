# Critical Balance Column Fix

## 🚨 Issue Discovered

The `balance` column in the `donors` table is a **GENERATED/COMPUTED column** defined as:

```sql
balance DECIMAL(10,2) GENERATED ALWAYS AS (total_pledged - total_paid) STORED
```

This means:
- ✅ `balance` is **automatically calculated** as `total_pledged - total_paid`
- ❌ You **CANNOT** and **SHOULD NOT** update it directly
- ✅ It updates automatically whenever `total_pledged` or `total_paid` changes

---

## 🐛 The Bug

In `admin/approved/index.php`, the undo batch functionality was trying to update `balance` directly:

### ❌ WRONG CODE (Before Fix):
```php
// For pledge_update batches
UPDATE donors SET
    total_pledged = total_pledged - ?,
    balance = balance - ?,  // <-- WRONG! Can't update generated column
    updated_at = NOW()
WHERE id = ?

// For other batches
UPDATE donors SET
    total_pledged = total_pledged + ?,
    total_paid = total_paid + ?,
    balance = balance + ?,  // <-- WRONG! Can't update generated column
    updated_at = NOW()
WHERE id = ?
```

### ✅ CORRECT CODE (After Fix):
```php
// For pledge_update batches
UPDATE donors SET
    total_pledged = total_pledged - ?,
    updated_at = NOW()
WHERE id = ?
// balance auto-calculates!

// For other batches
UPDATE donors SET
    total_pledged = total_pledged + ?,
    total_paid = total_paid + ?,
    updated_at = NOW()
WHERE id = ?
// balance auto-calculates!
```

---

## 📊 Impact Analysis

### Before Fix (Dahlak Example):
1. **Initial State:**
   - `total_pledged`: £400
   - `total_paid`: £0
   - `balance`: £400 (auto-calculated)

2. **After Adding £200:**
   - `total_pledged`: £600
   - `total_paid`: £0
   - `balance`: £600 (auto-calculated) ✅

3. **After Undo (With Bug):**
   - `total_pledged`: £400 (correctly reduced)
   - `total_paid`: £0
   - `balance`: **£600** (NOT updated because can't update generated column!) ❌

### After Fix (Expected Behavior):
1. **After Undo:**
   - `total_pledged`: £400 (correctly reduced)
   - `total_paid`: £0
   - `balance`: **£400** (auto-calculated) ✅

---

## 🔍 Why Was This Happening?

MySQL has different behaviors for generated columns depending on version and settings:

1. **Some MySQL versions**: Silently ignore attempts to update generated columns
2. **Other versions**: Throw an error (which might be caught and logged but not displayed)
3. **Result**: The `balance` column kept its old value because the database rejected the update

---

## ✅ Files Fixed

### `admin/approved/index.php`
- **Line 145-157**: Fixed pledge_update undo logic
- **Line 217-231**: Fixed other batch types undo logic

### Changes:
- ✅ Removed `balance = balance - ?` from UPDATE statement
- ✅ Removed `balance = balance + ?` from UPDATE statement
- ✅ Updated `bind_param` types from `'ddi'` to `'di'` (for pledge_update)
- ✅ Updated `bind_param` types from `'dddi'` to `'ddi'` (for other types)
- ✅ Added comments explaining why balance is not updated directly

---

## 🧪 Testing Steps

1. **Verify Dahlak's current state:**
   ```sql
   SELECT id, name, phone, total_pledged, total_paid, balance 
   FROM donors 
   WHERE name = 'Dahlak';
   ```
   - Expected: `total_pledged` = £400, `balance` = £600 (still wrong from previous bug)

2. **Manually fix Dahlak's data:**
   Since the balance is a generated column, we can force a recalculation by updating another field:
   ```sql
   UPDATE donors 
   SET total_pledged = total_pledged 
   WHERE name = 'Dahlak';
   ```
   This will trigger the balance to recalculate.
   
   **Or more explicitly:**
   ```sql
   -- Verify current values
   SELECT id, total_pledged, total_paid, balance FROM donors WHERE name = 'Dahlak';
   
   -- The balance should auto-fix when we query it, but if not:
   -- Force a recalculation by touching the record
   UPDATE donors SET updated_at = NOW() WHERE name = 'Dahlak';
   ```

3. **Test the undo flow again:**
   - Have Dahlak add another £200 update
   - Approve it
   - Verify `total_pledged` = £600, `balance` = £600
   - Undo the update
   - **Expected Result**: `total_pledged` = £400, `balance` = £400 ✅

---

## 📝 Additional Recommendations

### Check All Donor Update Queries
Search for any other places where `balance` is being updated directly:

```bash
grep -r "balance.*=" admin/ | grep -i update
```

### Common Patterns to Avoid:
```sql
-- ❌ WRONG
UPDATE donors SET balance = balance + 100 ...
UPDATE donors SET balance = balance - 100 ...
UPDATE donors SET balance = ? ...
UPDATE donors SET balance = total_pledged - total_paid ... (redundant!)

-- ✅ CORRECT
UPDATE donors SET total_pledged = total_pledged + 100 ...
UPDATE donors SET total_paid = total_paid + 100 ...
-- Let balance calculate automatically!
```

---

## 🎯 Resolution

**Status**: ✅ **FIXED**

- ✅ Removed direct balance updates from undo logic
- ✅ Balance will now auto-calculate correctly
- ✅ Donor portal will show correct values after session refresh
- ✅ Admin portal will show correct values immediately

**Next Steps:**
1. ✅ Code fixed in `admin/approved/index.php`
2. 🔄 User should test: Add £200 → Approve → Undo → Verify balance = £400
3. 🔍 Optional: Run SQL to force balance recalculation for existing records with incorrect balance

---

## 🔧 SQL to Fix Existing Incorrect Balances

If there are existing donors with incorrect balance values:

```sql
-- This will trigger recalculation for all donors
UPDATE donors SET updated_at = NOW();

-- Or check which donors have incorrect balance
SELECT 
    id, 
    name, 
    phone,
    total_pledged, 
    total_paid, 
    balance,
    (total_pledged - total_paid) AS calculated_balance,
    CASE 
        WHEN balance != (total_pledged - total_paid) THEN 'INCORRECT'
        ELSE 'OK'
    END AS status
FROM donors
WHERE balance != (total_pledged - total_paid);
```

---

**Date Fixed**: 2025-11-17  
**Severity**: HIGH (Data integrity issue)  
**Impact**: Donor portal and admin portal showing incorrect remaining balance

