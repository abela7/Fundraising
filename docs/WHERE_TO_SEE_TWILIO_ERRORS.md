# 📊 Where to See Twilio Error Reports

## 🎯 Quick Answer:

**Go to:** **Call Center → Twilio Errors** (in sidebar)  
**Direct URL:** `admin/call-center/twilio-error-report.php`

---

## 📍 **4 Places to See Error Information:**

### **1. Twilio Error Report Page** 🔴 **PRIMARY**

**Location:** Sidebar → Call Center → Twilio Errors

**What You See:**
- ✅ **Summary Statistics**
  - Total Twilio calls
  - Successful calls (% success rate)
  - Failed calls (% failure rate)
  - Number of unique error types

- ✅ **Error Breakdown**
  - Each error type with count
  - Human-readable error explanation
  - Recommended action (retry, update number, skip, escalate)
  - Number of unique donors affected
  - Last occurrence time

- ✅ **Recent Failed Calls List** (Last 100)
  - Date & time of failed call
  - Donor name & phone
  - Agent name
  - Error code & category
  - Recommended action
  - Quick "Retry" button

**Perfect For:**
- 📊 Analyzing error patterns
- 🔍 Finding which donors to retry
- 🚨 Identifying systemic issues
- 📈 Tracking improvement over time

---

### **2. Call History Page** 🟡 **SECONDARY**

**Location:** Sidebar → Call Center → Call History

**What You See:**
- All calls (including Twilio calls)
- "Twilio" badge on Twilio calls
- Error details shown inline for failed calls:
  ```
  Outcome: No Answer [Twilio]
  ⚠️ Network Error: Network connection error
  ```

**Perfect For:**
- Reviewing individual call outcomes
- Mixed view of manual + Twilio calls
- Quick donor history check

---

### **3. Real-Time Toast Notifications** ⚡ **LIVE**

**Location:** Automatically shows during calls

**What You See:**
- When call fails, toast appears with:
  - Error category
  - Error message
  - What to do next

**Example:**
```
┌──────────────────────────────────┐
│ [×] Call Failed                  │
│ Network connection error - Retry │
└──────────────────────────────────┘
```

**Perfect For:**
- Immediate feedback during calls
- Real-time error awareness
- Quick decision making

---

### **4. Database (Advanced Users)** 💾 **TECHNICAL**

**Location:** phpMyAdmin → `call_center_sessions` table

**Columns:**
- `twilio_error_code` - Error code (e.g., "31005")
- `twilio_error_message` - Error message from Twilio
- `twilio_status` - Call status (failed, busy, no-answer, etc.)
- `call_source` - "twilio" vs "manual"

**Perfect For:**
- Custom SQL queries
- Advanced analytics
- Data export
- Debugging

---

## 🔍 **Common Scenarios:**

### **Scenario 1: "I want to see all busy/rejected calls"**

**Answer:** Go to **Twilio Error Report**
1. Click **Call Center** → **Twilio Errors** in sidebar
2. Look for:
   - Error Code `486` = Busy
   - Error Code `603` = Declined/Rejected
3. See count, affected donors, and when it happened
4. Click "Retry" button to call again

---

### **Scenario 2: "Which donors should I retry?"**

**Answer:** Go to **Twilio Error Report**
1. Filter by date range (if needed)
2. Look for errors with **"Retry" badge**
3. These are temporary errors worth retrying:
   - Network errors (31005)
   - Temporarily unavailable (480)
   - Queue overflow (30001)

---

### **Scenario 3: "I want to clean up bad phone numbers"**

**Answer:** Go to **Twilio Error Report**
1. Look for errors with **"Update Number" badge**
2. These indicate bad data:
   - Unknown destination (30005)
   - Invalid format (31002)
   - Number not found (33001/33002)
3. Export the list and update donor records

---

### **Scenario 4: "Track improvement over time"**

**Answer:** Use **Twilio Error Report** with date filters
1. Set date range to "This Month"
2. Note the failure rate (e.g., 15%)
3. Next month, compare the rate
4. Goal: Decrease failure rate over time

---

## 📊 **Sample Report View:**

```
==============================================
TWILIO ERROR REPORT
Period: Dec 1-31, 2025
==============================================

SUMMARY:
├─ Total Calls: 250
├─ Successful: 215 (86%)
├─ Failed: 35 (14%)
└─ Unique Error Types: 5

ERROR BREAKDOWN:

1. [486] Busy (12 calls)
   Category: Donor line is busy
   Action: RETRY - Schedule callback for later
   Affected: 10 donors
   Last: Dec 30, 3:45 PM

2. [31005] Network Error (8 calls)
   Category: Network connection error
   Action: RETRY - Temporary network issue
   Affected: 8 donors
   Last: Dec 29, 2:15 PM

3. [30005] Unknown Destination (6 calls)
   Category: Phone number does not exist
   Action: UPDATE - Verify phone number
   Affected: 6 donors
   Last: Dec 28, 11:30 AM

4. [603] Declined (5 calls)
   Category: Call declined by recipient
   Action: SKIP - Donor rejected call
   Affected: 5 donors
   Last: Dec 27, 9:20 AM

5. [480] Unavailable (4 calls)
   Category: Temporarily unavailable
   Action: RETRY - Phone may be off
   Affected: 4 donors
   Last: Dec 26, 4:50 PM
==============================================
```

---

## 🎯 **Action Items Based on Errors:**

### **For "Busy" Errors (486):**
✅ Schedule callback for later today or tomorrow
✅ Try different time of day
✅ Note preferred contact time if donor mentions it

### **For "Network Errors" (31005):**
✅ Retry immediately or within 5 minutes
✅ If persists, check Twilio status page
✅ Alert admin if multiple network errors

### **For "Bad Number" Errors (30005, 31002, 33001):**
✅ Flag donor record for review
✅ Contact via different method (SMS, email)
✅ Ask donor to update phone number
✅ Remove from call queue

### **For "Declined" Errors (603):**
✅ Mark as "Do Not Call"
✅ Try alternative contact method
✅ Note donor preference
✅ May indicate donor is upset

---

## 📈 **Best Practices:**

1. **Review Error Report Daily**
   - Check for new error patterns
   - Identify donors to retry
   - Clean up bad data

2. **Track Failure Rates**
   - Monitor weekly failure rate
   - Goal: Keep under 10%
   - Investigate if suddenly increases

3. **Act on Recommendations**
   - Retry "retryable" errors
   - Update "bad number" records
   - Escalate "escalate" issues

4. **Learn Error Patterns**
   - Note times when errors are common
   - Identify donor segments with issues
   - Adjust calling strategies

---

## 🚀 **Quick Links:**

| What You Want | Where to Go |
|---------------|-------------|
| **See all errors** | Twilio Error Report |
| **See specific call** | Call History → Click call |
| **Retry failed call** | Error Report → Click "Retry" |
| **Export error data** | Database → phpMyAdmin |
| **Live error alerts** | Automatic during calls |

---

## 💡 **Pro Tips:**

1. **Filter by Date** - Focus on recent errors first
2. **Sort by Count** - Fix most common errors first
3. **Group by Error Type** - See patterns clearly
4. **Export to Excel** - Share with team/supervisor
5. **Set Weekly Review** - Make it a routine

---

**Need help understanding a specific error code?**  
Check `services/TwilioErrorCodes.php` for full error catalog!

---

**Report not showing errors?**  
✅ Good news! All calls succeeded! 🎉

