# Custom Number Dial Feature

## Overview

A new feature has been added to the Call Center that allows agents to dial any phone number without requiring a registered donor record. This is useful for calling:
- Prospects who haven't registered yet
- Unregistered contacts
- Partners or external stakeholders
- Anyone not in the donor database

## Files Added

### 1. **`admin/call-center/dial-custom.php`**
   - Main UI page for dialing custom numbers
   - Features:
     - Phone number input field
     - Interactive dial pad (1-9, *, #, 0)
     - "Call Now" button to initiate the call
     - Support for manual phone entry or dial pad
     - Input validation for UK phone numbers

### 2. **`admin/call-center/api/custom-call-initiate.php`**
   - API endpoint that initiates custom calls via Twilio
   - Similar to the existing `twilio-initiate-call.php` but doesn't require a donor record
   - Creates a call session in the database
   - Initiates the 2-leg Twilio call (agent's phone rings first, then connects to recipient)

### 3. **`admin/call-center/custom-call-status.php`**
   - Call status page for custom calls
   - Displays:
     - Call timer (running duration)
     - Call status (Ringing, Connected, Completed, Failed)
     - Call outcome buttons (Connected, No Answer, Busy, Invalid)
     - Notes field for documenting the call
   - Auto-polls Twilio for call status updates
   - Saves call outcome and notes when finished

### 4. **Updated `admin/call-center/index.php`**
   - Added "Dial Custom Number" button to Quick Actions
   - Links directly to `dial-custom.php`
   - Uses a distinctive yellow icon to differentiate from donor calls

## How It Works

### Call Flow
```
Agent clicks "Dial Custom Number" in dashboard
    ↓
Enters phone number (or uses dial pad)
    ↓
Clicks "Call Now"
    ↓
Twilio API creates a call session in database
    ↓
Agent's phone rings
    ↓
Agent answers their phone
    ↓
Twilio automatically connects agent to the recipient's number
    ↓
Call timer starts tracking duration
    ↓
Agent can end call and log outcome + notes
```

### Database Integration

The feature uses the existing `call_center_sessions` table. When a custom call is made:
- **No donor record needed** - the `donor_id` field remains NULL
- **Phone number stored** in `donor_phone_number` column
- **Agent info** is recorded (agent_id, agent_phone_number)
- **Call tracking** - timing, duration, status, and notes are recorded

#### Optional Database Enhancements

For better custom call tracking, you can add these columns to `call_center_sessions`:

```sql
ALTER TABLE call_center_sessions ADD COLUMN is_custom_call TINYINT DEFAULT 0;
```

This allows you to easily filter custom calls in reports. The code will work without this column, but it provides better data organization.

## Usage

### For Agents

1. **From Dashboard**: Click "Dial Custom Number" in Quick Actions
2. **Enter Phone Number**:
   - Type directly in the input field, OR
   - Use the dial pad buttons (click 1-9, *, #, 0)
   - Format: `07XXXXXXXXX` or `+447XXXXXXXXX` for UK numbers
3. **Click "Call Now"**
   - Your phone will ring (Twilio will call you first)
   - Answer your phone when it rings
   - You'll be connected to the recipient
4. **During/After Call**:
   - View live call timer
   - See call status updates
   - Enter call outcome (Connected, No Answer, Busy, Invalid)
   - Add notes about the conversation
5. **Save & Continue**:
   - Click "Save Call Details" to log the call
   - Option to make another call or return to dashboard

## Key Features

✅ **No Registration Required** - Call anyone instantly
✅ **Familiar Interface** - Same look/feel as donor calls
✅ **Interactive Dial Pad** - Quick number entry
✅ **Live Call Timer** - Track call duration
✅ **Outcome Tracking** - Log what happened
✅ **Call Notes** - Document the conversation
✅ **History** - All custom calls saved in database
✅ **Same Twilio Integration** - Uses your existing Twilio setup

## Technical Details

### Phone Number Validation

- Accepts UK mobile numbers: `07XXXXXXXXX`
- Accepts international format: `+447XXXXXXXXX` or `447XXXXXXXXX`
- Auto-adds `+44` country code if needed
- Validates format before calling

### Twilio Integration

- Uses the same Twilio service as donor calls (`TwilioService.php`)
- Displays your landing number as caller ID
- Supports call recording (if enabled in Twilio settings)
- Status callbacks tracked the same way as donor calls

### Call Session Tracking

Custom call sessions are stored with:
- `agent_id` - who made the call
- `agent_phone_number` - agent's phone
- `donor_phone_number` - the number called
- `call_started_at` - when call was initiated
- `twilio_call_sid` - Twilio's call identifier
- `twilio_status` - call status from Twilio
- `duration_seconds` - total call length
- `conversation_stage` - pending/contact_made/attempt_failed/etc
- `outcome` - how call ended (success/no_answer/busy/invalid)
- `notes` - agent's notes
- `is_custom_call` - 1 if custom call, 0 if donor call (optional column)

## Troubleshooting

### Call Won't Initiate
- Ensure Twilio is configured in admin settings
- Check agent phone number format (must be valid UK mobile)
- Verify target phone number is correct
- Check Twilio account has remaining credits

### Agent Phone Not Ringing
- Ensure phone volume is turned ON
- Check phone network connectivity
- Verify phone number is correct
- Try a test call to your own number first

### Connection Quality Issues
- Ensure good network connection
- Avoid WiFi if possible, use mobile data for better quality
- Check if multiple calls are happening simultaneously
- Reduce background noise if possible

### Call Not Recorded in History
- Check browser console for errors (F12)
- Ensure CSRF token is valid
- Check database connection
- Verify `call_center_sessions` table exists

## Future Enhancements

Possible improvements:
- [ ] Call scheduling for custom numbers
- [ ] Bulk call lists (import CSV of numbers)
- [ ] Call templates/scripts for custom calls
- [ ] SMS follow-up option after call
- [ ] Contact tagging/segmentation for custom calls
- [ ] Call analytics dashboard for custom calls
- [ ] Do Not Call list validation

## Security Notes

- All phone numbers are validated before sending to Twilio
- CSRF protection on all API endpoints
- User authentication required to access feature
- All calls logged with agent_id for accountability
- Twilio API credentials stored securely in database

## Support

If you encounter issues:
1. Check browser console (F12) for JavaScript errors
2. Check server logs for PHP errors
3. Verify Twilio configuration in admin settings
4. Test with a known working phone number
5. Clear browser cache and try again
