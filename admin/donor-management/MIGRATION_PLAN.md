# Migration Plan: Remove Cache Columns from Donors Table

## Goal
Remove redundant cache columns from `donors` table and use only `donor_payment_plans` (master table) for plan details.

## What We're Keeping
- ✅ `has_active_plan` (tinyint) - Quick flag: does donor have active plan?
- ✅ `active_payment_plan_id` (int) - FK to current active plan

## What We're Removing
- ❌ `plan_monthly_amount` - redundant with `donor_payment_plans.monthly_amount`
- ❌ `plan_duration_months` - redundant with `donor_payment_plans.total_months`
- ❌ `plan_start_date` - redundant with `donor_payment_plans.start_date`
- ❌ `plan_next_due_date` - redundant with `donor_payment_plans.next_payment_due`

---

## Files to Update (16 files)

### Admin Section (11 files)
1. ✅ `admin/donor-management/table-usage-analysis-report.php` (report only)
2. ✅ `admin/donor-management/DATABASE_SYNC_SOLUTION.md` (doc only)
3. ✅ `admin/donor-management/database-analysis-report.php` (report only)
4. 🔧 `admin/donor-management/fix-payment-plans.php` - Remove sync logic
5. 🔧 `admin/donor-management/payment-plans.php` - Check modal preview
6. 🔧 `admin/donor-management/edit-payment-plan.php` - Update queries
7. 🔧 `admin/donor-management/donors.php` - Update list view
8. 🔧 `admin/call-center/plan-success.php` - Update success page
9. 🔧 `admin/tools/fix_partial_migration.php` - Remove from migration
10. 🔧 `admin/tools/migrate_donors_system.php` - Remove from migration
11. ✅ `admin/tools/MIGRATION_SUMMARY.md` (doc only)

### Donor Portal (5 files)
12. 🔧 `donor/login.php` - Check if used
13. 🔧 `donor/update-pledge.php` - Check if used
14. 🔧 `donor/profile.php` - Check if used
15. 🔧 `donor/index.php` - Check if used
16. 🔧 `donor/make-payment.php` - Check if used

---

## New Pattern for Reading Plan Data

### Before (Bad - uses cache)
```php
$donor = get_donor_by_id($donor_id);
echo "Monthly: £" . $donor['plan_monthly_amount'];
echo "Duration: " . $donor['plan_duration_months'] . " months";
```

### After (Good - uses master table)
```php
$donor = get_donor_by_id($donor_id);

// If donor has active plan, fetch from master table
$plan = null;
if ($donor['has_active_plan'] && $donor['active_payment_plan_id']) {
    $plan_query = $db->prepare("
        SELECT * FROM donor_payment_plans 
        WHERE id = ? AND status = 'active'
    ");
    $plan_query->bind_param('i', $donor['active_payment_plan_id']);
    $plan_query->execute();
    $plan = $plan_query->get_result()->fetch_assoc();
}

if ($plan) {
    echo "Monthly: £" . number_format($plan['monthly_amount'], 2);
    echo "Duration: " . $plan['total_months'] . " months";
} else {
    echo "No active plan";
}
```

---

## New Pattern for Writing Plan Data

### Before (Bad - writes to both tables)
```php
// Create plan in master table
$db->query("INSERT INTO donor_payment_plans ...");
$plan_id = $db->insert_id;

// Sync to cache (causes issues!)
$db->query("UPDATE donors SET 
    active_payment_plan_id = $plan_id,
    has_active_plan = 1,
    plan_monthly_amount = $monthly,
    plan_duration_months = $months,
    plan_start_date = '$start',
    plan_next_due_date = '$next'
WHERE id = $donor_id");
```

### After (Good - writes to master, updates flag only)
```php
// Create plan in master table
$db->query("INSERT INTO donor_payment_plans ...");
$plan_id = $db->insert_id;

// Update flag only (no duplication!)
$db->query("UPDATE donors SET 
    active_payment_plan_id = $plan_id,
    has_active_plan = 1
WHERE id = $donor_id");
```

---

## Migration Steps

1. **Run SQL migration**
   - Execute `remove-cache-columns-migration.sql`
   - Drops 4 columns from `donors` table
   - Syncs `has_active_plan` and `active_payment_plan_id`

2. **Update all affected PHP files**
   - Replace cache column reads with JOIN to `donor_payment_plans`
   - Remove cache column writes from INSERT/UPDATE statements
   - Test each page

3. **Clean up old code**
   - Delete `fix-payment-plans.php` (no longer needed)
   - Delete `database-analysis-report.php` (reports on old structure)
   - Delete trigger SQL files (no longer needed)

4. **Test thoroughly**
   - Create new payment plan → Check flags updated
   - View donor page → Check plan details display
   - Update plan → Check changes reflected
   - Complete plan → Check flags cleared

---

## Benefits of This Approach

✅ **Single Source of Truth** - No sync issues possible
✅ **Data Integrity** - Master table is always correct
✅ **Cleaner Code** - No complex sync logic needed
✅ **Future-Proof** - New columns automatically available
✅ **Maintainable** - Less code to maintain

---

## Rollback Plan (if needed)

If something breaks, you can restore the columns:

```sql
ALTER TABLE donors 
ADD COLUMN plan_monthly_amount DECIMAL(10,2) NULL AFTER active_payment_plan_id,
ADD COLUMN plan_duration_months INT NULL AFTER plan_monthly_amount,
ADD COLUMN plan_start_date DATE NULL AFTER plan_duration_months,
ADD COLUMN plan_next_due_date DATE NULL AFTER plan_start_date;

-- Then sync data
UPDATE donors d
INNER JOIN donor_payment_plans p ON d.active_payment_plan_id = p.id
SET d.plan_monthly_amount = p.monthly_amount,
    d.plan_duration_months = p.total_months,
    d.plan_start_date = p.start_date,
    d.plan_next_due_date = p.next_payment_due
WHERE p.status = 'active';
```

But this should NOT be necessary if we update all files correctly!

