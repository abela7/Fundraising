# Twilio Call Data & System Robustness Guide

## 📊 **Data Twilio Provides During Calls**

### **1. Status Callback Parameters (What We Currently Use)**

Currently captured in `twilio-status-callback.php`:

| Parameter | Description | Currently Used? |
|-----------|-------------|-----------------|
| `CallSid` | Unique call identifier | ✅ Yes |
| `AccountSid` | Twilio account ID | ✅ Yes (logged) |
| `CallStatus` | Call status (queued, ringing, in-progress, completed, etc.) | ✅ Yes |
| `From` | Calling number | ✅ Yes (logged) |
| `To` | Called number | ✅ Yes (logged) |
| `CallDuration` | Total call duration in seconds | ✅ Yes |
| `Direction` | Call direction (inbound/outbound) | ✅ Yes (logged) |

### **2. Additional Parameters Available (NOT Currently Captured)**

| Parameter | Description | Value for System |
|-----------|-------------|------------------|
| `CallDurationBillable` | Billable duration | 💰 Cost tracking |
| `Timestamp` | When status occurred | 🕐 Precise timing |
| `CallbackSource` | Where callback came from | 🔍 Debug info |
| `SequenceNumber` | Order of callbacks | 🔢 Event ordering |
| `ForwardedFrom` | Original caller ID if forwarded | 📞 Call routing |
| `CallerName` | Caller ID name | 👤 Caller info |
| `ParentCallSid` | Parent call if this is a child | 🔗 Call hierarchy |
| `ErrorCode` | Error code if failed | ❌ Error tracking |
| `ErrorMessage` | Error description | 📝 Debug info |

### **3. Recording Callback Parameters**

When a call is recorded (captured in `twilio-recording-callback.php`):

| Parameter | Description | Currently Used? |
|-----------|-------------|-----------------|
| `RecordingSid` | Unique recording ID | ❌ No |
| `RecordingUrl` | URL to recording file | ✅ Yes |
| `RecordingStatus` | Recording status | ❌ No |
| `RecordingDuration` | Recording length | ❌ No |
| `RecordingChannels` | Mono/stereo | ❌ No |
| `RecordingSource` | Source of recording | ❌ No |

### **4. Call Quality Metrics (Advanced)**

Available via Twilio API after call:

| Metric | Description | Value for System |
|--------|-------------|------------------|
| `audio_quality_score` | 1-5 quality rating | 📊 Call quality tracking |
| `mos_score` | Mean Opinion Score | 📈 Network quality |
| `jitter` | Audio jitter (ms) | 🔊 Connection quality |
| `packet_loss` | % of lost packets | 📉 Network issues |
| `rtt` | Round-trip time (ms) | ⏱️ Latency tracking |

---

## 🛡️ **Recommendations for System Robustness**

### **PRIORITY 1: Critical Improvements** 🔴

#### **1. Capture Error Codes & Messages**
**Why:** Know exactly why calls fail (wrong number, network issue, timeout, etc.)

**Implementation:**
```php
// In twilio-status-callback.php, add:
$errorCode = $_POST['ErrorCode'] ?? null;
$errorMessage = $_POST['ErrorMessage'] ?? null;

if ($callStatus === 'failed' || $callStatus === 'no-answer' || $callStatus === 'busy') {
    $updateFields['twilio_error_code'] = $errorCode;
    $updateFields['twilio_error_message'] = $errorMessage;
}
```

**Database:**
```sql
ALTER TABLE call_center_sessions 
ADD COLUMN twilio_error_code VARCHAR(10) NULL,
ADD COLUMN twilio_error_message VARCHAR(500) NULL;
```

#### **2. Track Billable Duration**
**Why:** Know exactly how much each call costs

**Implementation:**
```php
$billableDuration = isset($_POST['CallDurationBillable']) ? (int)$_POST['CallDurationBillable'] : null;
$updateFields['twilio_billable_duration'] = $billableDuration;
```

**Database:**
```sql
ALTER TABLE call_center_sessions 
ADD COLUMN twilio_billable_duration INT NULL COMMENT 'Billable seconds';
```

#### **3. Save Full Webhook Payload**
**Why:** Complete audit trail for debugging and compliance

**Current:** ✅ Already done in `twilio_webhook_logs` table

---

### **PRIORITY 2: Enhanced Features** 🟡

#### **4. Call Quality Tracking**
**Why:** Identify network issues affecting call quality

**Implementation:**
- Query Twilio Insights API after call completes
- Store quality metrics for reporting
- Alert if quality drops below threshold

#### **5. Recording Metadata**
**Why:** Better recording management

**Database:**
```sql
ALTER TABLE call_center_sessions 
ADD COLUMN twilio_recording_sid VARCHAR(100) NULL,
ADD COLUMN twilio_recording_duration INT NULL,
ADD COLUMN twilio_recording_status VARCHAR(20) NULL;
```

