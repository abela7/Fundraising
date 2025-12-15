# Payment Reminder (2 Days Before) Setup Guide

## Overview
Automatically sends WhatsApp/SMS reminders to donors 2 days before their payment is due, with payment method-specific instructions.

## Files Created
1. `cron/send-payment-reminders-2day.php` - Cron job script
2. `sql/create_payment_reminder_2day_template.sql` - Template SQL

## Setup Steps

### 1. Create the Template
Run the SQL file to create the `payment_reminder_2day` template:

```bash
mysql -u your_user -p your_database < sql/create_payment_reminder_2day_template.sql
```

Or via phpMyAdmin: Import > `sql/create_payment_reminder_2day_template.sql`

### 2. Set Up Cron Job

#### Option A: Via cPanel Cron Jobs
1. Go to cPanel → Cron Jobs
2. Add new cron job:
   - **Minute**: 0
   - **Hour**: 8
   - **Day**: *
   - **Month**: *
   - **Weekday**: *
   - **Command**: `/usr/bin/php /home/YOUR_USER/public_html/Fundraising/cron/send-payment-reminders-2day.php`

#### Option B: Via Command Line (Linux/SSH)
```bash
crontab -e
```

Add this line:
```
0 8 * * * /usr/bin/php /path/to/Fundraising/cron/send-payment-reminders-2day.php
```

#### Option C: Via Web (with Cron Key)
Set environment variable `FUNDRAISING_CRON_KEY` on your server, then call:
```
https://yourdomain.com/cron/send-payment-reminders-2day.php?cron_key=YOUR_SECRET_KEY
```

### 3. Test the Cron Job Manually
```bash
php /path/to/cron/send-payment-reminders-2day.php
```

Check the log file:
```bash
tail -f logs/payment-reminders-2day-YYYY-MM-DD.log
```

## How It Works

### Trigger Logic
- Runs daily at 8:00 AM
- Finds all donors with `next_payment_due` = **today + 2 days**
- Only active payment plans (`status = 'active'`)
- Only donors with phone numbers

### Message Personalization
Each donor receives their own data:
- **Name**: First name
- **Amount**: Payment amount from their plan
- **Due Date**: In dd/mm/yyyy format
- **Payment Method**: Cash, Bank Transfer, or Card
- **Instructions**: Method-specific (see below)

### Payment Method Instructions

#### Cash
```
Please hand over the cash to [Representative Name] ([Phone])
```

#### Bank Transfer / Card
```
Bank: LMKATH, Account: 85455687, Sort Code: 53-70-44, Reference: [FirstNameDonorId]
```

### Channel Strategy
- **WhatsApp First**: Tries WhatsApp if available
- **SMS Fallback**: Falls back to SMS if WhatsApp fails
- Uses `MessagingHelper` for intelligent routing

### Template Variables

| Variable | Description | Example |
|----------|-------------|---------|
| `{name}` | Donor's first name | "Meseret" |
| `{amount}` | Payment amount | "£50.00" |
| `{due_date}` | Due date | "15/12/2025" |
| `{payment_method}` | Payment method | "Bank Transfer" |
| `{payment_instructions}` | Full instructions | "Bank: LMKATH, Account: 85455687..." |
| `{reference}` | Payment reference | "Meseret123" |
| `{account_name}` | Bank account name | "LMKATH" |
| `{account_number}` | Bank account number | "85455687" |
| `{sort_code}` | Sort code | "53-70-44" |
| `{frequency}` | Payment frequency | "per month" |
| `{portal_link}` | Donor portal link | "https://bit.ly/4p0J1gf" |

## Editing the Template

Go to **Admin → SMS → Templates** and edit the `Payment Reminder (2 Days Before)` template.

### English Version
```
Dear {name}, based on your payment plan, your next payment of {amount} is due on {due_date}. 
Payment method: {payment_method}. {payment_instructions}. Thank you! - Liverpool Abune Teklehaymanot Church
```

### Amharic Version (Example)
```
ውድ {name}, በክፍያ እቅድዎ መሰረት ቀጣዩ ክፍያዎ {amount} በ{due_date} መክፈል አለብዎት። 
የክፍያ ዘዴ: {payment_method}። {payment_instructions}። አመሰግናለሁ!
```

## Monitoring

### View Logs
```bash
tail -f logs/payment-reminders-2day-YYYY-MM-DD.log
```

### Check Message History
- Go to **Admin → SMS → Bulk Message History**
- Filter by source type: `cron_payment_reminder`

### Check Individual Donor
- Go to **Admin → Donor Management → View Donor**
- Check "Message History" section

## Customization

### Change Bank Details
Edit `cron/send-payment-reminders-2day.php` lines 58-62:
```php
$bankDetails = [
    'account_name' => 'YOUR_ACCOUNT_NAME',
    'account_number' => 'YOUR_ACCOUNT_NUMBER',
    'sort_code' => 'YOUR_SORT_CODE'
];
```

### Change Reminder Timing
To send reminders **3 days** before (instead of 2):
- Edit line 70: `strtotime('+2 days')` → `strtotime('+3 days')`

### Disable for Specific Donors
Set `sms_opt_in = 0` in the donor's profile.

## Troubleshooting

### No messages sent
- Check if template exists: `SELECT * FROM sms_templates WHERE template_key = 'payment_reminder_2day'`
- Check if donors have payment due in 2 days: `SELECT * FROM donor_payment_plans WHERE next_payment_due = DATE_ADD(CURDATE(), INTERVAL 2 DAY)`
- Check cron log file for errors

### Messages not delivered
- Check SMS/WhatsApp provider settings
- Check donor's phone number format
- Check blacklist: `SELECT * FROM sms_blacklist`

### Test with specific donor
Modify the SQL query in the cron job (line 82) to add:
```sql
AND pp.donor_id = 123  -- Replace with specific donor ID
```

Then run manually:
```bash
php cron/send-payment-reminders-2day.php
```

## Notes
- Messages use the donor's `preferred_language` setting
- Amharic and Tigrinya templates need translation (currently use English)
- Representative info only shows for cash payments (if assigned)
- Reference format: FirstNameDonorId (e.g., "Meseret123")
