# Repeat Donor Feature - Implementation Summary

## Overview
Implemented a smart repeat donor detection system that allows registrars to record multiple donations from the same donor without triggering duplicate phone number restrictions.

## Features

### 1. Donor History Detection
- When a phone number is entered that already has donations, the system detects it
- Shows a modal with the donor's name and previous donations (last 5 records)
- Displays donation type (pledge or payment), amount, status, and date

### 2. Additional Donation Checkbox
- A visible checkbox appears when a repeat donor is detected
- Label: "This donor wants to make another donation"
- Registrar must explicitly check this box to proceed
- Prevents accidental duplicate entries

### 3. Flexible Donation Patterns
Now supports these workflows:
- Pledge £400, then pay £200 (partial fulfillment)
- Pledge £400, then pledge £200 more (additional commitment)
- Pay £200 today, then pay £200 more later
- Any combination of pledges and payments from same donor

## Technical Changes

### Database
- No schema changes required
- All donations still tracked separately in `pledges` and `payments` tables
- Admin can view full history and manage relationships

### API Enhancement (api/check_donor.php)
- Now returns additional fields:
  - `donor_name`: Most recent name on file
  - `payments`: Count and status of payments (pending/approved)
  - `recent_donations`: Array of last 5 donations with:
    - amount
    - type (pledge or payment)
    - date
    - status

### Frontend (registrar/index.php)
- Added modal HTML with:
  - Donor info display
  - Recent donations table
  - Clear instructions
- Added "Additional Donation" checkbox (hidden by default)

### JavaScript Logic (registrar/assets/registrar.js)
- Enhanced form submission handler:
  1. Validates phone number format
  2. Calls API to check for existing donations
  3. If found:
     - Shows modal with donation history
     - Reveals "Additional Donation" checkbox
     - Blocks submission until checkbox is checked
  4. If not found:
     - Hides checkbox
     - Allows submission normally

### Backend Logic (registrar/index.php POST handler)
- Added `$additional_donation` flag from POST
- Modified duplicate checks (both pledge and payment paths):
  - Old: Always blocked if duplicate phone found
  - New: Only blocks if `$additional_donation` is not set to '1'
- If flag is set, duplicate check is bypassed and donation is recorded

## Workflow Example

**Scenario: Abebe pledges £400 on Day 1, wants to pay £200 on Day 2**

### Day 1 (Pledge)
1. Registrar enters: Name "Abebe", Phone "07700123456", Amount £400, Type "Pledge"
2. System: No previous donations → Checkbox hidden → Submission allowed
3. Result: Pledge recorded with status "pending" for approval

### Day 2 (Payment)
1. Registrar enters: Name "Abebe", Phone "07700123456", Amount £200, Type "Paid"
2. System: Detects previous pledge → Shows modal with pledge details
3. Modal displays: "Abebe", "07700123456", Previous donation: "Pledge £400 Pending"
4. Checkbox appears: "This donor wants to make another donation"
5. Registrar checks the box
6. System: Submission allowed → Payment recorded with status "pending"
7. Result: Both donations stored separately, can be managed by admin

## Security & Safety

✅ Backwards compatible (doesn't affect public donation form)
✅ No database schema changes
✅ All transactions still use proper rollback on error
✅ Audit logging captures both donations separately
✅ Prevents accidental duplicate registrations (checkbox required)
✅ Admin panel can later link or manage related donations
✅ All validation still enforced (phone format, amounts, etc.)
✅ Rate limiting and bot detection unchanged

## Admin Management

Admin can now:
- See complete donation history for each phone number
- View both pledges and payments in one screen
- Merge or link related donations if needed
- Generate reports showing full donor profiles
- Manage payment fulfillment across multiple entries

## Testing Checklist

- [ ] Test first pledge entry (no checkbox shown)
- [ ] Test repeat phone on payment (modal shows, checkbox appears)
- [ ] Test without checking box (submission blocked)
- [ ] Test with checkbox checked (submission succeeds)
- [ ] Verify both pledges recorded in database
- [ ] Check audit log shows both entries
- [ ] Test admin can view both donations
- [ ] Test reports show accurate totals
