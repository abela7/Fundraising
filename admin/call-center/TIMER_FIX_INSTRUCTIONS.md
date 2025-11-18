# 🔧 TIMER FIX - INSTRUCTIONS

## Problem Identified:
**Call duration is showing 0 seconds** even though the call lasted over 1 minute.

## Root Cause:
The `CallWidget` timer is NOT running on the `conversation.php` page. The timer starts on `availability-check.php` when "Picked Up" is clicked, but when navigating to `conversation.php`, the timer state from `localStorage` might be lost or the timer might be in "stopped" status.

## What Was Fixed:

### 1. `conversation.php` - Enhanced Timer Initialization
**Line 764-789**: Added automatic timer restart logic:
```javascript
// Ensure timer is running
if (CallWidget.state.status === 'stopped') {
    console.log('Timer was stopped. Starting it now...');
    CallWidget.start();
}
```

### 2. `conversation.php` - Enhanced Form Submission
**Line 954-999**: Added detailed logging and error handling:
- Checks if `CallWidget` is defined
- Shows alerts if there are errors
- Logs duration to browser console
- Captures errors gracefully

## How to Test:

### Step 1: Upload Updated Files
Upload these files to your server:
- `admin/call-center/conversation.php` (updated)
- `admin/call-center/process-conversation-debug.php` (new - for testing)

### Step 2: Open Browser Console
1. Open your browser
2. Press **F12** (or Right-click → Inspect)
3. Go to the **Console** tab
4. Keep it open during testing

### Step 3: Complete the Call Flow
1. Navigate to Call Center → View a donor → Start Call
2. Click "Picked Up"
3. **Watch the Console** - you should see:
   ```
   CallWidget: Script loaded
   CallWidget: Initializing with config
   CallWidget: Starting timer
   CallWidget: Initialized successfully
   ```
4. Click "Can Talk"
5. **Watch the Console** - you should see:
   ```
   Initializing CallWidget...
   Timer is already running/paused. Current status: running
   CallWidget state: {...}
   Current duration: [NUMBER] seconds
   ```
6. Complete all steps to Step 4 (Review & Confirm)
7. Click "Create Plan & Finish Call"
8. **Watch the Console** - you should see:
   ```
   submitForm called
   Duration from CallWidget: [NUMBER]
   Final duration to be saved: [NUMBER] seconds
   Hidden input added to form: {...}
   CallWidget stopped and reset
   ```

### Step 4: Check Results
- If you see **"ERROR: Call Widget not loaded"** alert → Widget JS file is not loading
- If duration shows **0** in console → Timer was never started or state was lost
- If duration shows **correct number** in console → Timer is working!

## Expected Console Output (Success):

```
CallWidget: Script loaded
Initializing CallWidget...
CallWidget: Initializing with config {sessionId: 41, donorId: 1, ...}
Timer is already running/paused. Current status: running
CallWidget state: {status: "running", startTime: 1700348266123, ...}
Current duration: 63 seconds
... (user completes steps) ...
submitForm called
Duration from CallWidget: 127
Final duration to be saved: 127 seconds
Hidden input added to form: <input>
CallWidget stopped and reset
```

## If Timer Shows 0:

### Scenario A: Widget Not Loaded
**Console shows:** `CallWidget is not defined!`
**Solution:** Check that `assets/call-widget.js` file exists and is loading (check Network tab in F12)

### Scenario B: Timer Never Started
**Console shows:** `Timer was stopped. Starting it now...`
**Solution:** This is now fixed - the timer will auto-start

### Scenario C: LocalStorage Cleared
**Console shows:** `Current duration: 0 seconds` even though timer is "running"
**Solution:** Check browser console for localStorage key: `call_center_session_[ID]`

## Debugging Tips:

1. **Check if widget is visible on screen**
   - Top-right corner (desktop)
   - Bottom center (mobile)
   - Red pulsing dot should be visible

2. **Manually check timer state in console:**
   ```javascript
   CallWidget.state
   CallWidget.getDurationSeconds()
   localStorage.getItem('call_center_session_41')
   ```

3. **Check Network tab:**
   - Is `call-widget.js` loading? (Status 200?)
   - Is `call-widget.css` loading? (Status 200?)

## After Testing:

1. If duration is now being captured correctly, **revert form action** in `conversation.php`:
   - Change `action="process-conversation-debug.php"` back to `action="process-conversation.php"`

2. Test one more time with the normal flow

3. Check `call-history.php` - duration should now show correctly!

---

## Quick Summary:

✅ **What was wrong:** Timer wasn't running on `conversation.php`
✅ **What was fixed:** Added auto-start if timer is stopped
✅ **What to do:** Upload updated file, test with console open, report results

**Report back:** Share console output or screenshot when you click "Create Plan & Finish Call"

