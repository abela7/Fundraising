# Unpaid Payment Reports - Setup Guide

## Overview

This cron job runs at **22:00 daily** and sends WhatsApp reports to agents about their assigned donors who had payments due TODAY but did not pay.

## How It Works

1. **At 22:00**, the system checks all payment plans with `next_payment_due = TODAY`
2. Identifies donors who **did NOT make a payment** (no record in `pledge_payments` for today)
3. Groups unpaid payments by **assigned agent**
4. Sends **WhatsApp report** to each agent with:
   - List of unpaid donors
   - Amount each donor owed
   - Total unpaid amount
5. **Tracks sent reports** to prevent duplicate notifications
6. Sends **admin summary** to your WhatsApp

## Agent Report Example

```
⚠️ *Unpaid Payment Report*

📅 *Date:* Monday, 15 December 2025
👤 *Agent:* John Smith

━━━━━━━━━━━━━━━━━━
📊 *Summary:*
→ Unpaid Donors: 3
→ Total Amount: £150.00
━━━━━━━━━━━━━━━━━━

*Donor List:*

1. *Mary Johnson*
   → Amount Due: £50.00
   → Phone: 07123456789

2. *David Williams*
   → Amount Due: £50.00
   → Phone: 07987654321

3. *Sarah Brown*
   → Amount Due: £50.00
   → Phone: 07555123456

━━━━━━━━━━━━━━━━━━
Please follow up with these donors.
🔗 View in system: https://donate.abuneteklehaymanot.org/admin/donor-management/payment-calendar.php
```

## Setup Steps

### 1. Run the SQL Migration

Execute in phpMyAdmin:

```sql
-- File: sql/create_unpaid_reports_sent_table.sql
CREATE TABLE IF NOT EXISTS unpaid_reports_sent (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    agent_name VARCHAR(100) NULL,
    agent_phone VARCHAR(20) NULL,
    report_date DATE NOT NULL,
    unpaid_count INT NOT NULL DEFAULT 0,
    unpaid_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    donor_list TEXT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    channel ENUM('whatsapp', 'sms', 'both') NOT NULL DEFAULT 'whatsapp',
    message_preview VARCHAR(500) NULL,
    source_type ENUM('cron', 'manual', 'admin_trigger') NOT NULL DEFAULT 'cron',
    INDEX idx_agent_date (agent_id, report_date),
    INDEX idx_report_date (report_date),
    INDEX idx_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2. Configure Environment Variable

Add to your server's environment (e.g., `.htaccess` or cPanel):

```
FUNDRAISING_CRON_KEY=your_secure_random_key_here
```

### 3. Set Up Cron Job in cPanel

1. Go to **cPanel → Cron Jobs**
2. Add a new cron job:
   - **Common Settings**: Once Per Day (at 22:00)
   - **Minute**: `0`
   - **Hour**: `22`
   - **Day/Month/Weekday**: `*` (all)
   - **Command**: 
     ```
     /usr/local/bin/lsphp /home/abunetdg/donate.abuneteklehaymanot.org/cron/send-unpaid-reports.php >> /home/abunetdg/logs/unpaid-reports-cron.log 2>&1
     ```

### 4. Verify Agent Phone Numbers

Ensure all agents have phone numbers in the `users` table:

```sql
SELECT id, name, phone, email, role 
FROM users 
WHERE role IN ('admin', 'registrar') 
ORDER BY name;
```

Update missing phone numbers:

```sql
UPDATE users SET phone = '07xxxxxxxxx' WHERE id = ?;
```

### 5. Verify Donor-Agent Assignments

Check which donors are assigned to agents:

```sql
SELECT 
    d.id as donor_id, 
    d.name as donor_name, 
    u.name as agent_name
