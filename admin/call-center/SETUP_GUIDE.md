# 🚀 Call Center Setup Guide

## Prerequisites

Before using the Call Center system, ensure:

✅ Database tables are created (you ran the SQL script)  
✅ You have admin or registrar access  
✅ Donors exist in the system with pledges  
✅ PHP 8.4+ is running  
✅ MariaDB 10.11+ is active  

---

## Step 1: Verify Database Tables

Run this query to check if tables exist:

```sql
SHOW TABLES LIKE 'call_center%';
```

You should see:
- `call_center_sessions`
- `call_center_queues`
- `call_center_campaigns`
- `call_center_sms_log`
- `call_center_sms_templates`
- `call_center_agent_stats`
- `call_center_conversation_steps`
- `call_center_responses`
- `call_center_objections`
- `call_center_attempt_log`
- `call_center_special_circumstances`
- `call_center_contact_verification`
- `call_center_disposition_rules`
- `call_center_workflow_rules`
- `call_center_workflow_executions`

Also check:
- `churches` table
- `donors` table (should already exist)

---

## Step 2: Populate Initial Queue

To get started, you need donors in the call queue. Run this SQL:

```sql
-- Add all donors with outstanding balances to the queue
INSERT INTO call_center_queues (donor_id, queue_type, priority, status, reason_for_queue, created_at)
SELECT 
    id,
    'overdue_pledges' as queue_type,
    CASE 
        WHEN balance >= 500 THEN 10
        WHEN balance >= 200 THEN 7
        ELSE 5
    END as priority,
    'pending' as status,
    CONCAT('Outstanding balance: £', balance) as reason_for_queue,
    NOW() as created_at
FROM donors 
WHERE balance > 0 
AND donor_type = 'pledge'
AND NOT EXISTS (
    SELECT 1 FROM call_center_queues 
    WHERE donor_id = donors.id AND status = 'pending'
);
```

This will:
- Add all donors with outstanding balances
- Set priority based on amount (higher balance = higher priority)
- Avoid duplicates

---

## Step 3: Access the Call Center

1. Log in to admin panel
2. Look for **"Call Center"** in sidebar under **Operations**
3. Click to access dashboard

You should see:
- Today's statistics (will be zero initially)
- Call queue with donors
- Empty recent activity
- No callbacks yet

---

## Step 4: Make Your First Test Call

1. Click **"Start Call"** on any donor
2. You'll see the make-call screen with:
   - Donor information (right sidebar)
   - Conversation script (main area)
   - Call timer
   - Outcome form

3. **Test the timer:**
   - Click "Start Timer"
   - Wait a few seconds
   - Click "End Call"

4. **Fill out the form:**
   - Select an outcome (try "no_answer" for testing)
   - Choose conversation stage: "no_connection"
   - Add notes: "Test call to verify system"
   - Select disposition: "retry_next_day"

5. Click **"Save Call Record"**

6. You should be redirected to dashboard with success message

---

## Step 5: Verify Everything Works

### Check Dashboard
- Stats should show: 1 call today
- Recent activity should show your test call
- Queue should be updated

### Check Call History
- Go to Call History
- Your test call should appear
- Click eye icon to view details

### Check Database
Run this to see your call:

```sql
SELECT * FROM call_center_sessions ORDER BY id DESC LIMIT 1;
```

---

## Step 6: Configure Initial Data (Optional)

### Add SMS Templates

```sql
INSERT INTO call_center_sms_templates (template_key, template_name, message_en, category, is_active, created_by)
VALUES 
('welcome', 'Welcome SMS', 'Hello {name}, thank you for your pledge to Abune Teklehaymanot EOTC. Access your donor portal: https://yoursite.com/donor/login.php', 'welcome', 1, 1),
('callback_reminder', 'Callback Reminder', 'Hello {name}, we tried to reach you today. We will call you back on {callback_date}. - ATEOTC Liverpool', 'reminder', 1, 1),
('payment_due', 'Payment Reminder', 'Hello {name}, your payment of £{amount} is due. You can pay online or at church. Thank you! - ATEOTC', 'payment', 1, 1);
```

### Add UK Cities (for donor filtering)

```sql
-- Common UK cities where donors might be
-- You'll add specific churches later based on actual locations
INSERT INTO churches (name, city, is_active) VALUES
('Abune Teklehaymanot EOTC', 'Liverpool', 1),
('Manchester Community', 'Manchester', 1),
('Birmingham Community', 'Birmingham', 1),
('London Community', 'London', 1),
('Glasgow Community', 'Glasgow', 1),
('Cardiff Community', 'Cardiff', 1);
```

---

## Step 7: Train Your Call Agents

Share with them:
1. The README.md file
2. Best calling times (6-8 PM weekdays)
3. The conversation script
4. How to record outcomes
5. Importance of detailed notes

---

## 🎯 Quick Testing Checklist

- [ ] Can access Call Center from sidebar
- [ ] Dashboard loads without errors
- [ ] Queue shows donors
- [ ] Can click "Start Call" 
- [ ] Timer works
- [ ] Can select all outcomes
- [ ] Can save call record
- [ ] Redirects to dashboard after save
- [ ] Stats update correctly
- [ ] Call appears in history
- [ ] Can filter call history
- [ ] Can view call details in modal
- [ ] Sidebar navigation works

---

## 🐛 Common Issues & Fixes

### Issue: "No donors in queue"
**Fix:** Run Step 2 SQL to populate queue

### Issue: "Failed to record call"
**Fix:** Check database connection and user permissions

### Issue: "Page not found"
**Fix:** Ensure all files are in `admin/call-center/` directory

### Issue: "Sidebar link not working"
**Fix:** Clear browser cache, check sidebar.php was updated

### Issue: "Stats showing wrong numbers"
**Fix:** Check server timezone matches database timezone

---

## 📞 Ready to Use!

Once all steps are complete:

1. ✅ Database tables exist
2. ✅ Queue is populated
3. ✅ Test call completed successfully
4. ✅ Stats are updating
5. ✅ History is tracking

You're ready to start systematic donor outreach! 🎉

---

## 🔄 Daily Maintenance

### Every Morning:
- Check callback list
- Review yesterday's stats
- Replenish queue if needed

### Every Week:
- Review agent performance
- Check special circumstances
- Update donor information
- Follow up on escalations

### Every Month:
- Run performance reports
- Update SMS templates
- Train new agents
- Review and improve scripts

---

## 📊 Monitoring Performance

### Key Metrics to Watch:
- **Conversion Rate**: Aim for 15-25%
- **Contact Rate**: Aim for 40-50%
- **Average Call Duration**: 3-5 minutes
- **Callbacks Completed**: 80%+

### Red Flags:
- Conversion rate < 10% (script may need improvement)
- Contact rate < 30% (wrong times, bad numbers)
- Too many "not interested" (may be donor fatigue)
- Too many "no answer" (calling at wrong times)

---

## 🆘 Need Help?

If something doesn't work:

1. Check PHP error logs
2. Check MariaDB error logs  
3. Verify file permissions
4. Check database connection
5. Review README.md
6. Contact system administrator

---

**System is ready! Let's collect those pledges! 💪**