#### **6. Retry Logic for Failed Calls**
**Why:** Automatic retry for network failures

**Implementation:**
```php
// If call fails due to network (not wrong number), auto-retry
if ($errorCode === '31005' || $errorCode === '30001') { // Network errors
    // Schedule retry in 5 minutes
    $stmt = $db->prepare("
        UPDATE call_center_queues 
        SET next_attempt_after = DATE_ADD(NOW(), INTERVAL 5 MINUTE),
            status = 'pending'
        WHERE id = ?
    ");
}
```

---

### **PRIORITY 3: Advanced Features** 🟢

#### **7. Real-time Call Monitoring**
**Why:** Supervisors can listen to live calls

**Implementation:**
- Use Twilio Conference API
- Add "Monitor" button for supervisors
- Whisper mode (supervisor hears, donor doesn't)

#### **8. Call Queue & Callback**
**Why:** If donor is busy, offer callback

**Implementation:**
- Detect busy signal
- Offer automated callback scheduling
- Call donor when available

#### **9. Sentiment Analysis**
**Why:** Auto-detect donor mood from voice tone

**Implementation:**
- Use Twilio Voice Intelligence
- Capture sentiment scores
- Flag negative calls for supervisor review

#### **10. Call Transcription**
**Why:** Searchable call records, AI analysis

**Implementation:**
- Enable Twilio Speech Recognition
- Save transcripts to database
- Search call content

---

## 📈 **Monitoring & Alerts**

### **What to Monitor:**

1. **Call Success Rate**
   - Alert if < 80% calls connect

2. **Average Call Duration**
   - Alert if sudden drop (system issue?)

3. **Cost per Call**
   - Alert if costs spike unexpectedly

4. **Failed Call Patterns**
   - Alert if same error code repeats

5. **Recording Failures**
   - Alert if recordings not captured

---

## 🔒 **Security Enhancements**

### **1. Webhook Signature Validation**
**Why:** Ensure webhooks are actually from Twilio

```php
// Verify Twilio signature
$twilioSignature = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';
$requestUrl = 'https://donate.abuneteklehaymanot.org' . $_SERVER['REQUEST_URI'];
$expectedSignature = hash_hmac('sha1', $requestUrl, TWILIO_AUTH_TOKEN);

if (!hash_equals($expectedSignature, $twilioSignature)) {
    http_response_code(403);
    exit('Invalid signature');
}
```

### **2. IP Whitelist**
**Why:** Only accept webhooks from Twilio IPs

```php
$allowedIPs = [
    '54.172.60.0/23',
    '54.244.51.0/24',
    // ... all Twilio IPs
];

if (!in_array($_SERVER['REMOTE_ADDR'], $allowedIPs)) {
    http_response_code(403);
    exit('Unauthorized IP');
}
```

---

## 💰 **Cost Optimization**

### **1. Track Costs in Real-Time**
```sql
CREATE TABLE twilio_daily_costs (
    date DATE PRIMARY KEY,
    total_calls INT,
    total_duration_seconds INT,
    total_cost_gbp DECIMAL(10,2),
    avg_cost_per_call DECIMAL(10,2)
);
```

### **2. Set Budget Alerts**
- Alert if daily costs exceed £X
- Auto-disable if monthly budget exceeded

### **3. Optimize Call Routing**
- Use cheapest routes
- Batch calls during off-peak

---

## 🎯 **Quick Wins (Implement Now)**

1. ✅ **Capture error codes** (5 min)
2. ✅ **Track billable duration** (5 min)
3. ✅ **Webhook signature validation** (15 min)
4. ✅ **Daily cost tracking** (30 min)
5. ✅ **Recording metadata** (10 min)

---

## 📊 **Reporting Enhancements**

### **New Reports to Build:**

1. **Call Success Dashboard**
   - Success rate by time of day
   - Success rate by donor
   - Failure reasons breakdown

2. **Cost Analysis**
   - Cost per successful call
   - Cost trends over time
   - Most expensive campaigns

3. **Quality Metrics**
   - Average call quality score
   - Network issues frequency
   - Recording success rate

4. **Agent Performance**
   - Calls per agent
   - Success rate per agent
   - Average call duration

---

## 🔧 **Next Steps**

**Week 1:**
- Add error tracking
- Implement webhook validation
- Build cost tracking

**Week 2:**
- Add recording metadata
- Create quality monitoring
- Build success dashboard

**Week 3:**
- Implement retry logic
- Add supervisor monitoring
- Create alert system

**Week 4:**
- Add transcription (optional)
- Implement sentiment analysis (optional)
- Build advanced reports

---

**Would you like me to implement any of these enhancements now?** 🚀

