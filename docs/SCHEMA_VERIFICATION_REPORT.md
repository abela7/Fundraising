# Schema Verification Report: unified_message_logging.sql

## ✅ Overall Assessment: **ROBUST AND ALIGNED**

The schema is well-designed and properly aligned with the current system. Minor optimizations recommended below.

---

## ✅ Column Alignment Check

### INSERT Statement (MessagingHelper.php) vs Table Schema

**37 columns in INSERT statement:**
1. ✅ donor_id - INT (matches)
2. ✅ phone_number - VARCHAR(20) (matches)
3. ✅ recipient_name - VARCHAR(255) (matches)
4. ✅ channel - ENUM (matches)
5. ✅ message_content - TEXT (matches)
6. ✅ message_language - ENUM (matches)
7. ✅ message_length - INT (matches)
8. ✅ segments - TINYINT (matches)
9. ✅ template_id - INT UNSIGNED (matches)
10. ✅ template_key - VARCHAR(50) (matches)
11. ✅ template_variables - JSON (matches)
12. ✅ sent_by_user_id - INT (matches)
13. ✅ sent_by_name - VARCHAR(255) (matches)
14. ✅ sent_by_role - VARCHAR(50) (matches)
15. ✅ source_type - VARCHAR(50) (matches)
16. ✅ source_id - INT (matches)
17. ✅ source_reference - VARCHAR(100) (matches)
18. ✅ provider_id - INT UNSIGNED (matches)
19. ✅ provider_name - VARCHAR(50) (matches)
20. ✅ provider_message_id - VARCHAR(100) (matches)
21. ✅ provider_response - TEXT (matches)
22. ✅ status - ENUM (matches)
23. ✅ sent_at - DATETIME (matches)
24. ✅ delivered_at - DATETIME (matches)
25. ✅ read_at - DATETIME (matches)
26. ✅ failed_at - DATETIME (matches)
27. ✅ error_code - VARCHAR(50) (matches)
28. ✅ error_message - TEXT (matches)
29. ✅ retry_count - TINYINT (matches)
30. ✅ is_fallback - TINYINT(1) (matches)
31. ✅ cost_pence - DECIMAL(8,2) (matches)
32. ✅ currency - CHAR(3) (matches)
33. ✅ queue_id - BIGINT UNSIGNED (matches)
34. ✅ call_session_id - INT (matches)
35. ✅ campaign_id - INT (matches)
36. ✅ ip_address - VARCHAR(45) (matches)
37. ✅ user_agent - VARCHAR(255) (matches)

**Additional columns in table (not in INSERT - auto-managed):**
- ✅ id - AUTO_INCREMENT (correct)
- ✅ created_at - DEFAULT CURRENT_TIMESTAMP (correct)
- ✅ updated_at - DEFAULT CURRENT_TIMESTAMP ON UPDATE (correct)
- ⚠️ status_updated_at - DATETIME nullable (not used in INSERT, but available for future use)

**Result: 100% ALIGNED** ✅

---

## ✅ Foreign Key Verification

### 1. `fk_msg_log_donor` → `donors.id`
- ✅ **Table exists**: `donors` table confirmed
- ✅ **Column exists**: `donors.id` is `INT PRIMARY KEY AUTO_INCREMENT`
- ✅ **Type matches**: `donor_id INT` matches `donors.id INT`
- ✅ **ON DELETE**: `SET NULL` is correct (preserves log even if donor deleted)

### 2. `fk_msg_log_user` → `users.id`
- ✅ **Table exists**: `users` table confirmed (used throughout system)
- ✅ **Column exists**: `users.id` is `INT PRIMARY KEY AUTO_INCREMENT`
- ✅ **Type matches**: `sent_by_user_id INT` matches `users.id INT`
- ✅ **ON DELETE**: `SET NULL` is correct (preserves log even if user deleted)

