# Donor System Migration Guide

## 🎯 Overview

This migration adds a comprehensive **Donor Payment Plan System** to your fundraising platform, enabling:

- 📊 **Central Donor Registry** - Track all donors in one place
- 💳 **Payment Plans** - Allow donors to pay pledges in installments
- 🌐 **Multi-language Portal** - English, Amharic, and Tigrinya support
- 📱 **SMS Notifications** - Automated payment reminders
- 🔐 **Secure Access** - Token-based donor portal authentication
- 📝 **Complete Audit Trail** - Track every change and action

---

## 🚀 Two Ways to Run the Migration

### Option 1: Web Interface (Recommended)

1. **Navigate to:** `/admin/tools/`
2. **Click:** "Run Donor System Migration" button
3. **Review:** The migration details
4. **Confirm:** Click "Run Migration"
5. **Wait:** The process takes 5-30 seconds
6. **Verify:** Check the detailed report and statistics

**Benefits:**
- ✅ Visual progress tracking
- ✅ Detailed step-by-step log
- ✅ Automatic verification statistics
- ✅ Beautiful error reporting
- ✅ No technical knowledge required

**URL:** `https://yourdomain.com/admin/tools/migrate_donors_system.php`

---

### Option 2: Direct SQL (For Advanced Users)

1. **Backup your database** using phpMyAdmin or command line
2. **Open phpMyAdmin** and select your database
3. **Go to SQL tab**
4. **Upload or paste** the contents of `migration_001_create_donors_system.sql`
5. **Execute** the SQL script
6. **Review** the output messages

**Benefits:**
- ✅ Direct database control
- ✅ Can be run on any MySQL client
- ✅ Easy to version control
- ✅ Can be automated

---

## 📋 What This Migration Does

### New Tables Created

#### 1. **`donors`** (Central Registry)
The heart of the system - stores all donor information:
- Personal info (name, phone, language preference)
- Financial totals (pledged, paid, balance)
- Payment plan details
- Status tracking (pending, paying, completed, etc.)
- Achievement badges
- Portal access tokens
- Admin notes and follow-up flags

#### 2. **`donor_payment_plans`**
Manages installment payment plans:
- Total amount and monthly amounts
- Duration and schedule
- Payment method preferences
- Status tracking
- Reminder management

#### 3. **`donor_audit_log`**
Comprehensive audit trail:
- Every action logged
- Before/after snapshots
- User attribution
- IP and user agent tracking
- Full JSON state preservation

#### 4. **`donor_portal_tokens`**
Secure portal access:
- Cryptographically secure tokens
- Expiration management
- Usage tracking
- Revocation support

### Existing Tables Enhanced

#### **`pledges` table**
- ➕ Added `donor_id` column
- 🔗 Linked to donors table
- 🔄 Backward compatible (phone still works)

#### **`payments` table**
- ➕ Added `donor_id` column
- ➕ Added `pledge_id` column (links payment to pledge)
- ➕ Added `installment_number` column (1 of 6, 2 of 6, etc.)
- 🔗 Linked to both donors and pledges

---

## 🔄 Data Migration Process

The migration automatically:

1. ✅ **Creates** all new tables with proper indexes
2. ✅ **Extracts** unique donors from existing pledges
3. ✅ **Links** all existing pledges to donors
4. ✅ **Links** all existing payments to donors
5. ✅ **Calculates** total pledged amounts per donor
6. ✅ **Calculates** total paid amounts per donor
7. ✅ **Computes** outstanding balances automatically
8. ✅ **Assigns** appropriate payment statuses
9. ✅ **Awards** achievement badges based on progress

**Example:**
```
Before Migration:
pledges table: 150 records with duplicate donors

After Migration:
donors table: 85 unique donors
pledges table: 150 records linked to donors
payments table: 45 records linked to donors and pledges
```

---

## 📊 New Donor Statuses

### Payment Status (Lifecycle)
- 🔴 **no_pledge** - Never pledged
- 🟡 **not_started** - Pledged but no plan selected
- 🔵 **paying** - Actively making payments
- 🟠 **overdue** - Missed payment deadline
- 🟢 **completed** - Fully paid
- ⚫ **defaulted** - Stopped paying (admin marked)

### Achievement Badges (Rewards)
- 🔴 **pending** - No action yet
- 🟡 **started** - Made first payment
- 🔵 **on_track** - Paying on schedule
- 🟣 **fast_finisher** - Completed early
- ✅ **completed** - Fully paid
- ⭐ **champion** - Completed + donated extra

---

## 🌍 Multi-Language Support

The system supports three languages:
- 🇬🇧 **English (en)** - Default
- 🇪🇹 **Amharic (am)** - አማርኛ
- 🇪🇷 **Tigrinya (ti)** - ትግርኛ

Each donor can choose their preferred language for:
- Portal interface
- SMS notifications
- Email communications (if added)

