# Agent Call Schedule Reminders - Setup Guide

## 📋 Overview

This automated system sends daily WhatsApp reminders to agents about their scheduled calls for the next day. Agents receive a list of all appointments, including donor names, phone numbers, times, and notes.

**Schedule:** Runs daily at 22:00 (10 PM)  
**Purpose:** Notify agents about tomorrow's call appointments  
**Channel:** WhatsApp (via UltraMSG)

---

## 🔧 Installation

### Step 1: Create Database Table

Run the SQL script to create the tracking table:

```bash
mysql -u your_user -p your_database < sql/create_agent_call_reminders_table.sql
```

Or in phpMyAdmin, import: `sql/create_agent_call_reminders_table.sql`

This creates the `agent_call_reminders_sent` table to track sent reminders and prevent duplicates.

---

### Step 2: Set Up Environment Variables

Ensure your `.env` file has the cron key configured:

```env
FUNDRAISING_CRON_KEY=your_secure_random_key_here
```

**Generate a secure key:**
```bash
openssl rand -hex 32
```

---

### Step 3: Configure the Cron Job

#### Option A: cPanel Cron Jobs (Recommended)

1. Log in to cPanel
2. Go to **Cron Jobs**
3. Add a new cron job:

```
0 22 * * * /usr/local/bin/lsphp /home/abunetdg/donate.abuneteklehaymanot.org/cron/send-agent-call-reminders.php
```

**Breakdown:**
- `0 22 * * *` = Every day at 22:00 (10 PM)
- `/usr/local/bin/lsphp` = PHP binary (adjust path as needed)
- Full path to the script

#### Option B: Linux Server Crontab

```bash
crontab -e
```

Add:
```
0 22 * * * /usr/bin/php /path/to/Fundraising/cron/send-agent-call-reminders.php >> /path/to/logs/agent-reminders.log 2>&1
```

#### Option C: Web-Based Trigger (Alternative)

If you can't use cron, use a service like [cron-job.org](https://cron-job.org):

**URL:**
```
https://donate.abuneteklehaymanot.org/cron/send-agent-call-reminders.php?cron_key=YOUR_CRON_KEY
```

**Schedule:** Daily at 22:00 UTC (adjust for timezone)

---

## 📱 Message Format

Agents receive a message like this:

```
🗓️ Call Schedule Reminder

Hi John Smith,

You have 5 appointment(s) scheduled for tomorrow (Saturday, 14 December 2024):

1. 09:00 - Callback
   → Ahmed Hassan
   → +447123456789
   📝 Interested in monthly plan

2. 10:30 - Follow Up
   → Sarah Williams
   → +447987654321

3. 14:00 - Callback
   → Mohammed Ali
   → +447555123456
   📝 Requested specific time

4. 15:30 - Follow Up
   → Emma Jones
   → +447444987654

5. 17:00 - Callback
   → David Brown
   → +447222111333

Please prepare for these calls.

View full schedule: https://donate.abuneteklehaymanot.org/admin/call-center/my-schedule.php

Good luck! 🙏
```

---

## 🔍 How It Works

### 1. Query Tomorrow's Appointments

The script queries `call_center_appointments` for:
- Appointments scheduled for tomorrow
- Status: `scheduled` or `pending`
- Only agents with valid phone numbers
- Groups by agent

### 2. Check for Duplicates

Before sending, the script checks `agent_call_reminders_sent` to ensure:
- Agent hasn't already received a reminder today for tomorrow's appointments
- Uses unique constraint: `(agent_id, appointment_date)`

### 3. Send WhatsApp Messages

For each agent:
- Formats a list of appointments (time, type, donor name, phone)
- Sends via UltraMSG WhatsApp API
- Logs to `agent_call_reminders_sent` table
- Brief 0.5-second delay between sends

### 4. Admin Notification

After all reminders are sent, admin receives a summary:
- Total sent, skipped, failed
- List of first 10 agents notified
- Timestamp and date info

---

## 🧪 Testing

### Manual Test (Command Line)

```bash
php /path/to/Fundraising/cron/send-agent-call-reminders.php
```

**Expected Output:**
```
================================================================================
🔔 Agent Call Reminders Cron Job
================================================================================
Started at: 2024-12-13 22:00:00
Checking appointments for: 2024-12-14

✓ WhatsApp service connected

Found 3 agent(s) with appointments for tomorrow.

Processing Agent: John Smith (ID: 5)
  Phone: +447360436171
  Appointments: 5
  ✓ Reminder sent successfully

Processing Agent: Mary Johnson (ID: 8)
  Phone: +447987654321
  Appointments: 3
  ✓ Reminder sent successfully

Processing Agent: Ahmed Khan (ID: 12)
  Phone: +447123456789
  Appointments: 2
  ✓ Reminder sent successfully

Sending admin notification...
✓ Admin notification sent

================================================================================
✅ Cron job completed successfully!
   Sent: 3 | Skipped: 0 | Failed: 0
================================================================================
```

