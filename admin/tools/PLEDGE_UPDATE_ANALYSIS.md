# Pledge Update System - Comprehensive Analysis & Solutions

## Current System Understanding

### Database Structure
1. **`pledges` table**: Stores individual pledge records
   - Each pledge has: `id`, `donor_id`, `donor_phone`, `amount`, `status` (pending/approved/rejected), `type` (pledge/paid)
   - When approved, it updates `counters` table but **NOT** `donors.total_pledged`

2. **`donors` table**: Stores donor summary data
   - `total_pledged`: Denormalized sum of approved pledges (manually maintained)
   - `total_paid`: Sum of approved payments
   - `balance`: `total_pledged - total_paid`
   - `has_active_plan`: Boolean flag
   - `active_payment_plan_id`: Reference to `donor_payment_plans.id`

3. **`donor_payment_plans` table**: Stores payment schedules
   - `pledge_id`: Links to specific pledge
   - `donor_id`: Links to donor
   - `total_amount`: The amount this plan covers (should match pledge amount)
   - `monthly_amount`: Amount due each month
   - `total_months`: Duration of plan
   - `status`: active/completed/paused/defaulted/cancelled
   - `amount_paid`: Running total of payments made against this plan

4. **`counters` table**: System-wide totals
   - Updated when pledges/payments are approved
   - Used for global statistics

### Current Approval Workflow
When admin approves a pledge in `admin/approvals/index.php`:
1. Updates `pledges.status` to 'approved'
2. Updates `counters` table (increments pledged_total)
3. Allocates floor grid cells
4. **DOES NOT** update `donors.total_pledged`
5. **DOES NOT** create or update payment plans automatically

---

## Scenarios Analysis

### Scenario 1: Simple Pledge Increase (No Payment Plan)
**Situation**: Donor pledges £400, wants to add £100 more (total £500)

**Current Behavior**:
- Creates new pending pledge for £100
- When approved: Only updates counters, doesn't update `donors.total_pledged`
- Result: Donor's dashboard still shows £400, but counters show £500

**Solution**: 
- On approval, update `donors.total_pledged` by adding the new amount
- Recalculate `donors.balance`

### Scenario 2: Pledge Increase with No Active Payment Plan
**Situation**: Donor pledged £400 (no plan created yet), wants to add £100

**Current Behavior**:
- Creates new pending pledge for £100
- Admin must manually create payment plan later
- No automatic plan update

**Solution**:
- Same as Scenario 1
- Admin can create payment plan for total £500 when ready

### Scenario 3: Pledge Increase with Active Payment Plan (No Payments Made Yet)
**Situation**: 
- Donor pledged £200, plan created: £50/month for 4 months
- No payments made yet
- Donor wants to add £100 (total £300)

**Current Behavior**:
- Creates new pending pledge for £100
- Existing plan still shows £200 total
- When approved: Doesn't update existing plan

**Solution Options**:
- **Option A (Recommended)**: Update existing plan
  - Update `donor_payment_plans.total_amount` from £200 to £300
  - Recalculate `monthly_amount`:
    - If keeping same duration: £75/month for 4 months
    - If keeping same monthly: Extend to 6 months (£50/month)
  - Keep same `pledge_id` reference
  - Update `donors.total_pledged` to £300

- **Option B**: Create new plan, pause old one
  - Create new plan for £100 (2 months at £50/month)
  - Pause old plan
  - More complex for donor to track

**Recommendation**: Option A (update existing plan)

### Scenario 4: Pledge Increase with Active Payment Plan (Some Payments Made)
**Situation**:
- Donor pledged £200, plan: £50/month for 4 months
- Already paid £50 (1 payment)
- Remaining: £150 over 3 months
- Donor wants to add £100 (total £300)

**Current Behavior**:
- Creates new pending pledge for £100
- Existing plan shows £200 total, £50 paid, £150 remaining
- When approved: Doesn't update plan

**Solution**:
- Calculate remaining balance: £150 (from original plan) + £100 (new) = £250 remaining
- Update `donor_payment_plans.total_amount` to £300
- Recalculate plan:
  - **Option 1**: Increase monthly amount
    - Remaining: £250 over 3 months = £83.33/month
    - Keep same duration (3 months left)
  - **Option 2**: Extend duration
    - Keep £50/month, extend by 2 months (5 months total)
    - New schedule: 3 months at £50 + 2 months at £50
  - **Option 3**: Hybrid
    - Keep next payment at £50, then adjust remaining
    
- Update `donors.total_pledged` to £300
- Update `donors.balance` to £250

**Recommendation**: Option 1 (increase monthly, keep duration) - simpler for donor

### Scenario 5: Pledge Increase with Completed Payment Plan
**Situation**:
- Donor pledged £200, completed plan (all 4 payments made)
- Wants to add £100 more

**Current Behavior**:
- Creates new pending pledge for £100
- Old plan marked 'completed'
- When approved: Doesn't create new plan automatically

**Solution**:
- Treat as new pledge
- Update `donors.total_pledged` to £300
- Admin can create new payment plan for £100 if needed
- Or donor can pay immediately

### Scenario 6: Multiple Pledge Updates Pending
**Situation**:
- Donor submits update request for £100
- Before admin approves, submits another for £50
- Both are pending

**Current Behavior**:
- Two separate pending pledges
- Admin approves one at a time
- Could cause confusion

**Solution**:
- Keep as separate pending pledges
- Admin approves in order
- Each approval updates totals incrementally
- Consider showing warning if multiple pending updates exist

