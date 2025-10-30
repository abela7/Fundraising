# Complete Database Schema Understanding

## Core Tables and Their Relationships

### 1. `pledges` Table
**Purpose**: Stores individual pledge records (both regular pledges and update requests)

**Key Columns**:
- `id`: Primary key
- `donor_id`: Foreign key → `donors.id` (nullable, set when donor exists)
- `donor_name`, `donor_phone`, `donor_email`: Denormalized donor info (for when donor_id is NULL)
- `amount`: The pledge amount (for update requests, this is the ADDITIONAL amount being requested)
- `type`: 'pledge' or 'paid'
- `status`: 'pending', 'approved', 'rejected', 'cancelled'
- `source`: 'self' (donor portal) or 'volunteer' (registrar)
- `created_by_user_id`: Foreign key → `users.id` (who created this pledge)
- `approved_by_user_id`: Foreign key → `users.id` (who approved this pledge)

**Important Behavior**:
- For update requests: When approved, the ORIGINAL pledge's `amount` is updated, and the pending update request pledge is DELETED
- `donor_id` is set when a donor exists in the `donors` table

### 2. `donors` Table
**Purpose**: Central donor registry with denormalized totals

**Key Columns**:
- `id`: Primary key
- `phone`: Unique identifier (UK mobile format)
- `name`: Donor name
- `total_pledged`: SUM of all approved pledge amounts (denormalized, manually updated)
- `total_paid`: SUM of all approved payment amounts (denormalized, manually updated)
- `balance`: Calculated as `total_pledged - total_paid`
- `donor_type`: 'immediate_payment' or 'pledge'
- `payment_status`: 'no_pledge', 'not_started', 'paying', 'overdue', 'completed', 'defaulted'
- `last_pledge_id`: Foreign key → `pledges.id` (most recent pledge, optimization)
- `pledge_count`: Count of pledges (denormalized)
- `payment_count`: Count of payments (denormalized)

**Important Behavior**:
- `total_pledged` is updated when pledges are approved
- For update requests: Only the ADDITIONAL amount is added to `total_pledged` (not the total)
- `balance` is recalculated as `total_pledged - total_paid`
- `payment_status` is calculated based on `total_paid` vs `total_pledged`

### 3. `grid_allocation_batches` Table
**Purpose**: Tracks batches of grid cell allocations, especially for update requests

**Key Columns**:
- `id`: Primary key
- `batch_type`: 'new_pledge', 'new_payment', 'pledge_update', 'payment_update'
- `request_type`: 'donor_portal', 'registrar', 'admin'
- `original_pledge_id`: Foreign key → `pledges.id` (for update requests, the original pledge being updated)
- `original_payment_id`: Foreign key → `payments.id` (for payment updates)
- `new_pledge_id`: Foreign key → `pledges.id` (the pending pledge ID, NULL for approved update requests because pending pledge is deleted)
- `new_payment_id`: Foreign key → `payments.id` (the pending payment ID)
- `donor_id`: Foreign key → `donors.id`
- `original_amount`: Amount of original pledge BEFORE the update (for update requests)
- `additional_amount`: Amount being added/allocated
- `total_amount`: `original_amount + additional_amount`
- `approval_status`: 'pending', 'approved', 'rejected', 'cancelled'
- `allocated_cell_ids`: JSON array of cell_id strings assigned to this batch
- `allocated_cell_count`: Number of cells allocated
- `allocated_area`: Total area allocated in m²
- `allocation_batch_id`: Links cells to batches

**Important Behavior**:
- For update requests: `original_pledge_id` points to the original pledge, `new_pledge_id` is NULL after approval (because pending pledge is deleted)
- `original_amount` should be the amount of the original pledge BEFORE the update was applied
- `additional_amount` is the amount being added (the amount from the pending update request)
- `total_amount` = `original_amount + additional_amount`

### 4. `floor_grid_cells` Table
**Purpose**: Represents individual cells on the floor plan that can be allocated

**Key Columns**:
- `id`: Primary key
- `cell_id`: Unique identifier (e.g., 'C0505-27')
- `status`: 'available', 'pledged', 'paid', 'blocked'
- `pledge_id`: Foreign key → `pledges.id` (which pledge owns this cell)
- `payment_id`: Foreign key → `payments.id` (which payment owns this cell)
- `allocation_batch_id`: Foreign key → `grid_allocation_batches.id` (which batch allocated this cell)
- `donor_name`: Denormalized donor name
- `amount`: Amount allocated to this cell
- `assigned_date`: When cell was allocated

**Important Behavior**:
- For update requests: Cells are allocated with `pledge_id` pointing to the ORIGINAL pledge (not the deleted pending one)
- `allocation_batch_id` links cells to the batch that allocated them (for precise undo operations)

### 5. `counters` Table
**Purpose**: Global totals for the entire fundraising campaign

**Key Columns**:
- `id`: Always 1 (single row)
- `paid_total`: SUM of all approved payment amounts
- `pledged_total`: SUM of all approved pledge amounts
- `grand_total`: `paid_total + pledged_total`
- `version`: Increments on each update (for optimistic locking)

