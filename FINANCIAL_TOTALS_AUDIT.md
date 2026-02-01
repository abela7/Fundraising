# Financial Totals Update Audit Report
**Date:** 2025-01-27  
**Purpose:** Identify ALL pages that add, view, edit, or update payment/pledge amounts and donor totals

---

## ✅ FILES USING FinancialCalculator (CORRECT)

### 1. **admin/donations/approve-pledge-payment.php**
- **Action:** Approves pending pledge payments
- **Method:** `FinancialCalculator::recalculateDonorTotalsAfterApprove()`
- **Lines:** 57-70
- **Status:** ✅ CORRECT - Uses centralized method

### 2. **admin/donations/undo-pledge-payment.php**
- **Action:** Reverses approved pledge payments
- **Method:** `FinancialCalculator::recalculateDonorTotalsAfterUndo()`
- **Lines:** 62-70
- **Status:** ✅ CORRECT - Uses centralized method

### 3. **admin/dashboard/index.php**
- **Action:** Displays totals (READ ONLY)
- **Method:** `FinancialCalculator::getTotals()`
- **Lines:** 22-35
- **Status:** ✅ CORRECT - Read-only, uses calculator

### 4. **admin/reports/index.php**
- **Action:** Displays totals (READ ONLY)
- **Method:** `FinancialCalculator::getTotals()`
- **Lines:** 14-22
- **Status:** ✅ CORRECT - Read-only, uses calculator

### 5. **admin/reports/comprehensive.php**
- **Action:** Displays totals (READ ONLY)
- **Method:** `FinancialCalculator::getTotals()` or `getTotals($from, $to)`
- **Lines:** 125-150
- **Status:** ✅ CORRECT - Read-only, uses calculator

### 6. **admin/reports/visual.php**
- **Action:** Displays totals (READ ONLY)
- **Method:** `FinancialCalculator::getTotals()` or `getTotals($from, $to)`
- **Lines:** 59-80
- **Status:** ✅ CORRECT - Read-only, uses calculator

### 7. **api/totals.php**
- **Action:** API endpoint for projector (READ ONLY)
- **Method:** `FinancialCalculator::getTotals()`
- **Lines:** 7-30
- **Status:** ✅ CORRECT - Read-only, uses calculator

---

## ❌ FILES USING DIRECT SQL UPDATES (NEEDS FIXING)

### 1. **admin/approvals/index.php** ⚠️ CRITICAL
**Location:** Lines 500-638 (Pledge Approval), Lines 566-910 (Payment Approval)

**Pledge Approval (Lines 500-638):**
```php
UPDATE donors SET
    total_pledged = total_pledged + ?,
    payment_status = CASE
        WHEN total_paid = 0 THEN 'not_started'
        WHEN total_paid >= (total_pledged + ?) THEN 'completed'
        WHEN total_paid > 0 THEN 'paying'
        ELSE 'not_started'
    END
WHERE id = ?
```

**Payment Approval (Lines 566-910):**
```php
UPDATE donors SET
    total_paid = total_paid + ?,
    payment_status = CASE
        WHEN total_pledged = 0 THEN 'completed'
        WHEN (total_paid + ?) >= total_pledged THEN 'completed'
        WHEN (total_paid + ?) > 0 THEN 'paying'
        ELSE payment_status
    END
WHERE id = ?
```

**Issues:**
- ❌ Directly updates `total_paid` and `total_pledged` without considering `pledge_payments` table
- ❌ Payment status logic doesn't account for `pledge_payments`
- ❌ Balance calculation assumes `balance = total_pledged - total_paid` (ignores `pledge_payments`)

**Fix Required:**
- Replace with `FinancialCalculator::recalculateDonorTotalsAfterApprove()` after approval
- For pledges: Update `total_pledged` manually, then recalculate totals
- For payments: Use calculator to recalculate (handles both instant payments and pledge payments)

---

## ✅ FILES THAT DON'T UPDATE TOTALS (CORRECT)

### 1. **admin/donations/save-pledge-payment.php**
- **Action:** Creates pending pledge payment
- **Lines:** 92-103
- **Status:** ✅ CORRECT - Does NOT update totals (payment is pending)
- **Note:** Totals updated only when approved via `approve-pledge-payment.php`

### 2. **admin/donations/void-pledge-payment.php**
- **Action:** Voids pending payment (before approval)
- **Status:** ✅ CORRECT - No totals to update (payment never confirmed)

