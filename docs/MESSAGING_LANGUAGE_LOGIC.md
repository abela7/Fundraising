# Messaging Language Logic

## Overview
This document explains the language selection logic for WhatsApp and SMS messaging in the Fundraising system.

## Language Selection Rules

### WhatsApp Messages
When sending messages via WhatsApp, the system **always uses Amharic (am)** as the default language:

```
WhatsApp → Amharic (am) language
```

**Logic:**
1. WhatsApp message is sent using the `message_am` field from the template
2. If `message_am` is not available, falls back to `message_en`

### SMS Fallback
When WhatsApp fails and the system falls back to SMS, it **always uses English (en)**:

```
WhatsApp Failed → SMS Fallback → English (en) language
```

**Logic:**
1. WhatsApp send attempt fails
2. System automatically falls back to SMS
3. SMS is sent using the `message_en` field from the template (ignoring donor's preferred language)
4. The response includes metadata marking it as a fallback:
   - `is_fallback: true`
   - `fallback_reason: 'whatsapp_failed'`
   - `original_channel: 'whatsapp'`
   - `language: 'en'`

## Implementation Details

### Modified Files
1. **`services/MessagingHelper.php`**
   - `sendWhatsAppFromTemplate()` method now:
     - Uses `'am'` (Amharic) for WhatsApp messages
     - Passes `'en'` (English) to SMS fallback
     - Adds fallback metadata to response

2. **`services/SMSHelper.php`**
   - `sendFromTemplate()` method now accepts optional `$languageOverride` parameter
   - When provided, uses the override instead of donor's preferred language

## Template Structure

Templates in the `sms_templates` table support multiple languages:
- `message_en` - English version
- `message_am` - Amharic version
- `message_ti` - Tigrinya version

### Example Template
```sql
INSERT INTO sms_templates (
    template_key, 
    name, 
    message_en, 
    message_am
) VALUES (
    'payment_reminder',
    'Payment Reminder',
    'Dear {name}, this is a reminder about your payment.',
    'ውድ {name}, ይህ ስለ ክፍያዎ ማስታወሻ ነው።'
);
```

## Usage Examples

### Sending WhatsApp Message
```php
$messaging = new MessagingHelper($db);
$result = $messaging->sendFromTemplate(
    'payment_reminder',
    $donorId,
    ['name' => 'John']
);

// If WhatsApp succeeds:
// Result: { success: true, channel: 'whatsapp', language: 'am', ... }
// Message sent in Amharic

// If WhatsApp fails and falls back to SMS:
// Result: { 
//   success: true, 
//   channel: 'sms', 
//   language: 'en',
//   is_fallback: true,
//   fallback_reason: 'whatsapp_failed',
//   original_channel: 'whatsapp'
// }
// Message sent in English
```

## Rationale

### Why Amharic for WhatsApp?
- WhatsApp supports rich Unicode characters and Ethiopic script
- Better readability for Ethiopian donors
- Higher engagement with native language

### Why English for SMS Fallback?
- SMS reliability: Not all SMS gateways/carriers properly support Ethiopic script
- Character encoding issues with some older phones
- SMS segment costs: Ethiopic characters often require more segments (Unicode)
- Fallback safety: English is more universally supported across all networks

## Benefits

1. **Better User Experience**: Donors receive WhatsApp messages in their native language (Amharic)
2. **Reliability**: SMS fallback uses English to ensure message delivery across all networks
3. **Cost Efficiency**: English SMS uses fewer segments than Unicode (Amharic) SMS
4. **Transparency**: System clearly marks fallback messages with metadata
5. **Consistency**: Clear, predictable language selection logic

## Testing

To verify the logic:

1. **Test WhatsApp Success (Amharic)**:
   ```php
   $result = $messaging->sendFromTemplate('test_template', $donorId, []);
   // Verify: $result['channel'] === 'whatsapp' && $result['language'] === 'am'
   ```

2. **Test SMS Fallback (English)**:
   - Disconnect WhatsApp service
   - Send message
   - Verify: `$result['channel'] === 'sms' && $result['language'] === 'en' && $result['is_fallback'] === true`

## Future Enhancements

Possible improvements:
- Add per-donor language preference override for WhatsApp
- Support multiple SMS fallback languages based on donor preference
- Add Tigrinya (ti) support for WhatsApp
- Create language-specific templates for different regions

---

**Last Updated**: 2026-02-08  
**Version**: 1.0.0  
**Author**: Fundraising System Team
