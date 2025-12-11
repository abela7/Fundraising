# Comprehensive Message Tracking System

## Overview

Every SMS and WhatsApp message sent from the system is now **comprehensively tracked** in a unified `message_log` table. This provides complete audit trails, donor communication history, and activity monitoring.

## What Gets Tracked

### For Every Message:

✅ **Recipient Information**
- Donor ID (if known)
- Phone number (normalized)
- Recipient name (snapshot at send time)

✅ **Message Content**
- Full message text
- Language (en/am/ti)
- Message length
- SMS segments (for SMS)

✅ **Template Information**
- Template ID and key
- Template variables used (JSON)

✅ **Sender Information** (WHO SENT IT)
- User ID who sent it
- User name (snapshot)
- User role (snapshot)
- IP address (for manual sends)
- User agent (for manual sends)

✅ **Source/Context**
- Source type (manual, payment_reminder, call_center, cron, etc.)
- Related entity ID (plan_id, session_id, campaign_id)
- Human-readable reference

✅ **Provider Details**
- Provider ID and name
- Provider message ID
- Raw API response

✅ **Status & Delivery**
- Status (sent, delivered, read, failed, etc.)
- Sent timestamp
- Delivered timestamp
- Read timestamp (WhatsApp)
- Failed timestamp
- Error codes and messages

✅ **Cost Tracking**
- Cost in pence
- Currency

✅ **Additional Context**
- Queue ID (if queued)
- Call session ID (if from call center)
- Campaign ID (if bulk campaign)
- Fallback flag (if WhatsApp failed → SMS)

## Database Structure

### Main Table: `message_log`

```sql
-- See: database/unified_message_logging.sql
```

**Key Features:**
- Unified table for SMS + WhatsApp
- 37 comprehensive fields
- Indexed for fast queries by donor_id, phone, channel, status, sent_at
- Foreign keys to donors, users, templates

### Views Created:

1. **`v_donor_message_history`** - Quick view of all messages for a donor
2. **`v_user_message_activity`** - Summary of messages sent by each user
3. **`v_donor_communication_summary`** - Communication stats per donor

## Usage

### Sending Messages (Automatic Logging)

All messages sent through `MessagingHelper` are automatically logged:

```php
$msg = new MessagingHelper($db);

// This automatically logs to message_log table
$result = $msg->sendFromTemplate(
    'payment_reminder_3day',
    $donorId,
    ['name' => 'John', 'amount' => '£50'],
    'auto'
);

// Log entry includes:
// - Your user ID (from session)
// - Donor ID
// - Message content
// - Channel used
// - Status
// - Timestamp
// - Everything else!
```

### Getting Donor Message History

```php
$msg = new MessagingHelper($db);

// Get all messages for a donor
$history = $msg->getDonorMessageHistory($donorId);

// Filter by channel
$smsOnly = $msg->getDonorMessageHistory($donorId, 50, 0, 'sms');
$whatsappOnly = $msg->getDonorMessageHistory($donorId, 50, 0, 'whatsapp');

// Get statistics
$stats = $msg->getDonorMessageStats($donorId);
// Returns: total_messages, sms_count, whatsapp_count, delivered_count, 
//          failed_count, total_cost_pence, last_message_at
```

### Viewing in UI

Visit: `/admin/donor-management/message-history.php?donor_id=123`

Shows:
- Statistics cards (total, SMS, WhatsApp, delivered)
- Filterable table (by channel, limit)
- Complete message details
- Who sent it, when, status, delivery time
- Cost tracking

## Tracking User Activity

The system automatically captures:
- **Current user** from session (via `current_user()` function)
- **IP address** from `$_SERVER['REMOTE_ADDR']`
- **User agent** from `$_SERVER['HTTP_USER_AGENT']`

For cron jobs or API calls, set user manually:

```php
$msg = new MessagingHelper($db);
$msg->setCurrentUser($userId, $userData);

// Now all messages will be logged with this user ID
$msg->sendDirect($phone, $message);
```

## Query Examples

### Get all messages sent by a specific user

```sql
SELECT * FROM message_log 
WHERE sent_by_user_id = 5 
ORDER BY sent_at DESC;
```

### Get all failed messages

```sql
SELECT * FROM message_log 
WHERE status = 'failed' 
ORDER BY failed_at DESC;
```

### Get messages sent today

```sql
SELECT * FROM message_log 
WHERE DATE(sent_at) = CURDATE()
ORDER BY sent_at DESC;
```

### Get donor's last 10 messages

```sql
SELECT * FROM message_log 
WHERE donor_id = 123 
ORDER BY sent_at DESC 
LIMIT 10;
```

### Get cost summary by user

```sql
SELECT 
    sent_by_user_id,
    sent_by_name,
    COUNT(*) as total_sent,
    SUM(cost_pence) as total_cost_pence
FROM message_log
WHERE sent_by_user_id IS NOT NULL
GROUP BY sent_by_user_id, sent_by_name
ORDER BY total_cost_pence DESC;
```

## Integration Points

### From Call Center

```php
// Messages sent from call center automatically include:
// - call_session_id
// - source_type = 'call_center_manual'
// - sent_by_user_id = current agent
```

### From Payment Reminders

```php
// Cron job sends reminders:
// - source_type = 'payment_reminder'
// - source_id = payment_plan_schedule.id
// - sent_by_user_id = NULL (system)
```

### From Admin Manual Send

```php
// Admin sends manually:
// - source_type = 'manual'
// - sent_by_user_id = admin user ID
// - ip_address = admin's IP
// - user_agent = admin's browser
```

## Migration

Run the SQL migration to create the table:

```sql
-- See: database/unified_message_logging.sql
```

This creates:
- `message_log` table
- 3 helpful views
- All necessary indexes

## Benefits

✅ **Complete Audit Trail** - Know who sent what, when, and why  
✅ **Donor History** - See all communications with each donor  
✅ **User Activity** - Track what each user is sending  
✅ **Cost Tracking** - Monitor messaging costs  
✅ **Delivery Tracking** - See delivery times and read receipts  
✅ **Error Analysis** - Identify failed messages and reasons  
✅ **Compliance** - Full record for GDPR/data protection  
✅ **Analytics** - Query data for insights and reporting  

## Next Steps

1. **Run Migration**: Execute `database/unified_message_logging.sql`
2. **Start Using**: All messages sent via `MessagingHelper` are automatically logged
3. **View History**: Use `/admin/donor-management/message-history.php?donor_id=X`
4. **Add Links**: Link to message history from donor detail pages

## Example: Adding Link to Donor Page

In `admin/donor-management/donor.php`, add:

```php
<a href="message-history.php?donor_id=<?= $donor['id'] ?>" class="btn btn-info">
    <i class="fas fa-envelope me-1"></i> Message History
</a>
```

This will show all SMS and WhatsApp messages sent to that donor!

