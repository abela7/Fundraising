# Payment Plan View System

## Overview
A comprehensive payment plan viewing and management system for individual donor payment plans.

## Files Created

### 1. **view-payment-plan.php**
The main view page for individual payment plans.

**URL Format:** `admin/donor-management/view-payment-plan.php?id={plan_id}`

**Features:**
- Complete plan overview with statistics
- Progress bar showing completion percentage
- Payment history timeline
- Donor, church, and representative information
- Edit, pause/resume, and delete functionality

**Sections:**
- **Plan Header**: Donor name, contact info, and status badge
- **Plan Statistics**: Total amount, paid amount, remaining balance, payments made/total
- **Plan Details**: Monthly amount, duration, frequency, payment day, method, dates
- **Payment History**: Timeline of all payments with details
- **Sidebar**: Template info, representative, church, pledge details, timestamps

### 2. **update-payment-plan.php**
Backend handler for editing payment plans via the modal form.

**Updates:**
- Installment amount (monthly_amount)
- Total payments
- Payment frequency (unit and number)
- Payment day
- Payment method
- Status
- Next payment due date

**Features:**
- Full validation
- Transaction support
- Audit logging
- Automatic recalculation of total_months
- Updates donor status when plan is completed/cancelled

### 3. **update-payment-plan-status.php**
Quick status change handler (pause/resume functionality).

**Supported Status Changes:**
- Active ↔ Paused
- Active → Completed/Cancelled/Defaulted

**Features:**
- Transaction support
- Audit logging
- Automatic donor status sync

### 4. **verify-payment-plan-columns.sql**
SQL verification script to check if all required columns exist.

**Usage:**
```sql
-- Run in phpMyAdmin to check column status
-- Shows all payment plan columns with types and comments
```

### 5. **add-missing-payment-plan-columns.sql**
SQL script to add any missing columns to the donor_payment_plans table.

**Adds:**
- `plan_frequency_unit` (week, month, year)
- `plan_frequency_number` (1, 2, 3, etc.)
- `plan_payment_day_type` (day_of_month, day_of_week)
- `total_payments` (number of installments)

## Database Integration

### Required Columns
The system uses these columns from `donor_payment_plans`:
- **Core**: id, donor_id, pledge_id, template_id
- **Amounts**: total_amount, monthly_amount, amount_paid
- **Schedule**: total_months, total_payments, payments_made
- **Frequency**: plan_frequency_unit, plan_frequency_number, plan_payment_day_type
- **Dates**: start_date, payment_day, next_payment_due, last_payment_date
- **Status**: status, payment_method
- **Timestamps**: created_at, updated_at, reminder_sent_at

### Related Tables
- **donors**: Donor information, church, representative
- **pledges**: Original pledge details
- **payments**: Payment history
- **payment_plan_templates**: Template information
- **church_representatives**: Representative details
- **churches**: Church information
- **donor_audit_log**: Change history

## User Interface

### Status Badges
- 🟢 **Active** (Green) - Plan is currently active
- 🔵 **Completed** (Blue) - All payments made
- 🟡 **Paused** (Yellow) - Temporarily suspended
- 🔴 **Defaulted** (Red) - Payments missed/failed
- ⚫ **Cancelled** (Gray) - Plan cancelled

### Action Buttons
- **View Donor Profile** - Navigate to full donor page
- **Edit Plan** - Open edit modal
- **Pause/Resume Plan** - Quick status change
- **Delete Plan** - Remove plan (with confirmation)

### Edit Modal Features
- ⚠️ Warning about recalculation impacts
- Installment amount with currency symbol
- Total payments counter
- Frequency selection (weekly, monthly, yearly)
- Frequency multiplier (e.g., 2 for biweekly)
- Payment day picker (1-28)
- Payment method dropdown
- Status selector
- Next payment due date picker

## Frequency System

### How It Works
Payment frequency is defined by **two fields**:
1. **plan_frequency_unit**: week, month, or year
2. **plan_frequency_number**: multiplier (1-12)

### Examples
| Unit | Number | Result |
|------|--------|--------|
| week | 1 | Weekly |
| week | 2 | Biweekly (every 2 weeks) |
| month | 1 | Monthly |
| month | 3 | Quarterly (every 3 months) |
| year | 1 | Annually |

### Automatic Calculations
When updating frequency, `total_months` is automatically recalculated:
- **Weekly**: `ceil((total_payments * frequency_number) / 4.33)`
- **Monthly**: `total_payments * frequency_number`
- **Yearly**: `total_payments * 12 * frequency_number`

## Security Features
- ✅ Admin authentication required
- ✅ Input validation and sanitization
- ✅ Transaction support (rollback on error)
- ✅ Prepared statements (SQL injection prevention)
- ✅ Audit logging of all changes
- ✅ Confirmation dialogs for destructive actions

## Usage Examples

### Viewing a Plan
```
Navigate to: admin/donor-management/view-payment-plan.php?id=11
```

### Editing a Plan
1. Click "Edit Plan" button
2. Modify fields in the modal
3. Click "Save Changes"
4. System validates and updates
5. Redirects back to view page

### Pausing a Plan
1. Click "Pause Plan" button
2. Confirm the action
3. Status changes to "paused"
4. Donor status updated accordingly

### Resuming a Plan
1. Click "Resume Plan" button (when paused)
2. Confirm the action
3. Status changes to "active"
4. Donor status updated to "paying"

## Integration Points

### From Call Center
After completing a call in `admin/call-center/conversation.php`:
- Payment plan is created in `process-conversation.php`
- User can click "View Payment Plan" to see details
- Plan ID is stored in `donor_payment_plans` table

### From Donor Management
In `admin/donor-management/view-donor.php`:
- Click on payment plan details
- Links to `view-payment-plan.php?id={plan_id}`

### From Payment Plans List
In `admin/donor-management/donors.php`:
- Click on any payment plan reference
- Opens detailed view page

## Error Handling

### Common Issues
1. **Plan not found**: Redirects to donors list with error message
2. **Invalid input**: Shows validation error and highlights field
3. **Database error**: Rolls back transaction and logs error
4. **Permission denied**: Requires admin authentication

### Logging
All errors are logged to PHP error log:
```php
error_log("Update payment plan error: " . $e->getMessage());
```

## Future Enhancements
- 📊 Payment schedule generator/calendar view
- 📧 Send payment reminders directly from this page
- 📈 Payment projection graphs
- 💰 Partial payment recording
- 📄 Export plan as PDF
- 📱 SMS notifications toggle

## Notes
- Changing frequency does NOT automatically reschedule existing payments
- Status changes sync with donor payment_status
- All updates are logged in donor_audit_log
- Payment day is limited to 1-28 to avoid month-end issues
- Representative and church info require proper column setup

## Database Maintenance

### Before First Use
Run these SQL scripts in order:
1. `verify-payment-plan-columns.sql` - Check current state
2. `add-missing-payment-plan-columns.sql` - Add any missing columns

### Expected Result
After running scripts, you should see:
```
✅ plan_frequency_unit: ENUM('week', 'month', 'year')
✅ plan_frequency_number: INT
✅ plan_payment_day_type: ENUM('day_of_month', 'day_of_week')
✅ total_payments: INT
```

## Support
For issues or questions, check:
- PHP error logs for backend errors
- Browser console for JavaScript errors
- Database structure using verification script