### Web-Based Test (Browser)

```
https://donate.abuneteklehaymanot.org/cron/send-agent-call-reminders.php?cron_key=YOUR_CRON_KEY
```

### Schedule a Test Appointment

1. Go to `admin/call-center/my-schedule.php`
2. Create a test appointment for tomorrow
3. Wait for 22:00 or run the script manually
4. Check WhatsApp for the reminder

---

## 📊 Monitoring

### View Sent Reminders

Query the tracking table:

```sql
SELECT 
    agent_name,
    agent_phone,
    appointment_date,
    appointment_count,
    sent_at,
    status
FROM agent_call_reminders_sent
ORDER BY sent_at DESC
LIMIT 20;
```

### Check for Failures

```sql
SELECT * FROM agent_call_reminders_sent 
WHERE status = 'failed' 
ORDER BY sent_at DESC;
```

### View Appointment Details

```sql
SELECT 
    agent_name,
    appointment_date,
    appointment_list
FROM agent_call_reminders_sent
WHERE agent_id = 5
ORDER BY sent_at DESC
LIMIT 1;
```

---

## 🚨 Troubleshooting

### No Reminders Sent

**Problem:** Cron runs but no reminders are sent.

**Solutions:**
1. Check if there are appointments for tomorrow:
   ```sql
   SELECT COUNT(*) FROM call_center_appointments 
   WHERE appointment_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
   AND status IN ('scheduled', 'pending');
   ```

2. Verify agents have phone numbers:
   ```sql
   SELECT u.id, u.name, u.phone 
   FROM users u
   INNER JOIN call_center_appointments a ON u.id = a.agent_id
   WHERE a.appointment_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
   AND u.phone IS NOT NULL;
   ```

3. Check if reminders were already sent:
   ```sql
   SELECT * FROM agent_call_reminders_sent 
   WHERE reminder_date = CURDATE();
   ```

### WhatsApp Not Connected

**Problem:** `WhatsApp service not available`

**Solution:**
- Check UltraMSG instance status
- Verify API credentials in `whatsapp_instances` table
- Scan QR code if session expired

### Duplicate Reminders

**Problem:** Agents receive the same reminder multiple times.

**Solution:**
- This shouldn't happen due to the unique constraint
- Check if cron is scheduled multiple times
- Verify table has unique key:
  ```sql
  SHOW CREATE TABLE agent_call_reminders_sent;
  ```

### Cron Not Running

**Problem:** Script doesn't execute at 22:00.

**Solutions:**
1. **Check cron logs:**
   ```bash
   grep "send-agent-call-reminders" /var/log/cron
   ```

2. **Test PHP path:**
   ```bash
   which php
   /usr/local/bin/lsphp --version
   ```

3. **Check file permissions:**
   ```bash
   ls -l /path/to/send-agent-call-reminders.php
   chmod +x /path/to/send-agent-call-reminders.php
   ```

4. **Verify cron service is running:**
   ```bash
   systemctl status cron
   ```

---

## 📝 Database Schema

### `agent_call_reminders_sent` Table

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT | Primary key |
| `agent_id` | INT | FK to users.id |
| `agent_name` | VARCHAR(100) | Agent's name |
| `agent_phone` | VARCHAR(20) | Agent's phone number |
| `reminder_date` | DATE | Date reminder was sent |
| `appointment_date` | DATE | Date of appointments |
| `appointment_count` | INT | Number of appointments |
| `appointment_list` | TEXT | JSON array of appointment details |
| `sent_at` | DATETIME | Timestamp |
| `channel` | ENUM | 'whatsapp', 'sms', 'both' |
| `message_preview` | VARCHAR(500) | First 500 chars |
| `provider_message_id` | VARCHAR(100) | UltraMSG message ID |
| `status` | ENUM | 'sent', 'delivered', 'failed' |

**Unique Constraint:** `(agent_id, appointment_date)` - Prevents duplicate reminders

---

## 🔐 Security

- ✅ Cron key authentication for web access
- ✅ CLI detection for direct execution
- ✅ Environment variable for sensitive data
- ✅ SQL injection prevention (prepared statements)
- ✅ Phone number validation and normalization

---

## 🎯 Future Enhancements

- [ ] SMS fallback if WhatsApp fails
- [ ] Configurable reminder time (not just 22:00)
- [ ] Agent-specific preferences (opt-out)
- [ ] Delivery confirmation tracking
- [ ] Reminder for same-day appointments (morning reminder)

---

## 📞 Support

If you encounter issues:
1. Check the troubleshooting section above
2. Review cron logs
3. Test manually with verbose output
4. Contact system administrator

---

**Last Updated:** December 2024  
**Version:** 1.0.0