FROM donors d
LEFT JOIN users u ON d.agent_id = u.id
WHERE d.agent_id IS NOT NULL;
```

## Requirements

For a report to be sent:
1. ✅ Donor must have an **active payment plan** with `next_payment_due = TODAY`
2. ✅ Donor must be **assigned to an agent** (`donors.agent_id IS NOT NULL`)
3. ✅ Agent must have a **phone number** (`users.phone IS NOT NULL`)
4. ✅ Donor must **NOT have made a payment today** (no record in `pledge_payments`)
5. ✅ Report must **NOT have been sent already today** (checked via `unpaid_reports_sent`)

## Tracking & Duplicate Prevention

The `unpaid_reports_sent` table tracks all sent reports:

```sql
-- Check if agent received report today
SELECT * FROM unpaid_reports_sent 
WHERE agent_id = 5 AND report_date = CURDATE() AND DATE(sent_at) = CURDATE();

-- View all reports sent today
SELECT * FROM unpaid_reports_sent WHERE DATE(sent_at) = CURDATE();

-- View agent's report history
SELECT * FROM unpaid_reports_sent WHERE agent_id = 5 ORDER BY sent_at DESC;
```

## Logs

Logs are stored in `/logs/unpaid-reports-YYYY-MM-DD.log`:

```
[2025-12-15 22:00:01] INFO: Starting unpaid payment reports job
[2025-12-15 22:00:01] INFO: Tracking table verified
[2025-12-15 22:00:02] INFO: Found 3 agents with 8 unpaid payments (£400.00)
[2025-12-15 22:00:03] SENT: Agent #5 (John Smith) - 3 unpaid donors
[2025-12-15 22:00:04] SENT: Agent #7 (Mary Jones) - 2 unpaid donors
[2025-12-15 22:00:05] SKIP: Agent #9 (Bob Wilson) - No phone number
[2025-12-15 22:00:06] COMPLETE: Reports Sent: 2, Failed: 0, Skipped: 1
[2025-12-15 22:00:06] INFO: Admin notification sent
```

## Manual Testing

You can test the cron job manually:

### Via CLI:
```bash
php /path/to/cron/send-unpaid-reports.php
```

### Via Web (with cron key):
```
https://donate.abuneteklehaymanot.org/cron/send-unpaid-reports.php?cron_key=YOUR_KEY
```

## Troubleshooting

### No reports being sent?
1. Check if there are payments due today: 
   ```sql
   SELECT * FROM donor_payment_plans WHERE next_payment_due = CURDATE() AND status = 'active';
   ```
2. Check if donors are assigned to agents:
   ```sql
   SELECT d.* FROM donors d 
   JOIN donor_payment_plans pp ON d.id = pp.donor_id 
   WHERE pp.next_payment_due = CURDATE() AND d.agent_id IS NOT NULL;
   ```
3. Check if payments were already made:
   ```sql
   SELECT * FROM pledge_payments WHERE DATE(payment_date) = CURDATE();
   ```

### Agent not receiving report?
1. Verify agent has a phone number in `users` table
2. Check if report was already sent: 
   ```sql
   SELECT * FROM unpaid_reports_sent WHERE agent_id = ? AND DATE(sent_at) = CURDATE();
   ```

### Reset for re-testing:
```sql
-- Delete today's report records (allows re-sending)
DELETE FROM unpaid_reports_sent WHERE DATE(sent_at) = CURDATE();
```

## Admin Notifications

After the cron completes, you'll receive a WhatsApp summary:

```
📋 *Unpaid Reports Cron Complete*

📅 *Report Date:* Monday, 15 December 2025
⏰ *Run Time:* 15/12/2025 22:00:05

━━━━━━━━━━━━━━━━━━
📊 *Summary:*
✅ Reports Sent: 3
❌ Failed: 0
⏭️ Skipped: 1
💰 Total Unpaid: £400.00
👥 Total Donors: 8
━━━━━━━━━━━━━━━━━━

*Agent Reports:*
✅ John Smith: 3 donors (£150.00)
✅ Mary Jones: 2 donors (£100.00)
✅ Tom Brown: 3 donors (£150.00)
```

## Related Files

- `cron/send-unpaid-reports.php` - Main cron job
- `sql/create_unpaid_reports_sent_table.sql` - Database schema
- `logs/unpaid-reports-*.log` - Daily log files
