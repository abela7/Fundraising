# Complete Analysis: What Happened with Pledge 71

## Timeline of Events

### Initial State
- **Pledge 71**: Original amount = **£400** (confirmed by user)
- **Donor**: Dahlak (ID: 180, Phone: 07956275687)

### Update Requests Timeline

1. **Batch 1** (REJECTED):
   - Created: `2025-10-30 21:21:29`
   - Type: `pledge_update`
   - Original amount: £400
   - Additional amount: £200
   - Total would be: £600
   - **Status**: `rejected` at `2025-10-30 21:23:00`
   - **Result**: Pledge 71 remained at £400

2. **Batch 12** (APPROVED):
   - Created: `2025-10-30 22:00:55`
   - Approved: `2025-10-30 22:00:56`
   - Type: `pledge_update`
   - Original amount: **£600** (incorrect! Should be £400)
   - Additional amount: £200
   - Total: £800
   - Cells allocated: `["C0505-27","C0505-28"]`
   - **Result**: Pledge 71 updated from £400 → £800 (should have been £400 → £600)

3. **Batch 13** (APPROVED - DUPLICATE):
   - Created: `2025-10-30 22:04:37`
   - Approved: `2025-10-30 22:04:37`
   - Type: `pledge_update`
   - Original amount: **£600** (incorrect! Pledge was already £800)
   - Additional amount: £200
   - Total: £800
   - Cells allocated: `["C0505-29","C0505-30"]`
   - **Result**: This should NOT have been approved! It's a duplicate of batch 12

### Current State
- **Pledge 71**: Amount = **£800** (should be £600)
- **Batches 12 & 13**: Both approved, both allocated cells, both showing incorrect `original_amount`

## The Problems

### Problem 1: Incorrect `original_amount` in Batches
- Batch 12 shows `original_amount = 600.00` but pledge 71 was actually £400
- Batch 13 shows `original_amount = 600.00` but pledge 71 was already £800 when it was approved
- This happened because the approval logic calculated `original_amount` from the CURRENT pledge amount instead of the amount BEFORE the update

### Problem 2: Duplicate Approval
- Batch 13 was approved even though batch 12 was already approved 4 minutes earlier
- Both batches allocated cells, causing duplicate cell allocation
- This is the "approving again and again" issue you mentioned

### Problem 3: Wrong Total Amount
- Pledge 71 should be: £400 (original) + £200 (batch 12) = **£600**
- But it's currently: **£800**
- This suggests batch 13 also added £200 incorrectly, OR the approval logic calculated wrong

## What the Undo Will Do

When you undo pledge 71, the system will:

1. **Find batches**: `getBatchesForPledge(71)` will find batches 12 and 13 (both have `original_pledge_id = 71`)

2. **Detect update request**: Will see `batch_type = 'pledge_update'` and `original_pledge_id = 71`

3. **Deallocate cells**: Will free cells `C0505-27`, `C0505-28`, `C0505-29`, `C0505-30`

4. **Restore amount**: 
   - Current: £800
   - Batch 12 `additional_amount`: £200
   - Batch 13 `additional_amount`: £200
   - Will subtract: £200 (from latest batch 13)
   - **Result**: £800 - £200 = **£600** ✅

5. **Update donor**: Will subtract £200 from `donors.total_pledged`

6. **Update counters**: Will subtract £200 from `counters.pledged_total`

## The Fix Needed

Before undoing, we should:
1. **Delete batch 13** (it's a duplicate)
2. **Fix batch 12's `original_amount`** to £400 (correct value)
3. **Then undo** will work correctly and restore pledge 71 to £400

OR

1. **Keep both batches** but fix their `original_amount` values
2. **Fix pledge 71 amount** to £600 (correct value)
3. **Then undo** will restore to £400 correctly

