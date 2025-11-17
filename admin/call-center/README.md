# 📞 Call Center System

## Overview

A comprehensive **Call Management & Tracking System** for managing donor outreach efforts. This system helps agents systematically contact donors, track conversations, and manage follow-ups—all while maintaining detailed audit trails.

---

## 🎯 Key Features

### 1. **Smart Call Queue**
- **Prioritized donor list** - High-priority donors appear first
- **Automatic filtering** - Only shows donors ready to be called
- **Queue types**: New pledges, callbacks, follow-ups, overdue payments
- **Agent assignment** - Assign specific donors to specific agents

### 2. **Call Dashboard**
- **Real-time statistics** - Calls today, successful contacts, conversion rate
- **Talk time tracking** - Monitor agent productivity
- **Upcoming callbacks** - Never miss a scheduled follow-up
- **Recent activity feed** - See what's been happening

### 3. **Make Call Screen**
- **Donor profile sidebar** - All donor info at a glance
- **Pre-written conversation script** - Follow professional, tested approach
- **57 different outcomes** - Capture every possible scenario
- **Conversation stage tracking** - Know how far each call progressed
- **Built-in timer** - Track call duration automatically
- **Special flags** - Mark important circumstances (legal threats, deceased, etc.)

### 4. **Call History**
- **Complete audit trail** - Every call is logged
- **Advanced filters** - Search by outcome, date, agent, donor
- **Detailed notes** - Full conversation records
- **Timeline view** - See donor's complete interaction history

### 5. **Campaign Management**
- **Organize calling efforts** - Group donors by campaign
- **Track progress** - Monitor goals vs actual results
- **Performance metrics** - See which campaigns work best

---

## 📁 File Structure

```
admin/call-center/
├── index.php              # Main dashboard with call queue
├── make-call.php          # Active call recording screen
├── call-history.php       # View past calls with filters
├── campaigns.php          # Campaign management (coming soon)
├── assets/
│   ├── call-center.css    # Custom styling
│   └── call-center.js     # Interactive features
└── README.md             # This file
```

---

## 🚀 How to Use

### For Call Agents:

#### **Starting Your Shift**

1. Navigate to **Call Center** in the sidebar (Operations section)
2. View your **Today's Statistics** at the top
3. See your **Call Queue** - prioritized list of who to call

#### **Making a Call**

1. Click **"Start Call"** button next to a donor in the queue
2. **Dial the donor** on your phone manually
3. **Follow the script** provided on screen:
   - Greeting & Introduction
   - Identity Verification
   - Pledge Reminder
   - Payment Discussion
   - Portal Information
   - Closing
4. **Click "Start Timer"** when call begins
5. **Record the outcome** as you talk:
   - Select primary outcome (57 options available!)
   - Choose conversation stage (how far did you get?)
   - Note donor's response type (positive/negative/neutral)
   - Add any special flags (if applicable)
6. **Write detailed notes** - Critical for follow-ups!
7. **Set next action**:
   - Schedule callback (with specific date/time)
   - Send SMS then retry
   - Assign to church rep
   - Mark as completed
8. Click **"Save Call Record"**

#### **Viewing History**

1. Go to **Call History** from dashboard
2. **Filter** by date, outcome, or donor
3. **Click eye icon** to see full call details
4. **Call again** if needed from the modal

---

## 📊 Call Outcomes Explained

### ✅ **Success Outcomes**
- `payment_plan_created` - Created payment plan with donor
- `agreed_to_pay_full` - Donor agreed to pay full balance
- `agreed_cash_collection` - Will pay cash at church
- `payment_made_during_call` - Paid while on phone

### 🟡 **Positive Progress**
- `interested_needs_time` - Interested but needs more time
- `interested_check_finances` - Will check their finances
- `interested_discuss_with_family` - Will discuss with family
- `busy_call_back_later` - Asked to call back specific time

### 🔴 **Negative Outcomes**
- `not_interested` - Not interested in paying
- `hostile_angry` - Was hostile or angry
- `requested_no_more_calls` - Asked not to be called again
- `never_pledged_denies` - Denies ever making pledge

### ⚪ **No Connection**
- `no_answer` - Phone rang, no answer
- `busy_signal` - Line was busy
- `voicemail_left` - Left voicemail message
- `number_not_in_service` - Number doesn't work

### 🟠 **Special Circumstances**
- `financial_hardship` - Can't pay due to finances
- `medical_emergency` - Health issues
- `moved_abroad` - No longer in UK
- `donor_deceased` - Donor has passed away

---

## 🎨 Dashboard Features

### Statistics Cards
- **Calls Today** - Total calls you made today
- **Connected** - Successfully reached donors
- **Conversion Rate** - % of calls that resulted in positive outcome
- **Talk Time** - Total time spent on calls

