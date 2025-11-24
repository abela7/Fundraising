# Donor Portal Make Payment - Deep Analysis

## Executive Summary

The `donor/make-payment.php` system allows logged-in donors to submit payments through their portal. **Critical Finding**: These payments are inserted into the `payments` table (instant payments), NOT the `pledge_payments` table. This creates a fundamental architectural difference from admin-recorded pledge payments.

---

## 1. Authentication & Access Control

### Entry Point
- **File**: `donor/make-payment.php`
- **Auth**: Requires donor login via `require_donor_login()`
- **Session**: Uses `$_SESSION['donor']` for donor data
- **CSRF Protection**: ✅ Uses `verify_csrf()` on form submission

### Donor Data Refresh
```php
// Lines 197-213: After payment submission, donor session is refreshed
$refresh_stmt = $db->prepare("
    SELECT id, name, phone, total_pledged, total_paid, balance, 
           has_active_plan, active_payment_plan_id, plan_monthly_amount,
           plan_duration_months, plan_start_date, plan_next_due_date,
           payment_status, preferred_payment_method, preferred_language
    FROM donors 
    WHERE id = ?
    LIMIT 1
");
```

---

## 2. Payment Amount Calculation Logic

### Default Amount Due (Lines 33-54)
```php
$amount_due = $donor['balance'] > 0 ? $donor['balance'] : 0;

// If active payment plan exists, suggest monthly amount
if ($donor['has_active_plan'] && $donor['active_payment_plan_id']) {
    $plan_stmt = $db->prepare("
        SELECT monthly_amount, next_payment_due
        FROM donor_payment_plans
        WHERE id = ? AND donor_id = ? AND status = 'active'
        LIMIT 1
    ");
    // If plan exists, use: min(monthly_amount, balance)
    $amount_due = min($plan['monthly_amount'], $donor['balance']);
}
```

**Logic Flow**:
1. Default: Use full `balance` if > 0
2. If payment plan exists: Use `min(monthly_amount, balance)`
3. UI provides buttons to set "Full Amount" or "Monthly Amount"

---

## 3. Bank Transfer Reference Generation

### Smart Reference Builder (Lines 61-133)

**Purpose**: Generate a suggested bank transfer reference combining:
- Donor's first name
- 4-digit code from pledge notes (if available)

**Process**:
1. **Extract digits from pledge notes**:
   ```php
   // Lines 74-89: Query latest approved pledge for this donor
   $ref_stmt = $db->prepare("
       SELECT notes 
       FROM pledges 
       WHERE donor_id = ? AND status = 'approved'
       ORDER BY created_at DESC, id DESC
       LIMIT 1
   ");
   ```

2. **Extract last 4 digits**:
   ```php
   $digits_only = preg_replace('/\D+/', '', $row['notes']);
   $bank_reference_digits = strlen($digits_only) >= 4
       ? substr($digits_only, -4)
       : $digits_only;
   ```

3. **Combine with first name**:
   ```php
   $name_parts = preg_split('/\s+/', trim($donor['name']));
   $reference_name_part = $name_parts[0]; // First name
   $bank_reference_label = $reference_name_part . $bank_reference_digits;
   ```

**Example**: If donor name is "John Smith" and pledge notes contain "12345", reference becomes "John1234"

**Bank Details** (Hardcoded, Lines 57-59):
- Account Name: `LMKATH`
- Account Number: `85455687`
- Sort Code: `53-70-44`

---

## 4. Payment Submission Flow