### 3. `fk_msg_log_template` → `sms_templates.id`
- ✅ **Table exists**: `sms_templates` table confirmed (from `sms_system_tables.sql`)
- ✅ **Column exists**: `sms_templates.id` is `INT UNSIGNED NOT NULL AUTO_INCREMENT`
- ✅ **Type matches**: `template_id INT UNSIGNED` matches `sms_templates.id INT UNSIGNED`
- ✅ **ON DELETE**: `SET NULL` is correct (preserves log even if template deleted)

**Result: All foreign keys are CORRECT** ✅

---

## ✅ Index Analysis

### Current Indexes:
1. ✅ `idx_donor` (`donor_id`) - Essential for donor history queries
2. ✅ `idx_phone` (`phone_number`) - Essential for phone-based queries
3. ✅ `idx_channel` (`channel`) - Good for filtering by channel
4. ✅ `idx_status` (`status`) - Essential for status filtering
5. ✅ `idx_sent_at` (`sent_at`) - Essential for date-based queries
6. ✅ `idx_sent_by` (`sent_by_user_id`) - Essential for user activity tracking
7. ✅ `idx_source` (`source_type`, `source_id`) - Good composite index
8. ✅ `idx_template` (`template_id`) - Good for template analysis
9. ✅ `idx_provider_msg` (`provider_message_id`) - Good for provider lookups
10. ✅ `idx_call_session` (`call_session_id`) - Good for call center integration
11. ✅ `idx_campaign` (`campaign_id`) - Good for campaign tracking
12. ⚠️ `idx_donor_date` (`donor_id`, `sent_at` DESC) - **POTENTIAL ISSUE**
13. ⚠️ `idx_donor_channel` (`donor_id`, `channel`, `sent_at` DESC) - **POTENTIAL ISSUE**

### ⚠️ Index Syntax Issue

**Problem**: MySQL/MariaDB versions before 8.0.1 do NOT support `DESC` in index definitions.

**Impact**: 
- MySQL 5.7 and MariaDB 10.2: Will fail with syntax error
- MySQL 8.0+: Will work correctly

**Recommendation**: Remove `DESC` from index definitions for compatibility:

```sql
-- Change from:
KEY `idx_donor_date` (`donor_id`, `sent_at` DESC),
KEY `idx_donor_channel` (`donor_id`, `channel`, `sent_at` DESC),

-- To:
KEY `idx_donor_date` (`donor_id`, `sent_at`),
KEY `idx_donor_channel` (`donor_id`, `channel`, `sent_at`),
```

**Note**: The `DESC` in index definition doesn't actually affect query performance - MySQL can use indexes in both directions. The `ORDER BY ... DESC` in queries will still work efficiently.

**Result: Minor compatibility issue - easy fix** ⚠️

---

## ✅ Data Type Verification

All data types are appropriate:

- ✅ `BIGINT UNSIGNED` for `id` - Good for high-volume logging
- ✅ `INT` for `donor_id`, `sent_by_user_id` - Matches referenced tables
- ✅ `INT UNSIGNED` for `template_id`, `provider_id` - Matches referenced tables
- ✅ `VARCHAR(20)` for `phone_number` - Appropriate for normalized phone numbers
- ✅ `TEXT` for `message_content`, `provider_response`, `error_message` - Appropriate for variable-length content
- ✅ `ENUM` for `channel`, `status`, `message_language` - Efficient and type-safe
- ✅ `DECIMAL(8,2)` for `cost_pence` - Appropriate for currency (supports up to £99,999.99)
- ✅ `DATETIME` for timestamps - Standard MySQL datetime type
- ✅ `TINYINT(1)` for `is_fallback` - Standard boolean representation
- ✅ `JSON` for `template_variables` - Modern MySQL JSON support

**Result: All data types are CORRECT** ✅

---

## ✅ Constraints & Validation

### NOT NULL Constraints:
- ✅ `phone_number` - Required (makes sense)
- ✅ `channel` - Required (makes sense)
- ✅ `message_content` - Required (makes sense)
- ✅ `source_type` - Required (makes sense)
- ✅ `status` - Required with default 'sent' (makes sense)
- ✅ `sent_at` - Required (makes sense)