### Call Queue Table
Columns:
- **Priority** - 1-10 scale (10 = most urgent)
- **Donor** - Name and city
- **Phone** - Click to dial
- **Balance** - Amount owed
- **Type** - Queue category
- **Attempts** - How many times called before
- **Action** - Start call button

### Upcoming Callbacks
- Shows next 10 callbacks scheduled
- Displays preferred time (morning/afternoon/evening)
- Shows callback reason
- Quick "Call Now" button

---

## 🔍 Advanced Features

### **Conversation Stage Tracking**
Tracks how far conversation progressed:
1. `no_connection` - Didn't reach donor
2. `connected_no_identity_check` - Answered but didn't verify
3. `identity_verified` - Confirmed it's correct person
4. `pledge_discussed` - Talked about their pledge
5. `payment_options_discussed` - Discussed payment methods
6. `agreement_reached` - Donor agreed to something
7. `plan_finalized` - Everything completed

### **Special Flags**
Check these if applicable:
- ☑️ Donor Requested Supervisor
- ☑️ Threatened Legal Action  
- ☑️ Claims Already Paid
- ☑️ Claims Never Pledged
- ☑️ Language Barrier

### **Auto-Refresh**
- Queue auto-refreshes every 2 minutes
- Ensures you always have fresh data
- Manual refresh available anytime

---

## 💡 Best Practices

### **1. Be Prepared**
- Review donor history before calling
- Have script ready
- Check previous call notes

### **2. Best Calling Times**
- **Monday-Friday: 6-8 PM** (best time!)
- **Weekends: 10 AM - 4 PM**
- **Avoid: Early mornings, late nights**

### **3. During the Call**
- Always verify identity first
- Be patient and respectful
- Listen more than you talk
- Don't argue or pressure
- Respect if they're busy

### **4. Taking Notes**
- Write detailed notes IMMEDIATELY
- Include donor's exact words for promises
- Note any objections raised
- Record what was agreed upon

### **5. Follow-Up**
- Set specific callback dates/times
- Send SMS as promised
- Update donor status accurately
- Escalate issues to supervisor when needed

---

## 🔒 Security & Privacy

- **All calls are logged** for audit purposes
- **Agent tracking** - System knows who made each call
- **GDPR compliant** - Notes are internal only
- **No recording** - Audio not recorded (per requirements)
- **Secure access** - Only admin/registrar roles can access

---

## 📈 Reporting Capabilities

### Agent Performance
- Total calls made
- Success rate
- Average call duration
- Conversion percentage
- Best performing times

### Campaign Tracking
- Total donors in campaign
- Contacted vs remaining
- Collection progress
- Outcome breakdown

---

## 🛠️ Technical Details

### Database Tables Used
- `call_center_sessions` - Main call records
- `call_center_queues` - Active call queue
- `call_center_attempt_log` - Attempt tracking
- `call_center_special_circumstances` - Special cases
- `call_center_contact_verification` - Data updates
- `donors` - Donor information

### Technologies
- **PHP 8.4+** - Backend logic
- **MySQL/MariaDB** - Database
- **Bootstrap 5** - UI framework
- **Font Awesome 6** - Icons
- **JavaScript (ES6)** - Interactivity

---

## 🚧 Coming Soon

- [ ] SMS integration (automated messages)
- [ ] Campaign creation wizard
- [ ] Agent performance leaderboard
- [ ] Bulk actions on queue
- [ ] Export call history to Excel
- [ ] Voice recording integration
- [ ] AI-powered objection suggestions

---

## 🐛 Troubleshooting

### Queue not showing donors?
- Check if donors have pending pledges
- Verify queue status is "pending"
- Ensure next_attempt_after is in the past

### Can't save call record?
- All required fields must be filled
- Outcome must be selected
- Notes are required
- Check database connection

### Stats showing zero?
- Stats only count today's calls
- Check date/time on server
- Ensure calls are being saved properly

---

## 👥 Support

For questions or issues:
1. Check this README first
2. View donor-management system documentation
3. Contact system administrator
4. Check database logs

---

## 📝 Change Log

### Version 1.0.0 (November 2025)
- ✅ Initial release
- ✅ Call queue management
- ✅ Make call screen with script
- ✅ 57 different outcome types
- ✅ Call history with filters
- ✅ Agent statistics tracking
- ✅ Special circumstance flags
- ✅ Callback scheduling
- ✅ Campaign structure (basic)

---

**Built with ❤️ for Liverpool Abune Teklehaymanot EOTC**

*Empowering the church to collect £48,495 in pledges through systematic, respectful donor engagement.*