### 3. **admin/call-center/process-conversation.php**
- **Action:** Creates payment plan (doesn't create payments)
- **Status:** ✅ CORRECT - Only creates plan, doesn't update totals

### 4. **admin/donor-management/add-donor.php**
- **Action:** Creates donor with initial payment/pledge
- **Status:** ⚠️ NEEDS REVIEW - May update totals directly

### 5. **admin/donations/index.php**
- **Action:** Creates/edits payments and pledges
- **Status:** ⚠️ NEEDS REVIEW - May update totals directly

### 6. **admin/payments/index.php**
- **Action:** Creates payments
- **Status:** ⚠️ NEEDS REVIEW - May update totals directly

### 7. **admin/payments/cash.php**
- **Action:** Bulk approves cash payments
- **Status:** ⚠️ NEEDS REVIEW - May update totals directly

### 8. **registrar/index.php**
- **Action:** Creates payments/pledges from registrar interface
- **Status:** ⚠️ NEEDS REVIEW - May update totals directly

### 9. **public/donate/index.php**
- **Action:** Public donation form
- **Status:** ⚠️ NEEDS REVIEW - May update totals directly

### 10. **donor/make-payment.php**
- **Action:** Donor self-service payment
- **Status:** ⚠️ NEEDS REVIEW - May update totals directly

---

## 📋 DETAILED LINE-BY-LINE AUDIT

### **admin/approvals/index.php** - CRITICAL ISSUES

#### **Pledge Approval (Lines 500-519)**
```php
500: UPDATE donors SET
501:     name = ?,
502:     total_pledged = total_pledged + ?,  // ❌ Direct update
503:     donor_type = 'pledge',
504:     payment_status = CASE
505:         WHEN total_paid = 0 THEN 'not_started'
506:         WHEN total_paid >= (total_pledged + ?) THEN 'completed'
507:         WHEN total_paid > 0 THEN 'paying'
508:         ELSE 'not_started'
509:     END,
510:     last_pledge_id = ?,
511:     updated_at = NOW()
512: WHERE id = ?
```
**Problem:** Updates `total_pledged` directly. Should use FinancialCalculator after approval.

#### **Payment Approval (Lines 566-590)**
```php
566: UPDATE donors SET
567:     name = ?,
568:     total_paid = total_paid + ?,  // ❌ Direct update - ignores pledge_payments!
569:     donor_type = CASE 
570:         WHEN total_pledged = 0 THEN 'immediate_payment'
571:         ELSE 'pledge'
572:     END,
573:     payment_status = CASE
574:         WHEN total_pledged = 0 THEN 'completed'
575:         WHEN (total_paid + ?) >= total_pledged THEN 'completed'
576:         WHEN (total_paid + ?) > 0 THEN 'paying'
577:         ELSE payment_status
578:     END,
579:     last_payment_date = NOW(),
580:     payment_count = COALESCE(payment_count, 0) + 1,
581:     updated_at = NOW()
582: WHERE id = ?
```
**Problem:** Updates `total_paid` directly. This is WRONG because:
- `total_paid` should include BOTH `payments` AND `pledge_payments`
- Payment status logic doesn't account for `pledge_payments`
- Balance calculation will be wrong

#### **Payment Approval (Lines 886-910)**
```php
886: UPDATE donors SET
887:     name = ?,
888:     total_paid = total_paid + ?,  // ❌ Same issue as above
889:     ...
```
**Problem:** Same as above - direct update without considering `pledge_payments`.

---

## 🔧 REQUIRED FIXES

### **Priority 1: admin/approvals/index.php**

**After approving a PLEDGE:**
```php
// Current (WRONG):
UPDATE donors SET total_pledged = total_pledged + ? WHERE id = ?

// Should be:
// 1. Update pledge status
UPDATE pledges SET status='approved' WHERE id=?
// 2. Update total_pledged manually (this is OK for pledges)
UPDATE donors SET total_pledged = total_pledged + ? WHERE id = ?
// 3. Recalculate totals using FinancialCalculator
require_once __DIR__ . '/../../shared/FinancialCalculator.php';
$calculator = new FinancialCalculator();
$calculator->recalculateDonorTotals($donor_id);
```

**After approving a PAYMENT:**
```php
// Current (WRONG):
UPDATE donors SET total_paid = total_paid + ? WHERE id = ?

// Should be:
// 1. Update payment status
UPDATE payments SET status='approved' WHERE id=?
// 2. Recalculate totals using FinancialCalculator (handles both payments and pledge_payments)
require_once __DIR__ . '/../../shared/FinancialCalculator.php';
$calculator = new FinancialCalculator();
$calculator->recalculateDonorTotalsAfterApprove($donor_id);
```

---

## 📊 SUMMARY TABLE

| File | Action | Updates Totals? | Uses FinancialCalculator? | Status |
|------|--------|-----------------|---------------------------|--------|
| `approve-pledge-payment.php` | Approve pledge payment | ✅ Yes | ✅ Yes | ✅ CORRECT |
| `undo-pledge-payment.php` | Undo pledge payment | ✅ Yes | ✅ Yes | ✅ CORRECT |
| `save-pledge-payment.php` | Create pending payment | ❌ No | ❌ N/A | ✅ CORRECT |
| `void-pledge-payment.php` | Void pending payment | ❌ No | ❌ N/A | ✅ CORRECT |
| `approvals/index.php` | Approve pledge | ✅ Yes | ❌ No | ❌ **NEEDS FIX** |
| `approvals/index.php` | Approve payment | ✅ Yes | ❌ No | ❌ **NEEDS FIX** |
| `dashboard/index.php` | Display totals | ❌ No (read) | ✅ Yes | ✅ CORRECT |
| `reports/index.php` | Display totals | ❌ No (read) | ✅ Yes | ✅ CORRECT |
| `reports/comprehensive.php` | Display totals | ❌ No (read) | ✅ Yes | ✅ CORRECT |
| `reports/visual.php` | Display totals | ❌ No (read) | ✅ Yes | ✅ CORRECT |
| `api/totals.php` | API endpoint | ❌ No (read) | ✅ Yes | ✅ CORRECT |

---

## 🎯 ACTION ITEMS

1. **CRITICAL:** Fix `admin/approvals/index.php` to use `FinancialCalculator` for payment approvals
2. **CRITICAL:** Fix `admin/approvals/index.php` to use `FinancialCalculator` for pledge approvals (after updating total_pledged)
3. **REVIEW:** Check other files that create payments/pledges to ensure they don't update totals directly
4. **VERIFY:** Ensure all payment/pledge creation flows go through approval workflow

---

## 🔍 FILES TO REVIEW NEXT

These files create payments/pledges but may not update totals (which is correct if they're pending):
- `admin/donor-management/add-donor.php`
- `admin/donations/index.php`
- `admin/payments/index.php`
- `admin/payments/cash.php`
- `registrar/index.php`
- `public/donate/index.php`
- `donor/make-payment.php`

**Need to verify:** Do these files update donor totals directly, or do they create pending records that require approval?

