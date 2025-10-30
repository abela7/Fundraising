# Donor Update Scenarios - Comprehensive Analysis

## Database Relationship Overview

### Tables Structure:
1. **`donors`** table (Central donor registry)
   - `id` (PK, AUTO_INCREMENT)
   - `phone` (UNIQUE) - Used for matching
   - `total_pledged` - Sum of all approved pledges
   - `total_paid` - Sum of all approved payments
   - `balance` - Calculated: `total_pledged - total_paid`
   - `donor_type` - `'pledge'` or `'immediate_payment'`
   - `payment_status` - `'no_pledge'`, `'not_started'`, `'paying'`, `'completed'`, etc.
   - `has_active_plan`, `active_payment_plan_id` - Payment plan tracking

2. **`pledges`** table (Individual pledge records)
   - `id` (PK)
   - `donor_id` (FK → `donors.id`) - **Must be linked!**
   - `donor_phone` - Used for matching
   - `donor_name` - Denormalized
   - `amount`, `type` (`'pledge'` or `'paid'`), `status`

3. **`payments`** table (Individual payment records)
   - `id` (PK)
   - `donor_id` (FK → `donors.id`) - **Must be linked!**
   - `donor_phone` - Used for matching
   - `donor_name` - Denormalized
   - `amount`, `status`

---

## ✅ All Scenarios Handled

### Scenario 1: New Donor - Pledge Approval
**Situation**: Donor submits pledge request, never existed before
- ✅ Find donor by phone → Not found
- ✅ Create new donor record in `donors` table
- ✅ Set `donor_type = 'pledge'`
- ✅ Set `total_pledged = amount`
- ✅ Set `payment_status = 'not_started'`
- ✅ Set `last_pledge_id = pledge_id`
- ✅ Set `pledge_count = 1`
- ✅ **Link pledge**: `UPDATE pledges SET donor_id = donor_id WHERE id = pledge_id`

### Scenario 2: Existing Donor - New Pledge (No Payments Yet)
**Situation**: Donor already has pledge(s), wants to increase
- ✅ Find donor by phone → Found
- ✅ Update `total_pledged = total_pledged + new_amount`
- ✅ Recalculate `balance = total_pledged - total_paid`
- ✅ Update `payment_status`:
  - If `total_paid = 0` → `'not_started'`
  - If `total_paid >= new_total` → `'completed'` (overpaid)
  - If `total_paid > 0` → `'paying'`
- ✅ Update `last_pledge_id = new_pledge_id`
- ✅ Increment `pledge_count`
- ✅ **Link pledge**: `UPDATE pledges SET donor_id = donor_id WHERE id = pledge_id`

### Scenario 3: Existing Donor - New Pledge (Has Active Payment Plan)
**Situation**: Donor has active payment plan, submits pledge update
- ✅ Same as Scenario 2 (update totals)
- ⚠️ **Note**: Payment plan remains active but totals updated
- ✅ `has_active_plan` flag stays `TRUE`
- ✅ `active_payment_plan_id` unchanged
- ✅ Payment plan totals need manual update (future enhancement)

### Scenario 4: Existing Donor - New Pledge (Previously Completed)
**Situation**: Donor completed previous pledge (`payment_status = 'completed'`), adds new pledge
- ✅ Find donor → Found
- ✅ Update `total_pledged = total_pledged + new_amount`
- ✅ Recalculate `balance` (will be positive again)
- ✅ Update `payment_status`:
  - If old payments < new total → `'paying'` (new balance)
  - If old payments >= new total → `'completed'` (still overpaid)
- ✅ Update `last_pledge_id`
- ✅ Increment `pledge_count`

### Scenario 5: Existing Donor - New Pledge (Currently Paying)
**Situation**: Donor is paying off existing pledge (`payment_status = 'paying'`), adds new pledge
- ✅ Find donor → Found
- ✅ Update `total_pledged = total_pledged + new_amount`
- ✅ Recalculate `balance` (increases)
- ✅ Update `payment_status`:
  - If `total_paid >= new_total` → `'completed'`
  - Else → `'paying'` (continues paying)
- ✅ Update `last_pledge_id`
- ✅ Increment `pledge_count`

### Scenario 6: New Donor - Payment Approval (`type = 'paid'`)
**Situation**: Donor pays immediately, never existed before
- ✅ Find donor by phone → Not found
- ✅ Create new donor record
- ✅ Set `donor_type = 'immediate_payment'`
- ✅ Set `total_paid = amount`
- ✅ Set `balance = 0`
- ✅ Set `payment_status = 'completed'`
- ✅ Set `last_payment_date = NOW()`
- ✅ Set `payment_count = 1`
- ✅ **Link pledge**: `UPDATE pledges SET donor_id = donor_id WHERE id = pledge_id`

