# IVR Call Flow Documentation

## Overview
When someone calls your Twilio number, the system automatically detects if they're a registered donor or a new caller, and provides different menu options accordingly.

---

## 🔄 Call Flow Diagram

```
Caller Dials Twilio Number
         ↓
    [twilio-inbound-call.php]
         ↓
    Check if phone number exists in donors table
         ↓
    ┌─────────────┬─────────────┐
    │   DONOR     │  NON-DONOR  │
    │   Found     │  Not Found  │
    └─────┬───────┴──────┬──────┘
          │              │
    Donor Menu      General Menu
    (4 options)     (5 options)
```

---

## 👤 **DONOR CALL FLOW**

### Step 1: Call Received
- System checks caller's phone number against `donors` table
- If found → **Donor Flow**
- Call is logged in `twilio_inbound_calls` table with `is_donor = 1`

### Step 2: Welcome Message
```
"Welcome to Liverpool Mekane Kidusan Abune Teklehaymanot, 
 Ethiopian Orthodox Tewahedo Church."
 
 "Hello [Donor Name]. Thank you for calling us today."
```

### Step 3: Donor Menu Options
**Press 1** - Check Outstanding Balance
- Tells them their total pledge amount
- Shows how much they've paid
- Shows outstanding balance (or confirms fully paid)

**Press 2** - Make a Payment
- Shows outstanding balance
- Asks for payment method:
  - Press 1: Bank Transfer
  - Press 2: Cash Payment
  - Press 3: Back to main menu
- Records payment method selection

**Press 3** - Contact Church Member
- Sends SMS with church administrator contact details
- Also speaks the phone number aloud
- Updates `sms_sent = 1` in call record

**Press 4** - Repeat Menu
- Returns to main menu

---

## 🆕 **NON-DONOR / NEW CALLER FLOW**

### Step 1: Call Received
- Phone number NOT found in donors table
- System treats as **New Caller**
- Call is logged with `is_donor = 0`

### Step 2: Welcome Message
```
"Welcome to Liverpool Mekane Kidusan Abune Teklehaymanot, 
 Ethiopian Orthodox Tewahedo Church."
 
 "Thank you for calling us today. We are happy to assist you."
```

### Step 3: General Menu Options
**Press 1** - Learn About Our Church
- Verbal information about the church
- History, mission, and current building fund project
- Option to receive SMS with more info

**Press 2** - Receive SMS with Website Links
- Sends SMS with:
  - Main website: https://abuneteklehaymanot.org/
  - Donation website: https://donate.abuneteklehaymanot.org/
- Updates `sms_sent = 1` in call record

**Press 3** - How to Support/Donate
- Explains the building fund project
- Provides bank transfer details:
  - Bank: Barclays Bank
  - Account Name: Liverpool Abune Teklehaymanot EOTC
  - Sort Code: 20-61-31
  - Account Number: 30926233
- Repeats bank details for clarity
- Mentions donation website

**Press 4** - Get Church Administrator Contact
- Sends SMS with contact details:
  - Name: Liqe Tighuan Kesis Birhanu
  - Phone: +44 7473 822244
- Also speaks the phone number aloud
- Updates `sms_sent = 1` in call record

**Press 5** - Repeat Menu
- Returns to main menu

---

## 📊 **What Gets Logged**

Every call is automatically logged in `twilio_inbound_calls` table:

| Field | Description |
|-------|-------------|
| `call_sid` | Unique Twilio call ID |
| `caller_phone` | Caller's phone number |
| `donor_id` | Donor ID if found (NULL if new caller) |
| `donor_name` | Donor name if found |
| `is_donor` | 1 = Donor, 0 = New caller |
| `menu_selection` | Which option they selected (e.g., "donor_menu_1", "general_menu_2") |
| `payment_method` | If they selected payment method |
| `sms_sent` | 1 if SMS was sent, 0 otherwise |
| `whatsapp_sent` | 1 if WhatsApp was sent |
| `agent_followed_up` | 1 if admin marked as followed up |
| `created_at` | Timestamp of call |

---

## 🎯 **Current Features**

✅ **Automatic Donor Detection** - Recognizes registered donors by phone number  
✅ **Personalized Greeting** - Calls donors by name  
✅ **Balance Check** - Donors can check their pledge status  
✅ **Payment Options** - Donors can select payment method  
✅ **SMS Integration** - Sends contact info and website links  
✅ **Bank Details** - Provides donation bank account details  
✅ **Call Logging** - All calls tracked in database  
✅ **Menu Selection Tracking** - Records which options callers choose  

---

## 🔧 **Technical Details**

### Voice Settings
- **Voice Engine**: Google Neural (en-GB-Neural2-B)
- **Language**: British English
- **Natural Speech**: Uses neural voice for more natural conversation

### Timeouts
- **Menu Selection**: 5 minutes (300 seconds)
- **Payment Method**: 60 seconds

### Phone Number Normalization
- Converts `+44` format to `0` format for database lookup
- Handles both international and UK formats

### Error Handling
- All errors are logged to server error log
- Graceful fallback messages if something fails
- Always returns valid TwiML even on errors

---

## 📝 **Next Steps to Make Fully Functional**

1. ✅ **Call Logging** - Already working
2. ✅ **Donor Detection** - Already working  
3. ✅ **Menu Options** - Already working
4. ⚠️ **Payment Processing** - Payment method selection works, but actual payment recording needs integration
5. ⚠️ **SMS Delivery** - SMS sending works, but needs verification
6. ⚠️ **Call Status Updates** - Need to capture call duration and status from Twilio webhooks

---

## 🚀 **How to Test**

1. **Test as Donor**: Call from a registered donor's phone number
2. **Test as New Caller**: Call from an unregistered number
3. **Check Logs**: View calls in `admin/call-center/inbound-callbacks.php`
4. **Verify SMS**: Check if SMS messages are being sent successfully

---

## 📞 **Support**

If you need to modify the IVR flow:
- **Main Entry**: `admin/call-center/api/twilio-inbound-call.php`
- **Donor Menu**: `admin/call-center/api/twilio-ivr-donor-menu.php`
- **General Menu**: `admin/call-center/api/twilio-ivr-general-menu.php`
- **Payment Method**: `admin/call-center/api/twilio-ivr-payment-method.php`

