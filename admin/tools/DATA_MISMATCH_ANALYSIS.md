# CRITICAL DATA MISMATCH ANALYSIS

## 🔴 ISSUE IDENTIFIED: Data Inconsistency Between Tables

### Current State Summary

#### 1. **Pledge 71 (Dahlak)**
- **pledges table**: `amount = 600.00`, `status = 'approved'`, `donor_id = 180`
- **donors table (ID 180)**: `total_pledged = 400.00` ❌ **MISMATCH!**
- **Expected**: `total_pledged` should be `600.00` (matching pledge 71 amount)

#### 2. **Grid Allocation Batches**
- **Batch 1**: `rejected`, `original_amount=400`, `additional_amount=200` ✅
- **Batch 12**: `approved`, `original_amount=400`, `additional_amount=200`, `allocated_cell_ids=['C0505-27','C0505-28']` ✅

#### 3. **Floor Grid Cells for Dahlak (Pledge 71)**
```
C0505-27: pledge_id=71, allocation_batch_id=12, donor_name='Dahlak', amount=100.00 ✅
C0505-28: pledge_id=71, allocation_batch_id=12, donor_name='Dahlak', amount=100.00 ✅
C0505-29: pledge_id=71, allocation_batch_id=NULL ❌, donor_name='Dahlak', amount=100.00 ⚠️
C0505-30: pledge_id=71, allocation_batch_id=NULL ❌, donor_name='Dahlak', amount=100.00 ⚠️
```

**CRITICAL ISSUE**: Cells C0505-29 and C0505-30 are allocated to pledge 71 but have NO `allocation_batch_id`!

#### 4. **Audit Logs Show TWO Approvals**
- **Audit Log 533** (2025-10-30 22:00:56): Approved update request, allocated C0505-27, C0505-28 ✅
- **Audit Log 534** (2025-10-30 22:04:38): Approved update request, allocated C0505-29, C0505-30 ⚠️

**PROBLEM**: Two separate approvals happened! The second approval created cells WITHOUT a batch ID!

#### 5. **Original Cells from Initial Pledge**
```
A0505-337: pledge_id=71, allocation_batch_id=NULL, donor_name='Dahlak', amount=100.00
A0505-338: pledge_id=71, allocation_batch_id=NULL, donor_name='Dahlak', amount=100.00
A0505-339: pledge_id=71, allocation_batch_id=NULL, donor_name='Dahlak', amount=100.00
A0505-340: pledge_id=71, allocation_batch_id=NULL, donor_name='Dahlak', amount=100.00
```

**Total cells for Dahlak**: 8 cells (4 original + 2 from batch 12 + 2 orphaned)

### 6. **Abeba Tamene (Donor 41)**
- **donors table**: `total_pledged = 200.00` ✅
- **pledges table**:
  - Pledge 90: `amount=200.00`, `status='approved'` ✅
  - Pledge 203: `amount=200.00`, `status='rejected'` (probably an update request that was rejected)
- **floor_grid_cells**:
  - A0505-376: pledge_id=90, allocation_batch_id=NULL, donor_name='Abeba tamene', amount=100.00 ✅
  - A0505-377: pledge_id=90, allocation_batch_id=NULL, donor_name='Abeba tamene', amount=100.00 ✅

**Status**: Abeba Tamene's data appears consistent ✅

---

## 🔍 ROOT CAUSE ANALYSIS

### What Happened to Dahlak's Pledge:

1. **Original Pledge**: £400 (4 cells: A0505-337 to A0505-340)
2. **First Update Request** (Batch 1): Rejected ✅
3. **Second Update Request** (Batch 12): Approved at 22:00:56
   - Allocated C0505-27, C0505-28 ✅
   - **BUT**: `donors.total_pledged` was NOT updated correctly! Still shows 400 instead of 600!
4. **Third Approval** (at 22:04:38): Another approval happened (probably duplicate)
   - Allocated C0505-29, C0505-30
   - **BUT**: No batch record created! Cells have `allocation_batch_id=NULL` ⚠️
   - This suggests a second approval happened WITHOUT proper batch tracking

### The Problem:

