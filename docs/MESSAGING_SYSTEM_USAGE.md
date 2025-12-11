# Unified Messaging System - Usage Guide

## Overview

The `MessagingHelper` class provides a unified interface for sending messages via **SMS** and **WhatsApp**. It intelligently selects the best channel based on donor preferences, availability, and fallback logic.

## Quick Start

```php
require_once __DIR__ . '/../services/MessagingHelper.php';

$db = // your database connection
$msg = new MessagingHelper($db);

// Send using template (auto-selects best channel)
$result = $msg->sendFromTemplate(
    'payment_reminder_3day',
    $donorId,
    ['name' => 'John', 'amount' => '£50', 'due_date' => '2025-01-15']
);

// Send direct message
$result = $msg->sendDirect(
    '07123456789',
    'Hello! Your payment is due soon.',
    'auto' // or 'sms', 'whatsapp', 'both'
);

// Send to donor (auto-detects phone and best channel)
$result = $msg->sendToDonor(
    $donorId,
    'Thank you for your payment!',
    'auto'
);
```

## Channel Selection

### Automatic Mode (`'auto'`)
- Checks if donor has WhatsApp preference
- Verifies WhatsApp availability for the number
- Falls back to SMS if WhatsApp unavailable
- Uses SMS if WhatsApp service is down

### Explicit Channels
- `'sms'` - Send via SMS only
- `'whatsapp'` - Send via WhatsApp only  
- `'both'` - Send via both channels

## Template System

Templates support multi-language (English, Amharic, Tigrinya) and variables:

```php
// Template variables are automatically replaced
$result = $msg->sendFromTemplate(
    'payment_reminder_3day',
    $donorId,
    [
        'name' => 'John Doe',        // Auto-filled from donor if not provided
        'amount' => '£50',
        'due_date' => '15 Jan 2025'
    ]
);
```

## Response Format

```php
[
    'success' => true,
    'channel' => 'whatsapp',  // or 'sms', 'both'
    'message' => 'Message sent successfully',
    'message_id' => '12345',  // Provider message ID
    // For 'both' channel:
    'sms' => [...],
    'whatsapp' => [...]
]
```

## Error Handling

```php
$result = $msg->sendDirect($phone, $message);

if (!$result['success']) {
    echo "Error: " . $result['error'];
    // Automatically falls back to alternative channel if available
}
```

## System Status

```php
$status = $msg->getStatus();

// Returns:
[
    'sms_available' => true,
    'whatsapp_available' => true,
    'sms_errors' => [],
    'whatsapp_status' => ['success' => true, 'status' => 'authenticated'],
    'initialized' => true,
    'errors' => []
]
```

## Integration Examples

### Payment Reminder
```php
$msg = new MessagingHelper($db);

// Send 3-day reminder
$msg->sendFromTemplate(
    'payment_reminder_3day',
    $donorId,
    ['amount' => $amount, 'due_date' => $dueDate],
    'auto',  // Smart channel selection
    'payment_reminder'
);
```

### Call Center Follow-up
```php
// After a call, send confirmation via preferred channel
$msg->sendToDonor(
    $donorId,
    "Thank you for speaking with us today. Your payment plan has been updated.",
    'auto'
);
```

### Bulk Campaign
```php
// Send to multiple donors
foreach ($donors as $donor) {
    $msg->sendFromTemplate(
        'event_announcement',
        $donor['id'],
        ['event_name' => 'Church Festival', 'date' => '2025-02-15'],
        'both'  // Send via both channels for maximum reach
    );
}
```

## Features

✅ **Intelligent Channel Selection** - Automatically picks best channel  
✅ **WhatsApp Number Detection** - Checks if number has WhatsApp (cached)  
✅ **Automatic Fallback** - Falls back to SMS if WhatsApp fails  
✅ **Multi-language Support** - Uses donor's preferred language  
✅ **Template System** - Reusable message templates  
✅ **Queue Support** - Can queue messages for later sending  
✅ **Error Handling** - Graceful error handling with fallbacks  
✅ **Logging** - All messages logged to database  

## Database Requirements

Run the migration script to add required columns:

```sql
-- See: database/messaging_system_migration.sql
```

This adds:
- `preferred_message_channel` column to `donors` table
- `whatsapp_number_cache` table for performance

## Best Practices

1. **Use 'auto' channel** for most cases - let the system decide
2. **Use templates** instead of direct messages when possible
3. **Check system status** before bulk sends
4. **Handle errors gracefully** - system will auto-fallback
5. **Respect quiet hours** - SMS helper handles this automatically

## Troubleshooting

### WhatsApp not working?
```php
$status = $msg->getStatus();
if (!$status['whatsapp_available']) {
    // Check WhatsApp service configuration
    // System will auto-fallback to SMS
}
```

### SMS not working?
```php
if (!$msg->isSMSAvailable()) {
    // Check SMS provider configuration
    // System will try WhatsApp if available
}
```

## Migration from Old Code

### Before (SMS only):
```php
$sms = new SMSHelper($db);
$sms->sendFromTemplate('template_key', $donorId, $vars);
```

### After (Unified):
```php
$msg = new MessagingHelper($db);
$msg->sendFromTemplate('template_key', $donorId, $vars, 'auto');
```

The unified helper is backward compatible - it uses `SMSHelper` internally for SMS operations.

