# Donor Information Columns - Status & Migration

## Overview
This document outlines the database columns needed for collecting donor information during call center conversations.

---

## Required Information Fields

Based on the call center enhancement requirements, we need to collect:

1. **Baptism Name** - The donor's baptism name
2. **City** - Where the donor lives
3. **Which Church Attending Regularly** - The church they attend (different from administrative assignment)
4. **Email Address** - Email for communication
5. **Preferred Language** - Language preference (en/am/ti)

---

## Current Database Status

### ✅ Columns That Already Exist

Based on codebase analysis:

1. **`baptism_name`** ✅
   - Type: `VARCHAR(255) NULL`
   - Status: EXISTS (used in `view-donor.php`, `edit-donor.php`)
   - Location: After `name` column

2. **`city`** ✅
   - Type: `VARCHAR(255) NULL`
   - Status: EXISTS (used throughout the system)
   - Location: After `phone` column

3. **`email`** ✅
   - Type: `VARCHAR(255) NULL`
   - Status: EXISTS (used in `view-donor.php`, `edit-donor.php`)
   - Location: After `phone` column

4. **`preferred_language`** ✅
   - Type: `ENUM('en', 'am', 'ti') DEFAULT 'en'`
   - Status: EXISTS (used throughout the system)
   - Values: `en` = English, `am` = Amharic, `ti` = Tigrinya

5. **`church_id`** ✅
   - Type: `INT NULL`
   - Status: EXISTS
   - **NOTE**: This is for ADMINISTRATIVE assignment (which church/representative is responsible for the donor)
   - **NOT** for tracking which church they attend regularly

---

### ⚠️ Column That May Need to Be Added

**`attending_church_id`** ⚠️
- **Purpose**: Track which church the donor actually attends regularly
- **Different from**: `church_id` (administrative assignment)
- **Status**: NEEDS VERIFICATION - May not exist
- **Type**: `INT NULL` with foreign key to `churches.id`

**Why Two Church Columns?**
- `church_id` = Administrative assignment (which church/rep manages this donor)
- `attending_church_id` = Actual church attendance (which church they go to)

Example:
- A donor might attend "Liverpool St. Mary's Church" regularly (`attending_church_id`)
- But be administratively assigned to "Manchester St. George's Church" for fundraising purposes (`church_id`)

---

## Migration Steps

### Step 1: Verify Current Columns

Run this SQL in phpMyAdmin to check what exists:

```sql
-- See: admin/call-center/verify-donor-columns.sql
SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME = 'donors'
AND COLUMN_NAME IN (
    'baptism_name',
    'city',
    'email',
    'preferred_language',
    'church_id',
    'attending_church_id'
);
```

### Step 2: Add Missing Columns

Run the migration script:

```sql
-- See: admin/call-center/add-donor-info-columns.sql
```

This script will:
- ✅ Check if each column exists
- ✅ Add only missing columns
- ✅ Add indexes where needed
- ✅ Add foreign key constraint for `attending_church_id`
- ✅ Show verification results

---

## Column Details

### 1. Baptism Name
```sql
baptism_name VARCHAR(255) NULL
COMMENT 'Baptism name of the donor'
```
- **Required**: No (can be NULL)
- **Example**: "Tekle Haymanot", "Mariam", "Gabriel"

### 2. City
```sql
city VARCHAR(255) NULL
COMMENT 'City where donor lives'
```
- **Required**: No (can be NULL)
- **Example**: "Liverpool", "Manchester", "London"

### 3. Attending Church
```sql
attending_church_id INT NULL
COMMENT 'Church the donor attends regularly'
INDEX idx_attending_church (attending_church_id)
FOREIGN KEY (attending_church_id) REFERENCES churches(id)
```
- **Required**: No (can be NULL)
- **Relationship**: Links to `churches.id`
- **Different from**: `church_id` (administrative assignment)

### 4. Email Address
```sql
email VARCHAR(255) NULL
COMMENT 'Email address'
INDEX idx_email (email)
```
- **Required**: No (can be NULL)
- **Validation**: Should be valid email format
- **Example**: "donor@example.com"

### 5. Preferred Language
```sql
preferred_language ENUM('en', 'am', 'ti') DEFAULT 'en'
COMMENT 'Preferred language: en=English, am=Amharic, ti=Tigrinya'
```
- **Required**: Yes (has default 'en')
- **Values**:
  - `en` = English
  - `am` = Amharic
  - `ti` = Tigrinya
- **Default**: `en`

---

## Usage in Call Center

After migration, the call center conversation flow will include:

**Step 1**: Verify Pledge Details (existing)
**Step 2**: Collect Donor Information (NEW)
- Baptism Name
- City (where they live)
- Which church attending regularly
- Email address
- Preferred language

**Step 3**: Payment Readiness (existing)
**Step 4**: Payment Plan Selection (existing)
**Step 5**: Review & Confirm (existing)

---

## Verification Checklist

After running the migration:

- [ ] `baptism_name` column exists
- [ ] `city` column exists
- [ ] `email` column exists
- [ ] `preferred_language` column exists
- [ ] `attending_church_id` column exists (if needed)
- [ ] `idx_email` index exists
- [ ] Foreign key constraint on `attending_church_id` exists (if column added)
- [ ] All columns are nullable (except `preferred_language` which has default)

---

## Next Steps

1. ✅ Run `verify-donor-columns.sql` to check current state
2. ✅ Run `add-donor-info-columns.sql` to add missing columns
3. ✅ Verify all columns exist
4. ✅ Update call center conversation flow to collect this information
5. ✅ Test the new step in the call center

---

## Notes

- All columns are nullable to allow gradual data collection
- `preferred_language` has a default value of 'en' so it's never NULL
- `attending_church_id` is optional - if not needed, we can use `church_id` for both purposes
- Email validation should be done in PHP before saving
- Consider adding email uniqueness constraint if needed (currently not enforced)

---

**Last Updated**: 2025-01-XX
**Status**: Ready for Migration