### Form Validation (Lines 135-149)
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'submit_payment') {
    verify_csrf(); // CSRF protection
    
    $payment_amount = (float)($_POST['payment_amount'] ?? 0);
    $payment_method = trim($_POST['payment_method'] ?? '');
    $reference = trim($_POST['reference'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    // Validation:
    // 1. Amount > 0
    // 2. Amount <= donor balance
    // 3. Payment method in allowed list: ['cash', 'bank_transfer', 'card', 'other']
}
```

### Database Insert (Lines 155-173)

**CRITICAL**: Payment goes to `payments` table, NOT `pledge_payments`!

```php
$insert_stmt = $db->prepare("
    INSERT INTO payments (
        donor_id, donor_name, donor_phone, 
        amount, method, reference, notes, 
        status, source, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 'donor_portal', NOW())
");
```

**Key Fields**:
- `status`: `'pending'` (requires admin approval)
- `source`: `'donor_portal'` (identifies origin)
- `donor_id`: Links to `donors` table
- **NO `pledge_id` field** - This is an instant payment, not a pledge installment!

### Audit Logging (Lines 175-190)
```php
$audit_data = json_encode([
    'payment_id' => $payment_id,
    'amount' => $payment_amount,
    'method' => $payment_method,
    'reference' => $reference,
    'donor_id' => $donor['id'],
    'donor_name' => $donor['name']
], JSON_UNESCAPED_SLASHES);

$audit_stmt = $db->prepare("
    INSERT INTO audit_logs(user_id, entity_type, entity_id, action, after_json, source) 
    VALUES(?, 'payment', ?, 'create_pending', ?, 'donor_portal')
");
$user_id = 0; // System/Donor portal (no user_id)
```

---

## 5. Approval Workflow (Admin Side)

### Where Payments Are Approved
**File**: `admin/approvals/index.php` (Lines 805-910)

### Approval Process
```php
if ($action === 'approve_payment') {
    // 1. Update payment status
    $upd = $db->prepare("UPDATE payments SET status='approved' WHERE id=?");
    
    // 2. Update counters table
    $ctr = $db->prepare("
        INSERT INTO counters (id, paid_total, pledged_total, grand_total, version, recalc_needed)
        VALUES (1, ?, 0, ?, 1, 0)
        ON DUPLICATE KEY UPDATE
          paid_total = paid_total + VALUES(paid_total),
          grand_total = grand_total + VALUES(grand_total),
          version = version + 1
    ");
    
    // 3. Allocate floor grid cells (if applicable)
    $customAllocator = new CustomAmountAllocator($db);
    $allocationResult = $customAllocator->processPaymentCustomAmount(...);
    
    // 4. Update donor totals
    $updateDonor = $db->prepare("
        UPDATE donors SET
            name = ?,
            total_paid = total_paid + ?,
            donor_type = CASE 
                WHEN total_pledged = 0 THEN 'immediate_payment'
                ELSE 'pledge'
            END,
            payment_status = CASE
                WHEN total_pledged = 0 THEN 'completed'
                WHEN (total_paid + ?) >= total_pledged THEN 'completed'
                WHEN (total_paid + ?) > 0 THEN 'paying'
                ELSE payment_status
            END,
            last_payment_date = NOW(),
            payment_count = COALESCE(payment_count, 0) + 1
        WHERE id = ?
    ");
}
```

**Key Differences from Pledge Payment Approval**:
- ✅ Updates `payments` table (not `pledge_payments`)
- ✅ Updates `donors.total_paid` directly (not using `FinancialCalculator`)
- ✅ Updates `counters.paid_total` (instant payments counter)
- ✅ Allocates floor grid cells (if applicable)
- ❌ Does NOT update `donors.balance` using pledge payment logic
- ❌ Does NOT check if pledge is fully paid

---

## 6. UI/UX Features

### Payment Form (Lines 277-451)

**Features**:
1. **Amount Input**:
   - Pre-filled with `$amount_due` (full balance or monthly amount)
   - Max validation: `max="<?php echo $donor['balance']; ?>"`
   - Quick buttons: "Pay Full Amount" or "Pay Monthly Amount"

2. **Payment Method Selection**:
   - Options: Bank Transfer, Cash, Card, Other
   - Pre-selects `preferred_payment_method` if set

3. **Bank Transfer Details Panel** (Conditional, Lines 327-406):
   - Shows when "Bank Transfer" is selected
   - Displays account name, number, sort code
   - Copy-to-clipboard buttons for each field
   - Auto-fills reference field with suggested reference

4. **Reference Number** (Optional):
   - Transaction reference, receipt number, etc.

5. **Additional Notes** (Optional):
   - Free-form text area

### Payment Summary Sidebar (Lines 458-488)
- Total Pledged: `£<?php echo number_format($donor['total_pledged'], 2); ?>`
- Total Paid: `£<?php echo number_format($donor['total_paid'], 2); ?>`
- Remaining Balance: `£<?php echo number_format($donor['balance'], 2); ?>`
- Monthly Amount (if active plan): Shows suggested monthly payment

### JavaScript Enhancements (Lines 496-575)
- **Amount Quick-Set**: `setAmount(amount)` function
- **Bank Details Toggle**: Shows/hides bank transfer panel
- **Reference Auto-Fill**: Pre-fills reference when bank transfer selected
- **Copy-to-Clipboard**: Modern clipboard API with fallback

---

## 7. Critical Architectural Differences

### Donor Portal Payments vs Admin Pledge Payments

| Aspect | Donor Portal (`make-payment.php`) | Admin Pledge Payments (`save-pledge-payment.php`) |
|--------|-----------------------------------|---------------------------------------------------|
| **Table** | `payments` | `pledge_payments` |
| **Purpose** | Instant/standalone payments | Installments toward specific pledges |
| **Status** | `pending` → `approved` | `pending` → `confirmed` |
| **Source Field** | `source='donor_portal'` | `source='admin'` (implicit) |
| **Pledge Link** | ❌ No `pledge_id` | ✅ Requires `pledge_id` |
| **Approval Updates** | Direct SQL update | Uses `FinancialCalculator` |
| **Balance Calculation** | Uses `total_paid` (includes instant + pledge payments) | Uses `total_paid - pledge_payments` only |
| **Grid Allocation** | ✅ Yes (via `CustomAmountAllocator`) | ❌ No (already allocated to pledge) |
| **Donor Totals** | Updates `total_paid` directly | Updates via `FinancialCalculator::recalculateDonorTotalsAfterApprove()` |

### Why This Matters

1. **Double-Counting Risk**: 
   - Donor portal payments go to `payments` table
   - Admin pledge payments go to `pledge_payments` table
   - Both update `donors.total_paid`, but calculation logic differs

2. **Balance Calculation**:
   - `FinancialCalculator` calculates: `balance = total_pledged - pledge_payments`
   - But donor portal payments update `total_paid` directly, which may include instant payments
   - This could cause balance inconsistencies

3. **Approval Workflow**:
   - Donor portal payments: Approved in `admin/approvals/index.php` (general payments)
   - Admin pledge payments: Approved in `admin/donations/approve-pledge-payment.php` (dedicated endpoint)

---

## 8. Potential Issues & Recommendations

### Issue 1: Payment Type Confusion
**Problem**: Donor portal creates instant payments, but donors may think they're paying toward their pledge.

**Current Behavior**: 
- If donor has balance > 0, they can submit payment
- Payment goes to `payments` table (instant payment)
- This increases `total_paid` but doesn't specifically reduce pledge balance

**Recommendation**: 
- Consider adding UI clarification: "This is an instant payment" vs "This payment goes toward your pledge"
- OR: Route donor payments to `pledge_payments` table if they have active pledges

### Issue 2: Balance Calculation Inconsistency
**Problem**: `donors.balance` is calculated as `total_pledged - total_paid`, but:
- `total_paid` includes instant payments (`payments` table)
- Pledge balance should be: `total_pledged - pledge_payments` only

**Current State**: 
- `FinancialCalculator` correctly calculates: `balance = total_pledged - pledge_payments`
- But donor portal payments update `total_paid` directly, which may not align

**Recommendation**: 
- Ensure `donors.balance` is a GENERATED column or recalculated consistently
- OR: Use `FinancialCalculator` for all balance calculations

### Issue 3: Missing Pledge Link
**Problem**: Donor portal payments don't link to specific pledges, making it unclear which pledge is being paid.

**Current Behavior**: 
- Payment is standalone, no `pledge_id`
- Admin must manually associate payment with pledge (if needed)

**Recommendation**: 
- If donor has only one active pledge, auto-link payment to that pledge
- If multiple pledges, show pledge selector in form
- Route to `pledge_payments` table instead of `payments`

### Issue 4: Approval Workflow Separation
**Problem**: Two separate approval workflows:
- `admin/approvals/index.php` for donor portal payments
- `admin/donations/approve-pledge-payment.php` for admin-recorded pledge payments

**Current State**: 
- Different endpoints, different logic
- Could lead to inconsistent handling

**Recommendation**: 
- Unify approval workflow OR clearly document the difference
- Ensure both use `FinancialCalculator` for consistency

---

## 9. Integration Points

### Files That Reference Donor Portal Payments

1. **`donor/index.php`**: 
   - Links to `make-payment.php`
   - Shows payment status and balance

2. **`admin/approvals/index.php`**: 
   - Approves pending payments from donor portal
   - Updates donor totals and counters

3. **`admin/payments/index.php`**: 
   - Lists all payments (including donor portal)
   - Filters by source if needed

4. **`api/totals.php`**: 
   - Includes `payments` table in totals
   - Should include `pledge_payments` separately

5. **`api/recent.php`**: 
   - Shows recent activity
   - Includes payments from donor portal

---

## 10. Security Considerations

### ✅ Implemented
- CSRF protection (`verify_csrf()`)
- Authentication required (`require_donor_login()`)
- Input validation (amount, method, balance check)
- SQL injection protection (prepared statements)
- Transaction rollback on errors

### ⚠️ Potential Improvements
- Rate limiting (prevent spam submissions)
- Amount validation (max per transaction)
- Payment method validation (allowed methods)
- Reference number sanitization
- File upload validation (if payment proof added)

---

## 11. Testing Scenarios

### Scenario 1: Donor with Active Pledge
**Setup**: Donor has £500 pledged, £200 paid, balance £300
**Action**: Submit payment of £100 via donor portal
**Expected**:
- Payment created in `payments` table with `status='pending'`
- `source='donor_portal'`
- After approval: `total_paid` = £300, `balance` = £200

### Scenario 2: Donor with Payment Plan
**Setup**: Donor has £1000 pledged, monthly amount £100
**Action**: Submit monthly payment via donor portal
**Expected**:
- Form suggests £100 (monthly amount)
- Payment goes to `payments` table
- After approval: `total_paid` increases by £100

### Scenario 3: Donor with No Balance
**Setup**: Donor has completed all payments (`balance = 0`)
**Action**: Access `make-payment.php`
**Expected**:
- Shows "No Payment Due" message
- Form is hidden
- No submission allowed

### Scenario 4: Payment Exceeds Balance
**Setup**: Donor has balance £50
**Action**: Try to submit £100
**Expected**:
- Validation error: "Payment amount cannot exceed your remaining balance"
- Form submission blocked

---

## 12. Summary & Key Takeaways

### What Works Well
✅ Clean, user-friendly UI with helpful features (bank details, copy buttons)  
✅ Proper authentication and CSRF protection  
✅ Smart reference generation from pledge notes  
✅ Payment plan integration (suggests monthly amount)  
✅ Comprehensive audit logging  

### Critical Findings
⚠️ **Donor portal payments go to `payments` table, NOT `pledge_payments`**  
⚠️ **This creates architectural inconsistency with admin pledge payment system**  
⚠️ **Balance calculations may differ between the two systems**  
⚠️ **No direct link between donor portal payments and specific pledges**  

### Recommendations
1. **Consider routing donor payments to `pledge_payments`** if donor has active pledges
2. **Unify approval workflows** or document differences clearly
3. **Use `FinancialCalculator` consistently** for all balance calculations
4. **Add pledge selector** in donor portal if multiple active pledges exist
5. **Clarify payment type** in UI (instant vs pledge installment)

---

## 13. Code References

### Key Files
- **`donor/make-payment.php`**: Main payment submission form (578 lines)
- **`admin/approvals/index.php`**: Payment approval workflow (Lines 805-910)
- **`shared/FinancialCalculator.php`**: Centralized financial calculations
- **`admin/donations/save-pledge-payment.php`**: Admin pledge payment recording
- **`admin/donations/approve-pledge-payment.php`**: Admin pledge payment approval

### Database Tables
- **`payments`**: Instant/standalone payments (donor portal uses this)
- **`pledge_payments`**: Installments toward pledges (admin system uses this)
- **`donors`**: Donor registry with denormalized totals
- **`audit_logs`**: All payment actions logged here

---

**Document Version**: 1.0  
**Last Updated**: 2025-01-XX  
**Author**: System Analysis