**Important Behavior**:
- For update requests: Only the ADDITIONAL amount is added to `pledged_total` (not the total amount)
- Updated atomically using `ON DUPLICATE KEY UPDATE`

### 6. `payments` Table
**Purpose**: Stores payment records

**Key Columns**:
- `id`: Primary key
- `donor_id`: Foreign key → `donors.id`
- `pledge_id`: Foreign key → `pledges.id` (which pledge this payment fulfills)
- `amount`: Payment amount
- `status`: 'pending', 'approved', 'voided'

## Relationships and Data Flow

### Update Request Flow (What Happens When Donor Updates Pledge)

1. **Donor submits update request** (`donor/update-pledge.php`):
   - Creates new `pledges` record with `status='pending'`, `source='self'`, `amount` = additional amount requested
   - Creates `grid_allocation_batches` record with:
     - `batch_type='pledge_update'`
     - `original_pledge_id` = original pledge ID
     - `new_pledge_id` = the newly created pending pledge ID
     - `original_amount` = amount of original pledge BEFORE update
     - `additional_amount` = amount being requested
     - `total_amount` = original_amount + additional_amount
     - `approval_status='pending'`

2. **Admin approves update request** (`admin/approvals/index.php`):
   - Finds original pledge using `original_pledge_id` from batch
   - Updates original pledge: `amount = amount + additional_amount` (from pending request)
   - Updates `donors` table:
     - `total_pledged = total_pledged + additional_amount` (NOT total_amount!)
     - `balance = total_pledged - total_paid`
     - `last_pledge_id` = original_pledge_id
   - Updates `counters` table:
     - `pledged_total = pledged_total + additional_amount` (NOT total_amount!)
   - Allocates grid cells with `pledge_id` = original_pledge_id and `allocation_batch_id` = batch_id
   - Updates batch: `approval_status='approved'`, stores allocated cell IDs
   - **DELETES** the pending update request pledge (it's been merged into original)

3. **Result After Approval**:
   - Original pledge amount increased
   - Donor totals updated correctly
   - Counters updated correctly
   - Grid cells allocated and linked to batch
   - Pending update request pledge deleted
   - Batch shows `new_pledge_id=NULL` (because pending pledge was deleted)

### Undo Flow (What Happens When Admin Undoes Approval)

1. **Admin clicks undo** (`admin/approved/index.php`):
   - Finds batches using `getBatchesForPledge(original_pledge_id)`
   - For each batch in reverse order:
     - Deallocates cells WHERE `allocation_batch_id = batch_id`
     - Marks batch as 'cancelled'
   - If batch is `pledge_update` type:
     - Restores original pledge amount: `amount = amount - additional_amount`
     - Updates `donors` table:
       - `total_pledged = total_pledged - additional_amount`
       - `balance = total_pledged - total_paid`
     - Updates `counters` table:
       - `pledged_total = pledged_total - additional_amount`
   - Pledge remains 'approved' (for update requests) or reverts to 'pending' (for new pledges)

## Current State Analysis (From SQL Dump)

### Pledge 71:
- `id`: 71
- `donor_id`: 180
- `donor_name`: 'Dahlak'
- `donor_phone`: '07956275687'
- `amount`: **600.00** (current state)
- `status`: 'approved'
- `source`: 'volunteer' (originally created by registrar)
- `approved_at`: '2025-08-30 08:04:39'

### Batch 12:
- `id`: 12
- `batch_type`: 'pledge_update'
- `original_pledge_id`: 71
- `new_pledge_id`: NULL (pending pledge was deleted after approval)
- `donor_id`: 180
- `original_amount`: **400.00** (correct! This was the amount BEFORE the update)
- `additional_amount`: **200.00** (the amount added)
- `total_amount`: **600.00** (400 + 200)
- `approval_status`: 'approved'
- `allocated_cell_ids`: '["C0505-27","C0505-28"]'
- `allocated_cell_count`: 2
- `allocated_area`: 0.50 m²

### What This Means:
- Pledge 71 originally: £400
- Update request added: £200
- Pledge 71 current: £600 ✅ (correct!)
- Batch 12 correctly shows: original_amount=400, additional_amount=200, total_amount=600 ✅

## Understanding Complete!

I now understand:
1. ✅ How `pledges` table stores both regular pledges and update requests
2. ✅ How `donors` table stores denormalized totals that must be manually updated
3. ✅ How `grid_allocation_batches` tracks allocation batches, especially for updates
4. ✅ How `floor_grid_cells` links to both pledges and batches
5. ✅ How `counters` table stores global totals
6. ✅ How update requests work: original pledge amount is updated, pending request is deleted
7. ✅ How undo works: uses batches to precisely deallocate cells and restore amounts
8. ✅ How all tables relate via foreign keys
9. ✅ The denormalized nature of `donors.total_pledged` and `counters.pledged_total`

**Current State**: 
- Pledge 71 = £600 ✅
- Batch 12 = correct (original_amount=400, additional_amount=200) ✅
- Undo should work correctly! ✅

