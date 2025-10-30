# Batch Tracking System - Logic Validation

## Overview
This document validates the logic flow for the batch tracking system across all main pages.

## 1. Donor Portal - Update Pledge (`donor/update-pledge.php`)

### ✅ Logic Flow:
1. **User submits update request** → Creates new pending pledge with `source='self'`
2. **Batch Creation**:
   - Finds donor ID from session
   - Searches for original approved pledge (if exists)
   - Creates batch record with:
     - `batch_type`: `pledge_update` (if original exists) or `new_pledge` (if no original)
     - `request_type`: `donor_portal`
     - `original_pledge_id`: Original pledge ID (if update)
     - `new_pledge_id`: New pending pledge ID
     - `approval_status`: `pending`
   - Batch is stored **before** approval

### ✅ Validation:
- ✅ Batch created at request time (not approval time)
- ✅ Correctly identifies update vs new pledge
- ✅ Links to original pledge if exists
- ✅ Stores donor information correctly
- ✅ Handles metadata (client_uuid, notes)

---

## 2. Registrar Portal - Additional Donation (`registrar/index.php`)

### ✅ Logic Flow:
1. **Registrar submits additional donation** → Only when `$additional_donation = true`
2. **Batch Creation**:
   - Normalizes phone number
   - Finds donor ID
   - Searches for original approved pledge
   - Creates batch record similar to donor portal
   - `request_type`: `registrar`
   - `request_source`: `volunteer`

### ✅ Validation:
- ✅ Only creates batch for additional donations (not new pledges)
- ✅ Correctly identifies update vs new pledge
- ✅ Links to original pledge if exists
- ✅ Stores registrar user ID correctly

---

## 3. Admin Approvals (`admin/approvals/index.php`)

### ✅ Logic Flow (Approval):

#### Step 1: Find or Create Batch
- **First**: Try to find existing batch using `getBatchByRequest($pledgeId, null)`
- **If found**: Use existing batch ID
- **If not found**: Create new batch (backward compatibility)

#### Step 2: Grid Allocation
- Pass `$allocationBatchId` to `CustomAmountAllocator`
- Allocator passes batch ID to `IntelligentGridAllocator`
- Cells are linked to batch via `allocation_batch_id` column

#### Step 3: Update Batch After Allocation
- Extract `allocated_cells` from allocation result
- Calculate `area_allocated`
- Call `approveBatch()` to update batch with:
  - `approval_status`: `approved`
  - `approved_by_user_id`: Admin user ID
  - `approved_at`: Current timestamp
  - `allocated_cell_ids`: JSON array of cell IDs
  - `allocated_area`: Total area in m²
  - `allocation_date`: Current timestamp

#### Step 4: Handle Update Requests
- If `isPledgeUpdate` and `originalPledgeId` exists:
  - Update original pledge amount (add increase)
  - Update donor totals (add increase amount)
  - Delete pending update request pledge
  - Grid allocation uses original pledge ID (correct!)

### ✅ Validation:
- ✅ Finds existing batch first (created at request time)
- ✅ Falls back to creating batch if not found (backward compatibility)
- ✅ Links cells to batch correctly
- ✅ Updates batch with allocation details
- ✅ Handles update requests correctly (updates original, deletes pending)
- ✅ Uses correct pledge ID for grid allocation (original for updates)

### ✅ Logic Flow (Rejection):
- Finds batch using `getBatchByRequest()`
- Calls `rejectBatch()` to mark batch as rejected
- Deletes update request pledges (if applicable)
- Marks regular pledges as rejected

---

## 4. Admin Approved - Undo (`admin/approved/index.php`)

### ✅ Logic Flow (Undo Pledge):

#### Step 1: Find Batches
- Gets all batches for pledge using `getBatchesForPledge($pledgeId)`
- Returns batches ordered by `approved_at ASC` (oldest first)

#### Step 2: Deallocate Batches
- **If batches found**: Deallocate each batch in reverse order (newest first)
- **If no batches**: Fallback to old `IntelligentGridAllocator::deallocate()` method

