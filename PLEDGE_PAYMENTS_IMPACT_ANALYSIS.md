# Pledge Payments System - Impact Analysis

## Summary
The new `pledge_payments` table has been created to track payments made towards pledges separately from instant donations. This document outlines all affected pages and required updates.

---

## ✅ **Already Updated (No Action Needed)**

### 1. **API Endpoints (Projector)**
- **`api/totals.php`** ✅ 
  - Updated to include `pledge_payments` in Total Paid calculation
  - Correctly prevents double-counting
  - Formula: `Grand Total = Total Pledged + Instant Payments`

- **`api/recent.php`** ✅
  - Updated to show pledge payments in the live feed
  - Uses `UNION ALL` to include pledge_payments alongside instant payments and pledges

### 2. **Recording System**
- **`admin/donations/record-pledge-payment.php`** ✅ (New UI)
- **`admin/donations/save-pledge-payment.php`** ✅ (New backend logic)
- **`admin/donations/get-donor-pledges.php`** ✅ (New AJAX helper)

### 3. **Donor Profile**
- **`admin/donor-management/donors.php`** - Uses `donors.total_paid` and `donors.balance` which are auto-updated ✅
- **`admin/donor-management/view-donor.php`** - Shows donor totals from `donors` table ✅

---

## ⚠️ **Needs Review & Potential Updates**

### 1. **`admin/reports/index.php`**

**Current Logic (Lines 14-19):**
```php
$paidRow = $db->query("SELECT COALESCE(SUM(amount),0) AS t FROM payments WHERE status='approved'")->fetch_assoc() ?: ['t'=>0];
$pledgeRow = $db->query("SELECT COALESCE(SUM(amount),0) AS t FROM pledges WHERE status='approved'")->fetch_assoc() ?: ['t'=>0];
$paidTotal    = (float)$paidRow['t'];
$pledgedTotal = (float)$pledgeRow['t'];
$grandTotal   = $paidTotal + $pledgedTotal;
```

**Issue:**
- `$paidTotal` only includes instant payments from `payments` table
- Pledge installments (`pledge_payments`) are ignored
- This means "Total Paid" is UNDERSTATED on the main reports page

**Fix Needed:**
```php
// 1. Instant Payments
$instRow = $db->query("SELECT COALESCE(SUM(amount),0) AS t FROM payments WHERE status='approved'")->fetch_assoc() ?: ['t'=>0];
$instantTotal = (float)$instRow['t'];

// 2. Pledge Payments (Check if table exists first)
$pledgePaidTotal = 0;
if ($db->query("SHOW TABLES LIKE 'pledge_payments'")->num_rows > 0) {
    $ppRow = $db->query("SELECT COALESCE(SUM(amount),0) AS t FROM pledge_payments WHERE status='confirmed'")->fetch_assoc() ?: ['t'=>0];
    $pledgePaidTotal = (float)$ppRow['t'];
}

// 3. Pledges
$pledgeRow = $db->query("SELECT COALESCE(SUM(amount),0) AS t FROM pledges WHERE status='approved'")->fetch_assoc() ?: ['t'=>0];
$pledgedTotal = (float)$pledgeRow['t'];

// Logic:
$paidTotal = $instantTotal + $pledgePaidTotal;
$grandTotal = $pledgedTotal + $instantTotal;
```

**Also Affected (same file):**
- **CSV Export - "Donor Report"** (Lines 63-77): Uses aggregation from `pledges` and `payments` tables
  - Needs to include `pledge_payments` in the `UNION ALL`
  - Outstanding calculation will be wrong without this
  
- **CSV Export - "All Donations Export"** (Lines 160-270): Shows all donations
  - Should include `pledge_payments` as a third source
  
- **Recent Payments Section** (Line 618): Only shows `payments` table
  - Consider adding recent pledge payments?

---

### 2. **`admin/reports/comprehensive.php`**

**Current Logic (Lines 125-134):**
```php
$stmt = $db->prepare("SELECT COALESCE(SUM(amount),0), COUNT(*) FROM payments WHERE status='approved' AND received_at BETWEEN ? AND ?");
$stmt->bind_param('ss',$fromDate,$toDate); $stmt->execute(); $stmt->bind_result($sum, $cnt); $stmt->fetch(); $stmt->close();
$metrics['paid_total'] = (float)$sum; $metrics['payments_count'] = (int)$cnt;

// ... pledges ...
$metrics['grand_total'] = $metrics['paid_total'] + $metrics['pledged_total'];
```

**Issue:**
- Same as `index.php` - only counts instant payments
- "Paid Total" is understated
- "Grand Total" is also wrong

**Fix Needed:**
Same fix as above - need to query `pledge_payments` and add to `paid_total`.

**Also Affected:**
- **"Top Donors" calculation** (Lines 200-216): Aggregates from `pledges` and `payments` only
  - Needs `pledge_payments` in the UNION to show correct `total_paid`
  - Outstanding will be wrong without this
  
- **"Outstanding Balance"** (Lines 152-160): 
  - Currently: `Pledges - Payments`
  - Should be: `Pledges - Pledge_Payments`
  - This is a CRITICAL error! It's using the wrong table for outstanding calculation.

---

### 3. **`admin/reports/visual.php`**

