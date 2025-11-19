# Database Sync Solution - Payment Plans

## Problem Analysis

### Current Situation
- **donor_payment_plans** table: Master record (Source of Truth)
  - Plan #11 has `monthly_amount = 33.33` ✅ (CORRECT)
  - Plan #11 has `total_amount = 400.00` ✅
  - Plan #11 has `total_payments = 12` ✅

- **donors** table: Cache/Index columns (for fast searching)
  - Donor #180 has `has_active_plan = 0` ❌ (Should be 1)
  - Donor #180 has `active_payment_plan_id = NULL` ❌ (Should be 11)
  - Donor #180 has `plan_monthly_amount = NULL` ❌ (Should be 33.33)
  - Donor #180 has `plan_duration_months = NULL` ❌ (Should be 12)
  - Donor #180 has `plan_start_date = NULL` ❌ (Should be 2025-12-01)
  - Donor #180 has `plan_next_due_date = NULL` ❌ (Should be 2025-12-01)

### Root Cause
The `donors` table columns are **NOT automatically synced** when payment plans are created/updated. This causes:
1. Search/filtering issues (can't find donors with active plans)
2. Display issues (view pages show wrong data)
3. Data inconsistency

---

## Recommended Solution: MySQL TRIGGERS

**Why Triggers?**
- ✅ Automatic sync at database level
- ✅ No code changes needed
- ✅ Works even if code is bypassed
- ✅ Zero performance impact on reads
- ✅ Handles all INSERT/UPDATE operations

---

## Implementation Steps

### Step 1: Fix Existing Data
Run `sync-existing-plans.sql` in phpMyAdmin:
- Fixes zero monthly amounts
- Syncs all active plans to donors table
- Clears orphaned donor records

### Step 2: Create Auto-Sync Triggers
Run `create-payment-plan-triggers.sql` in phpMyAdmin:
- Creates trigger for INSERT (new plans)
- Creates trigger for UPDATE (plan changes)
- Automatically syncs donors table

### Step 3: Verify
1. Check triggers exist: `SHOW TRIGGERS;`
2. Create a test plan and verify donor table updates
3. Update a plan and verify donor table updates

---

## Column Mapping

| donor_payment_plans (Master) | → | donors (Cache) |
|------------------------------|---|----------------|
| `id` | → | `active_payment_plan_id` |
| `monthly_amount` | → | `plan_monthly_amount` |
| `total_months` | → | `plan_duration_months` |
| `start_date` | → | `plan_start_date` |
| `next_payment_due` | → | `plan_next_due_date` |
| `status = 'active'` | → | `has_active_plan = 1` |
| `status = 'completed'` | → | `has_active_plan = 0`, clear fields |
| `status = 'cancelled'` | → | `has_active_plan = 0`, clear fields |

---

## Files Created

1. **database-analysis-report.php** - Visual analysis of table structures and sync status
2. **create-payment-plan-triggers.sql** - SQL to create auto-sync triggers
3. **sync-existing-plans.sql** - SQL to fix existing data
4. **fix-payment-plans.php** - PHP tool to run sync manually (backup method)

---

## Alternative: Code-Based Sync

If triggers are not preferred, update these files to sync manually:
- `admin/call-center/process-conversation.php` - After creating plan
- `admin/donor-management/update-payment-plan.php` - After updating plan
- `admin/donor-management/update-payment-plan-status.php` - After status change

But **triggers are recommended** as they're more reliable and automatic.

---

## Testing

After implementing triggers:

1. **Create a new plan** → Check donor table updates automatically
2. **Update plan status** → Check donor table updates automatically  
3. **Update monthly amount** → Check donor table updates automatically
4. **Complete a plan** → Check donor table clears automatically

---

## Notes

- The `donors` table columns are **cache/index columns** for fast searching
- The **master data** is always in `donor_payment_plans`
- Triggers ensure the cache stays in sync automatically
- If triggers fail, use the PHP sync tool as backup