1. **`donors.total_pledged` is wrong**: Should be 600, but shows 400
2. **Orphaned cells**: C0505-29 and C0505-30 have no batch_id
3. **Missing batch record**: There should be a batch 13 for the second approval, but it doesn't exist
4. **Pledge amount is correct**: Pledge 71 shows 600.00 ✅
5. **Cells allocated correctly**: 8 cells total (4 original + 4 from updates) ✅

---

## ✅ FIX REQUIRED

### Step 1: Fix `donors.total_pledged` for Donor 180
```sql
UPDATE donors 
SET total_pledged = 600.00,
    balance = 600.00 - total_paid,
    payment_status = CASE
        WHEN total_paid = 0 THEN 'not_started'
        WHEN total_paid >= 600.00 THEN 'completed'
        WHEN total_paid > 0 THEN 'paying'
        ELSE 'not_started'
    END
WHERE id = 180;
```

### Step 2: Create Missing Batch Record for Orphaned Cells
```sql
INSERT INTO grid_allocation_batches (
    batch_type, request_type, original_pledge_id, new_pledge_id,
    donor_id, donor_name, donor_phone,
    original_amount, additional_amount, total_amount,
    requested_by_donor_id, request_source,
    approval_status, approved_by_user_id, approved_at,
    allocated_cell_ids, allocated_cell_count, allocated_area, allocation_date
) VALUES (
    'pledge_update', 'donor_portal', 71, NULL,
    180, 'Dahlak', '07956275687',
    600.00, 200.00, 800.00,
    180, 'self',
    'approved', 1, '2025-10-30 22:04:38',
    '["C0505-29","C0505-30"]', 2, 0.50, '2025-10-30 22:04:38'
);
```

**Note**: After inserting, get the new batch ID and update cells:
```sql
-- Get the new batch ID (should be 13)
SELECT id FROM grid_allocation_batches 
WHERE allocated_cell_ids = '["C0505-29","C0505-30"]' 
AND approval_status = 'approved';

-- Update orphaned cells with batch ID (assuming batch ID is 13)
UPDATE floor_grid_cells 
SET allocation_batch_id = 13 
WHERE cell_id IN ('C0505-29', 'C0505-30') AND pledge_id = 71;
```

### Step 3: Fix Counters Table
```sql
-- Check current counters
SELECT * FROM counters WHERE id = 1;

-- If pledged_total is wrong, update it
-- Expected: Should include the 200 from batch 12 + 200 from orphaned cells = 400 additional
-- But we need to check what the actual current value is first
```

---

## 📊 VERIFICATION QUERIES

### Check Donor 180 State:
```sql
SELECT 
    d.id, d.name, d.total_pledged, d.total_paid, d.balance,
    p.id as pledge_id, p.amount as pledge_amount, p.status as pledge_status
FROM donors d
LEFT JOIN pledges p ON p.donor_id = d.id AND p.status = 'approved'
WHERE d.id = 180;
```

### Check All Cells for Pledge 71:
```sql
SELECT 
    cell_id, status, pledge_id, allocation_batch_id, 
    donor_name, amount, assigned_date
FROM floor_grid_cells
WHERE pledge_id = 71
ORDER BY assigned_date, cell_id;
```

### Check All Batches for Pledge 71:
```sql
SELECT 
    id, batch_type, approval_status, original_pledge_id, new_pledge_id,
    original_amount, additional_amount, total_amount,
    allocated_cell_ids, allocated_cell_count, allocated_area,
    approved_at
FROM grid_allocation_batches
WHERE original_pledge_id = 71 OR new_pledge_id = 71
ORDER BY id;
```

### Check Audit Logs for Pledge 71:
```sql
SELECT 
    id, user_id, entity_type, entity_id, action,
    created_at, after_json
FROM audit_logs
WHERE entity_type = 'pledge' AND entity_id = 71
ORDER BY created_at DESC
LIMIT 10;
```

---

## 🎯 SUMMARY

**Main Issues**:
1. ❌ `donors.total_pledged` = 400 (should be 600)
2. ❌ Cells C0505-29, C0505-30 have no `allocation_batch_id`
3. ❌ Missing batch record for second approval (22:04:38)
4. ✅ Pledge 71 amount is correct (600)
5. ✅ All cells are properly allocated to pledge 71

**Fix Priority**:
1. Fix `donors.total_pledged` first (critical for reports)
2. Create missing batch record for orphaned cells
3. Link orphaned cells to new batch record
4. Verify counters table is correct