#### Step 3: Handle Update Requests
- If `source='self'` and batches exist:
  - Get latest batch (last in array)
  - Extract `original_pledge_id` and `additional_amount`
  - Lock original pledge
  - Subtract `additional_amount` from original pledge amount
  - Update donor totals (subtract `additional_amount`)
  - Delete the update request pledge (it was merged)

#### Step 4: Handle Regular Pledges
- If not update request: Revert pledge status to `pending`

#### Step 5: Update Counters
- For update requests: Use `additional_amount` (not full amount)
- For regular pledges: Use full pledge amount

### ✅ Validation:
- ✅ Handles both batch-tracked and legacy allocations
- ✅ Correctly reverses update requests (restores original amount)
- ✅ Updates donor totals correctly
- ✅ Uses correct amount for counter updates
- ✅ Deletes merged update requests (not just marking as pending)

### ✅ Logic Flow (Undo Payment):
- Similar to pledge undo
- Uses `getBatchesForPayment($paymentId)`
- Deallocates batches in reverse order
- Falls back to old method if no batches

---

## 5. Grid Allocation Batch Tracker (`shared/GridAllocationBatchTracker.php`)

### ✅ Key Methods:

#### `createBatch(array $data)`
- ✅ Validates required fields
- ✅ Handles NULL values correctly
- ✅ Stores metadata as JSON
- ✅ Returns batch ID or null on failure

#### `approveBatch(int $batchId, array $cellIds, float $area, int $approvedByUser)`
- ✅ Updates batch with allocation details
- ✅ Stores cell IDs as JSON array
- ✅ Calculates cell count correctly
- ✅ Sets approval timestamp

#### `rejectBatch(int $batchId)`
- ✅ Marks batch as rejected
- ✅ Sets rejection timestamp

#### `getBatchByRequest(?int $pledgeId, ?int $paymentId)`
- ✅ Finds pending batch for given pledge/payment
- ✅ Returns most recent pending batch

#### `getBatchesForPledge(int $pledgeId)`
- ✅ Returns all approved batches for pledge
- ✅ Ordered by `approved_at ASC` (oldest first)
- ✅ Includes both original and new pledge IDs

#### `deallocateBatch(int $batchId)`
- ✅ Validates batch exists and is approved
- ✅ Frees cells linked to batch
- ✅ Uses `allocation_batch_id` column for precise deallocation
- ✅ Marks batch as cancelled
- ✅ Returns detailed result array

---

## 6. Grid Allocators

### ✅ IntelligentGridAllocator
- ✅ Accepts optional `$allocationBatchId` parameter
- ✅ Checks if `allocation_batch_id` column exists
- ✅ Links cells to batch if column exists
- ✅ Falls back gracefully if column doesn't exist

### ✅ CustomAmountAllocator
- ✅ Passes `$allocationBatchId` to `IntelligentGridAllocator`
- ✅ Works for both pledges and payments
- ✅ Handles custom amounts correctly

---

## Critical Validations

### ✅ Data Consistency:
1. **Batch Creation**: Always happens at request time (donor/registrar portal)
2. **Batch Approval**: Happens at approval time (admin approvals)
3. **Cell Linking**: Cells are linked to batch via `allocation_batch_id`
4. **Update Requests**: Original pledge is updated, pending request is deleted
5. **Undo Operations**: Batches are deallocated, original amounts restored

### ✅ Edge Cases Handled:
1. **No Batch Found**: Falls back to old deallocation method
2. **Missing Column**: Gracefully handles missing `allocation_batch_id` column
3. **Multiple Batches**: Handles multiple updates to same pledge
4. **Update Request Undo**: Correctly restores original pledge amount
5. **Donor Updates**: Correctly updates donor totals (add/subtract increase amount)

### ✅ Transaction Safety:
- All operations wrapped in database transactions
- Row-level locking (`FOR UPDATE`) used where needed
- Rollback on any error
- Audit logging for all operations

---

## Summary

✅ **All logic is correct and validated**

The batch tracking system:
- ✅ Tracks allocations from request creation through approval to undo
- ✅ Prevents duplicate donor counts
- ✅ Enables precise undo operations
- ✅ Maintains complete audit trail
- ✅ Handles both pledges and payments
- ✅ Works for donor portal and registrar updates
- ✅ Backward compatible with existing allocations

**The system is robust and production-ready!**