**Current Logic (Lines 60-66):**
```php
$stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='approved' AND received_at BETWEEN ? AND ?");
$stmt->bind_param('ss',$fromDate,$toDate); $stmt->execute(); $stmt->bind_result($sumPaid); $stmt->fetch(); $stmt->close();
$data['metrics']['paid_total'] = (float)$sumPaid;

// ... grand_total ...
$data['metrics']['grand_total'] = (float)$sumPaid + (float)$sumPledged;
```

**Issue:**
- Same issue - only instant payments counted
- All charts showing "Paid" or "Grand Total" are understated

**Fix Needed:**
Same as above.

**Also Affected:**
- **Time Series Chart** (Lines 68-83): Shows payments and pledges over time
  - Should consider adding pledge_payments to show actual cash flow?
  - Or keep as-is if purpose is to show commitments vs. instant donations only

---

## 📊 **Database Updates Needed?**

### Current State:
- `pledge_payments` table: ✅ Created
- `donors.total_paid`: ✅ Auto-updated in `save-pledge-payment.php` (Line 66-69)
- `donors.balance`: ✅ Auto-updated in `save-pledge-payment.php` (Line 70-73)

### No Additional Database Changes Required
All donor-level fields (`total_paid`, `balance`, `payment_status`) are correctly updated by the `save-pledge-payment.php` script using subqueries.

---

## 🔧 **Recommended Action Plan**

### Priority 1 (Critical - Data Accuracy):
1. ✅ Update `admin/reports/comprehensive.php`:
   - Fix "Paid Total" calculation (add `pledge_payments`)
   - **CRITICAL:** Fix "Outstanding Balance" calculation (use `pledge_payments`, not `payments`)
   - Fix "Top Donors" aggregation (add `pledge_payments` to UNION)

2. ✅ Update `admin/reports/index.php`:
   - Fix "Total Paid" stat card (add `pledge_payments`)
   - Fix "Grand Total" (use correct formula)
   - Fix "Donor Report" CSV export (add `pledge_payments` to UNION)

3. ✅ Update `admin/reports/visual.php`:
   - Fix "Paid Total" KPI (add `pledge_payments`)
   - Fix "Grand Total" chart (use correct formula)

### Priority 2 (Enhancement - Completeness):
4. Consider adding `pledge_payments` to:
   - "All Donations Export" in `index.php` (for complete backup)
   - "Recent Payments" section (to show all payment activity)
   - Time-series charts in `visual.php` (to show cash flow)

---

## 📝 **SQL Queries Summary**

### For Totals:
```sql
-- Instant Payments
SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='approved';

-- Pledge Installments
SELECT COALESCE(SUM(amount),0) FROM pledge_payments WHERE status='confirmed';

-- Total Pledged
SELECT COALESCE(SUM(amount),0) FROM pledges WHERE status='approved';

-- Calculated Fields:
-- Total Paid = Instant + Pledge Installments
-- Grand Total = Total Pledged + Instant Payments (NOT Paid + Pledged!)
-- Outstanding = Total Pledged - Pledge Installments
```

### For Donor Aggregations (Top Donors, CSV):
```sql
SELECT donor_name, donor_phone, donor_email,
       SUM(CASE WHEN src='pledge' THEN amount ELSE 0 END) AS total_pledged,
       SUM(CASE WHEN src='payment' THEN amount ELSE 0 END) AS total_paid,
       MAX(last_seen_at) AS last_seen_at
FROM (
  -- Pledges (Promises)
  SELECT donor_name, donor_phone, donor_email, amount, created_at AS last_seen_at, 'pledge' AS src
  FROM pledges WHERE status='approved' AND created_at BETWEEN ? AND ?
  
  UNION ALL
  
  -- Instant Payments
  SELECT donor_name, donor_phone, donor_email, amount, received_at AS last_seen_at, 'payment' AS src
  FROM payments WHERE status='approved' AND received_at BETWEEN ? AND ?
  
  UNION ALL
  
  -- Pledge Installments (NEW!)
  SELECT d.name as donor_name, d.phone as donor_phone, d.email as donor_email, 
         pp.amount, pp.created_at AS last_seen_at, 'payment' AS src
  FROM pledge_payments pp
  LEFT JOIN donors d ON pp.donor_id = d.id
  WHERE pp.status='confirmed' AND pp.created_at BETWEEN ? AND ?
) c
GROUP BY donor_name, donor_phone, donor_email
ORDER BY total_paid DESC, total_pledged DESC
```

---

## ⚡ **Quick Verification Checklist**

After updates, verify:
- [ ] Projector shows correct "Total Paid" ✅ (Already fixed)
- [ ] Projector shows correct "Grand Total" ✅ (Already fixed)
- [ ] Recent feed shows pledge payments ✅ (Already fixed)
- [ ] Reports page shows correct "Total Paid" (Fix needed)
- [ ] Reports page shows correct "Outstanding" (Fix needed - CRITICAL)
- [ ] Comprehensive report "Top Donors" includes pledge payments (Fix needed)
- [ ] Visual report charts reflect pledge payments (Fix needed)
- [ ] CSV exports include pledge payments (Fix needed)

---

## 🎯 **End Goal**

All reports and stats should correctly reflect:
1. **Total Paid** = Cash in hand (instant donations + pledge installments)
2. **Total Pledged** = Commitment amount (doesn't change when paid)
3. **Grand Total** = Total fundraising value (Pledged + Instant, not Paid + Pledged)
4. **Outstanding** = Pledges - Pledge Installments (NOT Pledges - All Payments)

This ensures accurate financial tracking without double-counting and with proper separation of pledges vs. instant donations.

