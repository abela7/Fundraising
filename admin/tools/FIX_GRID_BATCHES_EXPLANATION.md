# Grid Allocation Batches Data Issues - Analysis & Fix

## What Happened?

Based on the `grid_allocation_batches.sql` file, here are the issues identified:

### Issue 1: Orphaned Batches with NULL `new_pledge_id`
- **Batches 3-11**: All have `batch_type='new_pledge'` but `new_pledge_id=NULL`
- These batches were created but never linked to actual pledges
- They are all `pending` status, meaning they're waiting for approval but can't be found when admin tries to approve

### Issue 2: Duplicate Batches
- Multiple batches (3-11) for the same donor (180) with the same amount (£200)
- This suggests the donor submitted the form multiple times, creating duplicate batches
- Each submission created a new batch but may have failed to create the pledge or link them properly

### Issue 3: Update Request Batch Not Linked
- **Batch ID 2**: `pledge_update` type with `original_pledge_id=71` but `new_pledge_id=NULL`
- This is a pending update request that should have `new_pledge_id` pointing to the pending pledge
- When admin tries to approve, `getBatchByRequest()` can't find it because `new_pledge_id` is NULL

### Issue 4: Approved Batches with NULL `new_pledge_id`
- **Batches 12-13**: Approved with allocated cells but `new_pledge_id=NULL`
- For update requests, this is EXPECTED because the pending pledge gets DELETED after approval
- However, the batch should still be trackable via `original_pledge_id`

## Root Causes

1. **Race Condition**: When `donor/update-pledge.php` creates a batch, if the transaction fails or there's an error, the batch might be created without the pledge ID
2. **Missing Link**: If batch creation happens before pledge insertion completes, `new_pledge_id` could be NULL
3. **Update Request Handling**: When update requests are approved, the pending pledge is deleted, leaving `new_pledge_id` as NULL (this is correct behavior, but we need to handle it)

## What the Fix Does

The SQL script (`fix_grid_allocation_batches_comprehensive.sql`) will:

1. **Delete Orphaned Batches**: Remove pending batches with NULL `new_pledge_id` that have no allocated cells (safe to delete)
2. **Link Orphaned Batches**: Try to match batches with pending pledges by donor_id, amount, and timestamp
3. **Remove Duplicates**: Keep only the most recent batch for duplicate pending batches
4. **Identify Issues**: Mark batches that need manual review (approved batches with cells but NULL `new_pledge_id`)

## Expected Behavior After Fix

- All pending batches should have `new_pledge_id` pointing to a valid pending pledge
- No duplicate pending batches for the same donor/amount
- Approved update request batches will have NULL `new_pledge_id` (expected) but will reference `original_pledge_id`
- All batches will be trackable via `getBatchByRequest()` or `original_pledge_id`

