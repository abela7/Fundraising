# 🚀 AJAX Backend Setup - Phase 1

## ✅ **COMPLETED FILES:**

### 1. `ajax_approve.php` 
**AJAX approval handler with IDENTICAL floor allocation logic**
- ✅ Same transaction boundaries as original system
- ✅ Same `CustomAmountAllocator` calls  
- ✅ Same `IntelligentGridAllocator` calls
- ✅ Same counter update logic
- ✅ Same audit logging
- ✅ Rate limiting (10 requests/minute)
- ✅ CSRF protection
- ✅ Request deduplication
- ✅ Database locking with `FOR UPDATE`

### 2. `test_ajax_backend.php`
**Testing interface for the AJAX backend**
- ✅ Test approvals and rejections
- ✅ Real-time system status display
- ✅ Detailed response logging
- ✅ Floor allocation verification

### 3. `create_ajax_request_log_table.sql`
**Database table for request deduplication**

## 🔧 **SETUP STEPS:**

### Step 1: Create Database Table
Run this SQL in phpMyAdmin:
```sql
CREATE TABLE IF NOT EXISTS `ajax_request_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_uuid` varchar(255) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` int(11) NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_request` (`request_uuid`),
  KEY `idx_user_time` (`user_id`, `created_at`),
  KEY `idx_entity` (`entity_type`, `entity_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Step 2: Test the Backend
1. Navigate to: `admin/approvals/test_ajax_backend.php`
2. Test approving a few pledges and payments
3. Verify floor allocation works correctly
4. Check counter updates are accurate

### Step 3: Verify Floor Allocation
- ✅ Amounts under £100 should accumulate
- ✅ Amounts £100+ should allocate floor cells immediately
- ✅ Counters should update correctly
- ✅ Grid cells should be assigned sequentially
- ✅ Unapproval system should still work in `admin/approved/index.php`

## 🛡️ **SAFETY FEATURES:**

### Transaction Safety
```php
$db->begin_transaction();
try {
    // 1. Lock record with FOR UPDATE
    // 2. Validate status hasn't changed
    // 3. Update status
    // 4. Update counters  
    // 5. Allocate floor space
    // 6. Log audit entry
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}
```

### Duplicate Prevention
- ✅ UUID-based request tracking
- ✅ Database-level duplicate detection
- ✅ Rate limiting (10 requests/minute)
- ✅ CSRF token validation

### Floor Allocation Integrity
- ✅ Uses EXACT same `CustomAmountAllocator::processCustomAmount()`
- ✅ Uses EXACT same `IntelligentGridAllocator::allocate()`
- ✅ Identical transaction boundaries
- ✅ Same error handling and rollback logic

## 🧪 **TESTING VERIFICATION:**

### Before Testing:
1. Note current counter totals
2. Note available floor cells count
3. Note accumulated custom amounts

### During Testing:
1. Approve a £50 donation → Should accumulate, no cells allocated
2. Approve a £60 donation → Should trigger allocation if total ≥ £100
3. Approve a £200 donation → Should allocate 2 cells immediately
4. Approve a £400 donation → Should allocate 4 cells immediately

### After Testing:
1. Verify counters increased by exact amounts
2. Verify correct number of cells allocated
3. Verify floor grid shows proper donor names
4. Test unapproval in `admin/approved/index.php`

## ⚠️ **IMPORTANT:**

**This is REAL TESTING** - All operations affect the live database. The AJAX backend uses IDENTICAL logic to the original system, so floor allocation and counters will be updated exactly as they would through the original forms.

**Next Phase:** Once this backend is verified, we'll add pagination and then integrate AJAX into the existing UI with fallback support.