### Scenario 7: Existing Donor - Payment Approval (No Pledges)
**Situation**: Donor paid before, pays again, has no pledges
- ✅ Find donor → Found (`donor_type = 'immediate_payment'`)
- ✅ Update `total_paid = total_paid + amount`
- ✅ Keep `donor_type = 'immediate_payment'`
- ✅ Keep `payment_status = 'completed'`
- ✅ Update `last_payment_date`
- ✅ Increment `payment_count`
- ✅ **Link payment**: `UPDATE payments SET donor_id = donor_id WHERE id = payment_id`

### Scenario 8: Existing Donor - Payment Approval (Has Pledges)
**Situation**: Donor has pledges, makes a payment
- ✅ Find donor → Found (`donor_type = 'pledge'`)
- ✅ Update `total_paid = total_paid + amount`
- ✅ Keep `donor_type = 'pledge'` (has pledges, needs tracking)
- ✅ Update `payment_status`:
  - If `total_paid >= total_pledged` → `'completed'`
  - Else → `'paying'`
- ✅ Recalculate `balance = total_pledged - total_paid`
- ✅ Update `last_payment_date`
- ✅ Increment `payment_count`
- ✅ **Link payment**: `UPDATE payments SET donor_id = donor_id WHERE id = payment_id`

### Scenario 9: Repeat Donor - Multiple Pledges/Payments
**Situation**: Same donor (same phone) submits multiple times
- ✅ Always finds same donor record (by phone)
- ✅ Updates same donor record (no duplicates)
- ✅ Accumulates totals correctly
- ✅ Updates counts correctly
- ✅ Links all pledges/payments to same `donor_id`

---

## 🔧 Implementation Details

### Phone Number Matching Logic:
```php
// Normalize phone number
$normalized_phone = preg_replace('/[^0-9]/', '', $donorPhone);
if (substr($normalized_phone, 0, 2) === '44' && strlen($normalized_phone) === 12) {
    $normalized_phone = '0' . substr($normalized_phone, 2);
}

// Find by normalized phone (UNIQUE constraint ensures one donor per phone)
$findDonor = $db->prepare("SELECT id FROM donors WHERE phone = ? LIMIT 1");
```

### Donor ID Linking:
```php
// After finding/creating donor, link the pledge/payment
if ($donorId) {
    $linkPledge = $db->prepare("UPDATE pledges SET donor_id = ? WHERE id = ?");
    $linkPledge->bind_param('ii', $donorId, $pledgeId);
    $linkPledge->execute();
}
```

### Payment Status Logic:
```sql
CASE
    WHEN total_pledged = 0 THEN 'completed'  -- Pure immediate payer
    WHEN total_paid >= total_pledged THEN 'completed'  -- Fully paid
    WHEN total_paid > 0 THEN 'paying'  -- In progress
    ELSE 'not_started'  -- Has pledge but no payments yet
END
```

---

## ✅ Verification Checklist

- [x] New donor created correctly
- [x] Existing donor found by phone (no duplicates)
- [x] `donor_id` linked in `pledges` table
- [x] `donor_id` linked in `payments` table
- [x] `total_pledged` updated correctly
- [x] `total_paid` updated correctly
- [x] `balance` calculated correctly
- [x] `payment_status` updated for all scenarios
- [x] `donor_type` set correctly (`'pledge'` vs `'immediate_payment'`)
- [x] `pledge_count` and `payment_count` incremented
- [x] `last_pledge_id` and `last_payment_date` updated
- [x] Grid allocation unchanged (still allocates new cells)

---

## ⚠️ Known Limitations (Future Enhancements)

1. **Payment Plans**: When approving pledge updates for donors with active payment plans, the plan totals are not automatically recalculated. This requires manual admin intervention or a future enhancement.

2. **Achievement Badges**: Achievement badges (`'pending'`, `'started'`, `'on_track'`, etc.) are not automatically recalculated. They remain as-is or need manual update.

3. **Overdue Status**: The `'overdue'` and `'defaulted'` payment statuses are not automatically set. They require additional logic based on payment plan due dates.

---

## 🎯 Summary

**The implementation correctly handles:**
- ✅ All donor scenarios (new, existing, repeat)
- ✅ Proper phone number matching (no duplicates)
- ✅ Foreign key linking (`pledges.donor_id`, `payments.donor_id`)
- ✅ Financial totals updates
- ✅ Payment status calculations
- ✅ Donor type assignments
- ✅ Grid allocation (unchanged, still works)

**The system ensures:**
- One donor record per phone number
- All pledges/payments linked to correct donor
- Accurate financial tracking
- Proper status management