### Default Values:
- ✅ `status` DEFAULT 'sent' - Logical default
- ✅ `message_language` DEFAULT 'en' - Logical default
- ✅ `segments` DEFAULT 1 - Logical default
- ✅ `retry_count` DEFAULT 0 - Logical default
- ✅ `is_fallback` DEFAULT 0 - Logical default
- ✅ `currency` DEFAULT 'GBP' - Logical default
- ✅ `created_at` DEFAULT CURRENT_TIMESTAMP - Standard
- ✅ `updated_at` DEFAULT CURRENT_TIMESTAMP ON UPDATE - Standard

**Result: Constraints are APPROPRIATE** ✅

---

## ✅ Views Analysis

### 1. `v_donor_message_history`
- ✅ Useful for quick donor queries
- ✅ Includes calculated fields (delivery_time_seconds, read_time_seconds)
- ✅ Filters to `donor_id IS NOT NULL` (makes sense)

### 2. `v_user_message_activity`
- ✅ Useful for user activity reports
- ✅ Aggregates by user with counts and costs
- ✅ Filters to `sent_by_user_id IS NOT NULL` (makes sense)

### 3. `v_donor_communication_summary`
- ✅ Useful for donor overview pages
- ✅ LEFT JOIN ensures all donors appear (even with no messages)
- ✅ Aggregates communication stats per donor

**Result: Views are WELL-DESIGNED** ✅

---

## ⚠️ Minor Issues & Recommendations

### 1. Index Compatibility (CRITICAL)
**Issue**: `DESC` in index definitions may fail on older MySQL/MariaDB versions.

**Fix**: Remove `DESC` from index definitions (see Index Analysis above).

### 2. Unused Column
**Issue**: `status_updated_at` is defined but never populated.

**Impact**: Low - column is nullable, can be used in future updates.

**Recommendation**: Either:
- Remove it if not needed, OR
- Add logic to update it when status changes (future enhancement)

### 3. Missing Index (OPTIONAL)
**Recommendation**: Consider adding composite index for common query pattern:
```sql
KEY `idx_status_sent_at` (`status`, `sent_at`)
```
This would optimize queries like "Get all failed messages from last week".

---

## ✅ Compatibility Check

### MySQL/MariaDB Versions:
- ✅ MySQL 5.7+ (with index fix)
- ✅ MySQL 8.0+ (works as-is)
- ✅ MariaDB 10.2+ (with index fix)
- ✅ MariaDB 10.3+ (works as-is)

### Engine & Charset:
- ✅ `ENGINE=InnoDB` - Correct (supports foreign keys, transactions)
- ✅ `CHARSET=utf8mb4` - Correct (supports emojis, full Unicode)
- ✅ `COLLATE=utf8mb4_unicode_ci` - Correct (case-insensitive Unicode)

---

## ✅ Final Verdict

### **SCHEMA IS ROBUST AND ALIGNED** ✅

**Strengths:**
- ✅ 100% column alignment with code
- ✅ All foreign keys correctly reference existing tables
- ✅ Comprehensive indexing strategy
- ✅ Appropriate data types and constraints
- ✅ Well-designed views for common queries
- ✅ Future-proof design (nullable columns for optional data)

**Minor Fixes Needed:**
- ⚠️ Remove `DESC` from index definitions for compatibility (1-line fix)

**Recommendation:**
1. ✅ **APPROVE** the schema
2. ⚠️ **FIX** index definitions (remove DESC)
3. ✅ **PROCEED** with migration

---

## 📋 Migration Checklist

- [x] Schema verified against code
- [x] Foreign keys verified
- [x] Data types verified
- [x] Indexes verified (minor fix needed)
- [x] Views verified
- [ ] Index DESC syntax fixed
- [ ] Migration tested on staging
- [ ] Migration run on production

---

**Generated**: 2025-01-XX  
**Verified By**: AI Assistant  
**Status**: ✅ APPROVED (with minor fix)