---

## 🔐 Security Features

### Token-Based Authentication
- 64-character cryptographically secure tokens
- Configurable expiration (default: 30 days)
- One-time use options
- IP tracking
- Device fingerprinting

### Audit Logging
- Every change tracked
- User attribution
- Before/after state
- IP addresses logged
- Full transparency

### Data Integrity
- Foreign key constraints
- Referential integrity
- Transaction-based migration
- Automatic rollback on error

---

## ⚠️ Important Notes

### Before Migration

1. **BACKUP YOUR DATABASE!**
   - Use phpMyAdmin export
   - Or use `/admin/tools/export_database.php`
   - Save backup in a safe location

2. **Test on a copy first** (if possible)
   - Clone your database
   - Run migration on clone
   - Verify everything works
   - Then run on production

3. **Check server requirements**
   - MySQL 5.7+ or MariaDB 10.2+
   - PHP 8.0+
   - Sufficient disk space

### After Migration

1. **Verify the statistics** shown in the report
2. **Check a few donor records** manually
3. **Test the donor portal** (when built)
4. **Review payment plan features** (when built)

### Rollback Plan

If something goes wrong, the migration can be rolled back:

```sql
-- See the rollback script at the bottom of migration_001_create_donors_system.sql
-- It will remove all new tables and columns
```

**Note:** The web interface uses transactions, so if any step fails, all changes are automatically rolled back.

---

## 🔍 Verification Queries

After migration, you can run these queries to verify:

```sql
-- Check donor count
SELECT COUNT(*) FROM donors;

-- Check linked pledges
SELECT COUNT(*) FROM pledges WHERE donor_id IS NOT NULL;

-- Check linked payments
SELECT COUNT(*) FROM payments WHERE donor_id IS NOT NULL;

-- View status breakdown
SELECT payment_status, COUNT(*) FROM donors GROUP BY payment_status;

-- Check balances
SELECT 
    SUM(total_pledged) as total_pledged,
    SUM(total_paid) as total_paid,
    SUM(balance) as outstanding
FROM donors;
```

---

## 🆘 Troubleshooting

### Issue: Foreign Key Constraint Error

**Cause:** Orphaned records in pledges/payments tables

**Solution:**
```sql
-- Find orphaned pledges (if any)
SELECT * FROM pledges WHERE donor_phone = '' OR donor_phone IS NULL;

-- Clean them before migration
UPDATE pledges SET donor_phone = '07000000000' WHERE donor_phone = '' OR donor_phone IS NULL;
```

### Issue: Duplicate Phone Numbers

**Cause:** Inconsistent phone number formatting

**Solution:** The migration handles this automatically by grouping on `donor_phone` and taking the most recent donor name.

### Issue: Migration Timeout

**Cause:** Large database

**Solution:**
- Use the SQL file option (Option 2)
- Run directly in MySQL command line
- Increase PHP execution time

---

## 📚 Next Steps

After successful migration:

1. **Build the Admin Interface**
   - Create payment plan templates
   - Assign plans to donors
   - Manage installments

2. **Build the Donor Portal**
   - Token generation
   - Login system
   - Dashboard with progress tracking
   - Payment history view

3. **Set Up SMS Notifications**
   - Integrate with Twilio or similar
   - Configure reminder schedules
   - Test message templates

4. **Create Admin Dashboard**
   - Overdue payments view
   - Follow-up priority queue
   - Status reports

---

## 📞 Support

If you encounter any issues:

1. Check the migration log for specific errors
2. Review this README for common solutions
3. Check database error logs
4. Verify server requirements

---

## 📝 Technical Details

### Database Changes Summary

```
New Tables:
- donors (4 new tables total)
- donor_payment_plans
- donor_audit_log
- donor_portal_tokens

Modified Tables:
- pledges (+1 column: donor_id)
- payments (+3 columns: donor_id, pledge_id, installment_number)

New Indexes: 20+
New Foreign Keys: 6
```

### Performance Optimizations

- Indexed all foreign keys
- Indexed frequently queried fields
- Generated column for balance (auto-computed)
- Composite indexes for complex queries

### Backward Compatibility

- ✅ Existing code continues to work
- ✅ Phone fields preserved in pledges/payments
- ✅ All existing queries still function
- ✅ No breaking changes

---

## ✅ Migration Checklist

- [ ] Database backed up
- [ ] Server requirements verified
- [ ] Migration method chosen (Web UI or SQL)
- [ ] Migration executed successfully
- [ ] Verification statistics reviewed
- [ ] Sample donor records checked
- [ ] No errors in migration log
- [ ] Rollback script saved (just in case)
- [ ] Team notified of new features
- [ ] Documentation updated

---

## 🎉 Success!

Once migrated, your system is ready for the next phase: building the payment plan management interface and donor portal!

**Happy fundraising! 🚀**