### Scenario 7: Payment Plan Already Partially Paid, New Pledge Increases Amount
**Situation**:
- Original pledge: £200, plan: £50/month for 4 months
- Paid: £100 (2 payments)
- Remaining on plan: £100 over 2 months
- New pledge: £100
- Total new commitment: £300

**Solution**:
- Update `donor_payment_plans.total_amount` to £300
- Remaining to pay: £200 (£100 original + £100 new)
- Options:
  - **Option 1**: Increase remaining payments: £100/month for 2 months
  - **Option 2**: Extend: £50/month for 4 more months (6 months total, 2 done, 4 remaining)
  
**Recommendation**: Option 1 (increase remaining payments) - cleaner

---

## Proposed Implementation Strategy

### Phase 1: Fix Approval Process to Update Donor Totals

**File**: `admin/approvals/index.php`

**Changes Needed**:
1. After approving pledge, update `donors.total_pledged`:
   ```php
   // Get donor_id from pledge
   $donor_id = $pledge['donor_id'];
   if ($donor_id) {
       // Recalculate totals from all approved pledges
       $totals_stmt = $db->prepare("
           SELECT 
               COALESCE(SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END), 0) as total_pledged
           FROM pledges
           WHERE donor_id = ?
       ");
       $totals_stmt->bind_param('i', $donor_id);
       $totals_stmt->execute();
       $totals = $totals_stmt->get_result()->fetch_assoc();
       
       // Update donor
       $update_donor = $db->prepare("
           UPDATE donors SET 
               total_pledged = ?,
               balance = total_pledged - total_paid,
               updated_at = NOW()
           WHERE id = ?
       ");
       $update_donor->bind_param('di', $totals['total_pledged'], $donor_id);
       $update_donor->execute();
   }
   ```

### Phase 2: Handle Payment Plan Updates

**When approving pledge update that affects active payment plan**:

1. **Check if donor has active plan**:
   ```php
   $active_plan = $db->prepare("
       SELECT id, total_amount, monthly_amount, total_months, 
              amount_paid, payments_made, status
       FROM donor_payment_plans
       WHERE donor_id = ? AND status = 'active'
       LIMIT 1
   ");
   ```

2. **If active plan exists**:
   - Calculate new total: `old_total + new_pledge_amount`
   - Calculate remaining: `new_total - amount_paid`
   - Update plan with new totals
   - Recalculate monthly amount or extend duration

3. **Decision Logic**:
   - If no payments made: Update monthly amount proportionally
   - If payments made: Increase remaining payments or extend duration
   - Ask admin for preference (or use donor's preferred payment day)

### Phase 3: Enhanced Approval UI

**File**: `admin/approvals/index.php`

**Add to approval form**:
- Show current donor totals
- Show active payment plan details (if exists)
- Show projected impact of approval
- Option to:
  - Update existing plan automatically
  - Create new plan
  - Leave plan unchanged (if donor wants to pay new amount separately)

---

## Recommended Approach

### For Simple Pledge Increases (No Plan):
1. ✅ Create new pending pledge record (current behavior)
2. ✅ On approval: Update `donors.total_pledged` by recalculating from all approved pledges
3. ✅ Update `donors.balance`

### For Pledge Increases with Active Plan:
1. ✅ Create new pending pledge record
2. ✅ On approval: 
   - Update `donors.total_pledged`
   - **Ask admin** (or auto-update if no payments made):
     - Update existing plan's `total_amount`
     - Recalculate `monthly_amount` or extend `total_months`
     - Update remaining payment schedule

### For Complex Cases:
- **Admin decision required**: Show impact preview in approval UI
- Allow admin to choose:
  - Update existing plan
  - Create separate plan for new amount
  - Leave as-is (donor pays separately)

---

## Database Changes Needed

### None Required! 
All necessary fields exist. We just need to:
1. Update approval logic to recalculate donor totals
2. Add payment plan update logic
3. Add admin UI for plan update decisions

---

## Implementation Priority

1. **High Priority**: Fix donor totals update on approval
2. **Medium Priority**: Auto-update payment plans when no payments made
3. **Low Priority**: Admin UI for complex plan updates

---

## Testing Scenarios

1. ✅ Approve pledge update → Check `donors.total_pledged` updated
2. ✅ Approve pledge update with active plan (no payments) → Check plan updated
3. ✅ Approve pledge update with active plan (partial payments) → Check plan recalculated
4. ✅ Multiple pending updates → Approve sequentially → Check totals correct
5. ✅ Reject pledge update → Check no changes made

---

## Questions to Clarify

1. **Should payment plan updates be automatic or require admin approval?**
   - Recommendation: Automatic if no payments made, ask admin if payments exist

2. **When recalculating plan, should we prioritize same monthly amount or same duration?**
   - Recommendation: Keep same duration, increase monthly (easier to track)

3. **Should we allow donors to specify how they want the plan updated?**
   - Future enhancement: Add option in update form

4. **What if donor has multiple active plans?**
   - Current system pauses old plan when creating new one
   - Should we allow multiple active plans?
   - Recommendation: Keep current behavior (one active plan at a time)

---

## Next Steps

1. ✅ Create this analysis document
2. ⏳ Implement Phase 1: Fix donor totals update
3. ⏳ Implement Phase 2: Payment plan update logic
4. ⏳ Implement Phase 3: Enhanced approval UI
5. ⏳ Test all scenarios
6. ⏳ Document user-facing changes

