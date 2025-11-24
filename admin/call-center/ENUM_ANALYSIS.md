# Call Center ENUM Analysis: Do We Need Database Changes?

## Current Database ENUM Values

### `outcome` ENUM (57 values):
```
'no_answer', 'busy_signal', 'number_not_in_service', 'number_disconnected', 
'invalid_number', 'voicemail_full', 'voicemail_left', 'voicemail_no_message', 
'network_error', 'call_failed_technical', 'wrong_number', 'wrong_person', 
'number_changed', 'answered_hung_up_immediately', 'answered_language_barrier', 
'answered_cannot_hear', 'answered_poor_connection', 'call_dropped_before_talk', 
'call_dropped_during_talk', 'busy_call_back_later', 'at_work_cannot_talk', 
'driving_cannot_talk', 'with_family_cannot_talk', 'in_meeting_cannot_talk', 
'not_feeling_well', 'not_interested', 'never_pledged_denies', 'already_paid_claims', 
'hostile_angry', 'threatened_complaint', 'requested_no_more_calls', 
'suspected_scam_refused', 'interested_needs_time', 'interested_check_finances', 
'interested_discuss_with_family', 'interested_wants_details_by_sms', 
'interested_wants_details_by_email', 'will_call_back_themselves', 
'financial_hardship', 'lost_job_cannot_pay', 'medical_emergency', 
'family_emergency', 'moved_abroad', 'moved_to_different_city', 'changed_church', 
'donor_deceased', 'donor_hospitalized', 'donor_elderly_confused', 
'payment_plan_created', 'agreed_to_pay_full', 'agreed_reduced_amount', 
'agreed_cash_collection', 'payment_made_during_call', 'transferred_to_supervisor', 
'scheduled_church_meeting'
```

### `conversation_stage` ENUM (7 values):
```
'no_connection', 'connected_no_identity_check', 'identity_verified', 
'pledge_discussed', 'payment_options_discussed', 'agreement_reached', 'plan_finalized'
```

---

## Call Center Scenarios Mapping

### Scenario 1: No Answer
- **Code uses:** `status=not_picked_up`
- **Current mapping:** `outcome='no_answer'` ✅ EXISTS
- **Stage:** `'no_connection'` ✅ EXISTS
- **Status:** ✅ COVERED

### Scenario 2: Busy Signal
- **Code uses:** `status=busy`
- **Current mapping:** `outcome='busy_signal'` ✅ EXISTS
- **Stage:** `'no_connection'` ✅ EXISTS
- **Status:** ✅ COVERED

### Scenario 3: Picked Up but Can't Talk Now (`status=busy_cant_talk`)
- **Reasons from callback-reason.php:**
  - `driving` → `outcome='driving_cannot_talk'` ✅ EXISTS
  - `at_work` → `outcome='at_work_cannot_talk'` ✅ EXISTS
  - `with_family` → `outcome='with_family_cannot_talk'` ✅ EXISTS
  - `eating` → `outcome='busy_call_back_later'` ✅ EXISTS
  - `sleeping` → `outcome='not_feeling_well'` or `'busy_call_back_later'` ✅ EXISTS
  - `bad_time` → `outcome='busy_call_back_later'` ✅ EXISTS
  - `requested_later` → `outcome='busy_call_back_later'` ✅ EXISTS
  - `other` → `outcome='busy_call_back_later'` ✅ EXISTS
- **Stage:** `'connected_no_identity_check'` ✅ EXISTS (contact was made)
- **Status:** ✅ COVERED

### Scenario 4: Not Ready to Pay (`status=not_ready_to_pay`)
- **Current code tries:** `outcome='not_ready_to_pay'` ❌ NOT EXISTS
- **Should use:** `outcome='interested_needs_time'` ✅ EXISTS
- **Stage:** `'connected_no_identity_check'` ✅ EXISTS
- **Status:** ✅ COVERED (needs code fix)

### Scenario 5: Payment Plan Created
- **Current mapping:** `outcome='payment_plan_created'` ✅ EXISTS
- **Stage:** Should be `'plan_finalized'` ✅ EXISTS (not `'completed'`)
- **Status:** ✅ COVERED (needs code fix)

### Scenario 6: Number Invalid/Not Working
- **Current mapping:** Multiple options exist ✅
  - `'number_not_in_service'`
  - `'number_disconnected'`
  - `'invalid_number'`
  - `'wrong_number'`
  - `'network_error'`
- **Stage:** `'no_connection'` ✅ EXISTS
- **Status:** ✅ COVERED

### Scenario 7: Picked Up and Can Talk
- **Current code tries:** `conversation_stage='connected'` or `'connection_made'` ❌ NOT EXISTS
- **Should use:** `'connected_no_identity_check'` ✅ EXISTS
- **Status:** ✅ COVERED (needs code fix)

---

## Invalid Values Currently Used in Code

| Code Value | Should Be | ENUM Exists? |
|------------|-----------|--------------|
| `'connection_made'` | `'connected_no_identity_check'` | ✅ YES |
| `'connected'` | `'connected_no_identity_check'` | ✅ YES |
| `'callback_requested'` | `'busy_call_back_later'` or reason-specific | ✅ YES |
| `'not_ready_to_pay'` | `'interested_needs_time'` | ✅ YES |
| `'callback_scheduled'` | `'connected_no_identity_check'` (stage) | ✅ YES |
| `'completed'` | `'plan_finalized'` | ✅ YES |

---

## Conclusion

✅ **NO DATABASE CHANGES NEEDED!**

The existing ENUM values are **comprehensive** and cover **all call center scenarios**. The issue is that the **code is using invalid values** that don't match the database schema.

**Action Required:** Fix the code to use the correct existing ENUM values, not update the database.

---

## Recommended Code Fixes

1. **availability-check.php** (line 30):
   - Change: `'connection_made'` → `'connected_no_identity_check'`

2. **conversation.php** (lines 30, 39, 53):
   - Change: `'connected'` → `'connected_no_identity_check'`

3. **schedule-callback.php** (lines 57, 248):
   - Change: `'callback_requested'` → Map to reason-specific outcomes:
     - `driving` → `'driving_cannot_talk'`
     - `at_work` → `'at_work_cannot_talk'`
     - `with_family` → `'with_family_cannot_talk'`
     - Others → `'busy_call_back_later'`

4. **schedule-callback.php** (lines 58, 249):
   - Change: `'not_ready_to_pay'` → `'interested_needs_time'`

5. **schedule-callback.php** (lines 65-66, 257-258):
   - Change: `'callback_scheduled'` → `'connected_no_identity_check'` (stage)

6. **schedule-callback.php** (lines 63-64, 255-256):
   - Change: `'no_answer'`, `'busy_signal'` → `'no_connection'` (stage)

7. **process-conversation.php** (line 339):
   - Change: `'completed'` → `'plan_finalized'`

8. **edit-payment-plan-flow.php** (line 70):
   - Change: `'connected'` → `'connected_no_identity_check'`

