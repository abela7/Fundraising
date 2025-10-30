# Approval Flow Analysis - Safety Check

## Current Flow When Approving a Pledge

### Transaction Safety ✅
- All operations are wrapped in `begin_transaction()` / `commit()` / `rollback()`
- Operations are atomic - if any step fails, everything rolls back
- `FOR UPDATE` lock prevents concurrent modifications

---

## Flow for Regular Pledge Approval

1. ✅ **Lock pledge** (`FOR UPDATE`)
2. ✅ **Update pledge status** to 'approved'
3. ✅ **Update counters** (increment pledged_total)
4. ✅ **Allocate grid cells** (for full amount)
5. ✅ **Create audit log**
6. ✅ **Update/Find donor** (create or update donor record)
7. ✅ **Link pledge to donor** (update `pledges.donor_id`)

**Status: ✅ SAFE**

---

## Flow for Update Request Approval

### Current Issues Found:

#### ❌ Issue 1: Duplicate Update Request Detection
**Location**: Lines 87-125 and 192-274
- Update request is detected **TWICE** (before and after grid allocation)
- Second detection re-fetches original pledge (redundant query)
- Risk: If original pledge changes between detections, inconsistency

#### ⚠️ Issue 2: Audit Log for Deleted Pledge
**Location**: Lines 159-175
- Audit log is created for `$pledgeId` (the pending update request)
- Later, this pledge is DELETED (line 261-264)
- Result: Audit log references a deleted pledge
- Impact: Low (audit log still useful for history), but inconsistent

#### ⚠️ Issue 3: Status Update Before Deletion
**Location**: Line 50-53, then 261-264
- Pending request is set to 'approved' status
- Then immediately deleted
- Brief moment where it exists as 'approved' before deletion
- Risk: If grid allocator or other code checks status during this window, could cause issues

#### ⚠️ Issue 4: Grid Allocation Uses Original Pledge ID
**Location**: Lines 140-147
- Grid cells allocated using `$originalPledgeId` (correct)
- But allocation happens BEFORE original pledge amount is updated
- Original pledge amount update happens at line 222-225
- Risk: Grid allocator might calculate based on old amount

#### ✅ Issue 5: Counters Update (Actually OK)
**Location**: Lines 55-79
- Counters updated with increase amount (correct for update requests)
- Happens before update detection (also OK - amount is correct)

---

## Recommended Fixes

### Fix 1: Detect Update Request ONCE at the Start
Move all update request detection to the beginning, before any database modifications.

### Fix 2: Don't Update Status if It's an Update Request
Skip the status update for update requests (we'll delete it anyway).

### Fix 3: Update Original Pledge Amount BEFORE Grid Allocation
Update the original pledge amount first, then allocate grid cells for the new total.

### Fix 4: Update Audit Log Entity ID
For update requests, log against the original pledge ID, not the pending request ID.

---

## Safe Operation Order for Update Requests

**Correct Order:**
1. Lock pending request (`FOR UPDATE`)
2. **Detect if update request** (check source + find original)
3. **If update request:**
   - Lock original pledge (`FOR UPDATE`)
   - Update original pledge amount
   - Allocate grid cells (using original pledge ID + increase amount)
   - Update donor totals
   - Create audit log (for original pledge ID)
   - Delete pending request
   - **Skip status update** (we're deleting it anyway)
4. **If regular pledge:**
   - Update status to 'approved'
   - Allocate grid cells
   - Update/Find donor
   - Create audit log
   - Link pledge to donor

---

## Current Risk Assessment

| Risk | Severity | Impact |
|------|----------|--------|
| Duplicate detection | Low | Minor performance hit |
| Audit log inconsistency | Low | Historical records fine, just references deleted pledge |
| Status update before deletion | Medium | Could cause issues if grid allocator checks status |
| Grid allocation timing | Medium | Should allocate after original amount updated |
| Transaction safety | ✅ Safe | All wrapped in transaction |

**Overall: Functionally works, but has some logical inconsistencies that should be fixed for robustness.**

