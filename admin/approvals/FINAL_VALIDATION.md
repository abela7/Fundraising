# Final Validation - Both Scenarios

## ✅ SCENARIO 1: Update → Approve → Check Floor → Undo → Verify

### Step 1: Donor Updates Pledge ✅
**State**:
- New pledge created: `id=X`, `status='pending'`, `source='self'`, `amount=£200`
- Batch created: `id=B`, `approval_status='pending'`, `new_pledge_id=X`, `original_pledge_id=Y`
- Audit log: `action='create_pending'`, `entity_id=X`

### Step 2: Admin Approves ✅ **FIXED**
**Operations**:
1. ✅ Detect update request (`source='self'`)
2. ✅ Find original pledge (id=Y, amount=£400)
3. ✅ Update counters: `pledged_total += £200` (increase amount)
4. ✅ Allocate grid cells: Linked to original pledge (id=Y) with batch (id=B)
5. ✅ Update batch: `approval_status='approved'`, stores cell IDs
6. ✅ Update original pledge: `amount = £400 + £200 = £600`
7. ✅ Update donor: `total_pledged += £200`
8. ✅ DELETE update request pledge (id=X)
9. ✅ Audit log: `entity_id=Y` (original pledge), `action='approve'`, `is_update_request=true`

**Final State**:
- `pledges`: Original (id=Y) = £600, approved; Update request (id=X) = DELETED
- `grid_allocation_batches`: Batch (id=B) = approved, `new_pledge_id=X` (OK, batch still tracks it)
- `counters`: `pledged_total += £200` ✅
- `donors`: `total_pledged += £200` ✅
- `floor_grid_cells`: Cells allocated with `pledge_id=Y`, `allocation_batch_id=B` ✅

### Step 3: Check Floor ✅
**Expected**: Cells show allocated with original pledge ID (Y)
**✅ VALID**: Correct!

### Step 4: Admin Undoes ✅ **FIXED**
**Operations**:
1. ✅ Find batches: `getBatchesForPledge(Y)` finds batch B (has `original_pledge_id=Y`)
2. ✅ Detect update: Checks batches for `original_pledge_id=Y` → `isUpdateRequest=true`
3. ✅ Deallocate batches: Frees cells linked to batch B
4. ✅ Restore original amount: `£600 - £200 = £400` (keeps pledge approved!)
5. ✅ Update donor: `total_pledged -= £200`
6. ✅ Update counters: `pledged_total -= £200` (uses `additional_amount` from batch)
7. ✅ Audit log: `action='undo_approve'`, `is_update_undo=true`

**Final State**:
- `pledges`: Original (id=Y) = £400, **still approved** ✅
- `counters`: `pledged_total -= £200` ✅
- `donors`: `total_pledged -= £200` ✅
- `floor_grid_cells`: Cells deallocated ✅

**✅ ALL VALID**: Logic is correct!

---

## ✅ SCENARIO 2: Update → Reject → Check Database

### Step 1: Donor Updates Pledge ✅
Same as Scenario 1, Step 1

### Step 2: Admin Rejects ✅
**Operations**:
1. ✅ Find batch: `getBatchByRequest(X, null)` finds batch B
2. ✅ Reject batch: `approval_status='rejected'`
3. ✅ Detect update request: `source='self'`
4. ✅ DELETE update request pledge (id=X)
5. ✅ Audit log: `action='reject'`, `entity_id=X`

**Final State**:
- `pledges`: Update request (id=X) = **DELETED** ✅
- `grid_allocation_batches`: Batch (id=B) = `approval_status='rejected'` ✅
- `audit_logs`: Entry for rejection ✅

**✅ ALL VALID**: Logic is correct!

---

## ✅ Audit Logging Validation

### Scenario 1 Audit Trail:
1. **Request Creation**: `action='create_pending'`, `entity_id=X`, `source='donor_portal'` ✅
2. **Approval**: `action='approve'`, `entity_id=Y` (original), `is_update_request=true` ✅
3. **Undo**: `action='undo_approve'`, `entity_id=Y`, `is_update_undo=true` ✅

### Scenario 2 Audit Trail:
1. **Request Creation**: `action='create_pending'`, `entity_id=X`, `source='donor_portal'` ✅
2. **Rejection**: `action='reject'`, `entity_id=X` ✅

**✅ ALL VALID**: Complete audit trail maintained!

---

## ✅ Database Consistency Checks

### No Orphaned Records:
- ✅ Update request pledges are deleted (not left as rejected)
- ✅ Batches reference deleted pledges (acceptable for audit trail)
- ✅ Cells are properly linked to batches

### No Duplicate Counts:
- ✅ Counters use increase amount, not full amount
- ✅ Donor totals use increase amount
- ✅ Original pledge amount is updated, not duplicated

### Transaction Safety:
- ✅ All operations wrapped in transactions
- ✅ Row-level locking used
- ✅ Rollback on errors

---

## ✅ FINAL CONFIRMATION

**Both scenarios are logically valid and database-safe!**

All operations:
- ✅ Use correct amounts (increase vs full)
- ✅ Maintain data consistency
- ✅ Create proper audit trails
- ✅ Handle edge cases correctly
- ✅ Use transactions and locking
- ✅ Update all related tables correctly

**The system is ready for testing!**

