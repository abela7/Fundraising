# PHP Security & Code Review Report
## Fundraising System - Complete Line-by-Line Review

**Review Date:** December 11, 2025  
**Total Pages:** ~186+  
**Reviewer:** Automated Security Review

---

## Table of Contents
1. [Root Level](#root-level)
2. [Admin Section](#admin-section)
3. [Donor Section](#donor-section)
4. [Registrar Section](#registrar-section)
5. [Public Section](#public-section)
6. [API Section](#api-section)
7. [Reports Section](#reports-section)

---

# Root Level

## index.php

**File Path:** `index.php`

### Overview
Static HTML landing page for the Liverpool Abune Teklehaymanot EOTC fundraising campaign. Contains no PHP processing - purely static content with embedded YouTube video, floor plan images, and navigation links.

### Critical Issues
- **NONE** - This is a static HTML file with no server-side processing or user input handling.

### Enhancements
1. **Content Security Policy**: Add CSP headers to mitigate XSS risks from embedded external resources (YouTube, CDN).
2. **Subresource Integrity**: Add SRI hashes to CDN-loaded Bootstrap and Font Awesome resources.
3. **Image Alt Text**: The lightbox image (`id="lightbox-img"`) has empty src and alt attributes by default.

---

# Admin Section

## admin/index.php

**File Path:** `admin/index.php`

### Overview
Simple redirect page that requires login authentication before redirecting to the dashboard. Uses shared auth and CSRF modules.

### Critical Issues
- **NONE** - Properly requires authentication before redirect.

### Enhancements
1. **Exit After Redirect**: Code correctly exits after header redirect - good practice maintained.

---

## admin/login.php

**File Path:** `admin/login.php`

### Overview
Admin login page accepting phone number and 6-digit code. Uses CSRF protection and proper password verification via `login_with_phone_password()` function.

### Critical Issues
- **NONE** - CSRF protection is properly implemented. Input is validated. Output is escaped with `htmlspecialchars()`.

### Enhancements
1. **Rate Limiting**: No visible rate limiting on login attempts - could allow brute force attacks on the 6-digit code (only 1 million combinations).
2. **Phone Validation**: Phone input is not validated for format before database lookup.
3. **Account Lockout**: No account lockout mechanism visible after failed attempts.
4. **Timing Attack**: The error message "Invalid phone or code" is generic (good), but login timing could still leak whether phone exists.

---

## admin/logout.php

**File Path:** `admin/logout.php`

### Overview
Simple logout handler that calls the shared `logout()` function and redirects to login page.

### Critical Issues
- **NONE** - Properly delegates to shared auth function.

### Enhancements
1. **CSRF Protection**: Logout should ideally require POST with CSRF token to prevent logout CSRF attacks (low severity).

---

## admin/register.php

**File Path:** `admin/register.php`

### Overview
User registration page. First user becomes admin automatically; subsequent registrations require admin access. Uses prepared statements and proper validation.

### Critical Issues
- **NONE** - Uses prepared statements for SQL. CSRF protection in place. Input validated. Output escaped.

### Enhancements
1. **Email Normalizatio n**: Email is not normalized (lowercase) before uniqueness check - could allow duplicates with different cases.
2. **Phone Format Validation**: Phone number format not validated (should be UK mobile format per project requirements).
3. **Password Strength**: 6-digit code is weak (only 1 million combinations). Consider longer codes or alphanumeric passwords.
4. **Role Whitelist**: Role comes from POST data without whitelist validation - though limited to admin/registrar by the form.

---

## admin/dashboard/index.php

**File Path:** `admin/dashboard/index.php`

### Overview
Main admin dashboard displaying fundraising progress, financial totals, quick action links, and recent activity timeline from audit logs. Uses FinancialCalculator for totals and requires login.

### Critical Issues
- **NONE** - Properly requires login, escapes output with `htmlspecialchars()`, uses `json_encode()` for JavaScript output.

### Enhancements
1. **Error Message Exposure**: Database error message is displayed to user with `htmlspecialchars($db_error_message)` - should log internally and show generic message.
2. **Direct db() call on line 321**: The audit log query uses `db()->query()` without error handling in the foreach loop.
3. **Function Definition Inside PHP**: The `activityIconClass()` function is defined inside HTML output - should be at top of file or in a helper.

---

## admin/profile/index.php

**File Path:** `admin/profile/index.php`

### Overview
Admin profile management page allowing users to view and update their profile information and change their password. Uses modals triggered by URL query parameters.

### Critical Issues
- **NONE** - CSRF protection on all forms, prepared statements used, output properly escaped.

### Enhancements
1. **Missing declare(strict_types=1)**: File doesn't declare strict types unlike other admin files.
2. **Session Data Exposure**: IP address and user agent are displayed - could be used for fingerprinting.
3. **Password Minimum Length**: Only 6 characters required - should be stronger for admin accounts.
4. **Email Uniqueness**: Unlike phone, email uniqueness is not checked before update.
5. **Logout Link Points to Wrong Path**: Line 547 links to `../auth/logout.php` but logout is at `../logout.php`.

---

## admin/settings/index.php

**File Path:** `admin/settings/index.php`

### Overview
System settings page for managing fundraising target, currency, donation packages, user settings, and system maintenance tools. Uses AJAX for package management.

### Critical Issues
- **Missing CSRF Token in AJAX**: AJAX calls for package management (add/update/delete) don't appear to include CSRF validation in the JavaScript - only PHP side checks.

### Enhancements
1. **Session Timeout Not Saved**: The session_timeout, registration_mode, default_role fields are in the form but not handled in the POST processing.
2. **Maintenance Mode Not Implemented**: The maintenance_on/maintenance_off actions are in forms but not processed in PHP.
3. **recalc_totals/clear_cache/export_json/health_check Actions**: These form actions are defined but not implemented in PHP.
4. **Currency Code Validation**: Only truncates to 3 chars but doesn't validate against known currency codes.

---

## admin/audit/index.php

**File Path:** `admin/audit/index.php`

### Overview
Audit log viewer with filtering, pagination, search, and CSV export functionality. Displays all system actions with before/after JSON data.

### Critical Issues
- **Potential SQL Injection in Search**: The LIKE query with `CONCAT("%", ?, "%")` is safe due to prepared statements, but the search term goes into multiple fields including JSON columns which could have performance issues.

### Enhancements
1. **Missing declare(strict_types=1)**: File doesn't declare strict types.
2. **CSV Export Without Rate Limiting**: Exporting all logs could be abused for DoS.
3. **IP Display**: Binary IP conversion uses `@inet_ntop()` with error suppression - should handle errors properly.
4. **Default Date Range**: Sets default from date to 7 days ago in HTML but doesn't use it in PHP filtering when empty.

---

## admin/members/index.php

**File Path:** `admin/members/index.php`

### Overview
Member (user) management page with CRUD operations, bulk actions, role changes, and WhatsApp sharing functionality.

### Critical Issues
1. **SQL Injection on Lines 103, 128, 176, 177, 182, 183, 189, 195**: Direct string concatenation in SQL queries like `'UPDATE users SET active=0 WHERE id=' . $id` - although $id is cast to int, this pattern is unsafe.
2. **Bulk Delete Without Transaction Rollback on Partial Failure**: When bulk delete has some errors, it still commits successful deletes without properly communicating which ones failed.

### Enhancements
1. **Self-Modification Prevention**: Only prevents self-modification in bulk actions, not in single delete/deactivate actions.
2. **Password Code Display**: Auto-generated 6-digit code is displayed in plain text in success message - should be shown once and then hidden.
3. **Role Validation**: Role value from POST is not validated against a whitelist before database insert/update.
4. **Phone/Email Uniqueness**: Not checked in create/update operations - could create duplicates.

---

## admin/messages/index.php

**File Path:** `admin/messages/index.php`

### Overview
Internal user-to-user messaging system with real-time AJAX endpoints for conversations, messages, and sending. Implements blocklist checking and idempotency via client_uuid.

### Critical Issues
- **NONE** - CSRF protection on send action. Prepared statements used throughout. Proper user authorization checks.

### Enhancements
1. **Message Body Sanitization**: Message body is only trimmed, not sanitized for potentially malicious content before storage.
2. **Rate Limiting**: No visible rate limiting on message sending - could allow spam.
3. **Message Length Limit**: No server-side limit on message body length.
4. **Error Suppression**: Line 153 uses `@filemtime()` to suppress errors.

---

## admin/payments/index.php

**File Path:** `admin/payments/index.php`

### Overview
Payments management page with filtering, search, pagination, and payment recording functionality. Records payments as pending until approved.

### Critical Issues
1. **SQL Injection via real_escape_string**: Line 133-134 uses `$db->real_escape_string($methodFilter)` in string concatenation - while `$methodFilter` is validated against whitelist, this pattern is unsafe.

### Enhancements
1. **Missing declare(strict_types=1)**: File doesn't declare strict types.
2. **Missing Donor Name Validation**: Donor name only requires non-empty, no format validation.
3. **Error Message in URL**: Error messages are passed via URL query string which could leak information.
4. **Payment Details Modal**: Static content in modal HTML instead of dynamic AJAX population.

---

## admin/pledges/index.php

**File Path:** `admin/pledges/index.php`

### Overview
Pledges management page with filtering, search, CSV export, and pagination. Read-only view of all pledges in the system.

### Critical Issues
- **NONE** - Uses prepared statements throughout. Proper output escaping with htmlspecialchars().

### Enhancements
1. **CSV Export Without Rate Limiting**: Export endpoint could be abused for DoS.
2. **No CSRF on Export**: CSV export is GET-based without CSRF protection.
3. **JSON Encoding in onclick**: Line 364 uses `json_encode($pledge)` directly in onclick - could be vulnerable to XSS if pledge data contains special characters (though htmlspecialchars is applied).

---

## admin/projector/index.php

**File Path:** `admin/projector/index.php`

### Overview
Projector control panel for managing footer messages, display modes, and quick presets. Allows controlling what's shown on the public projector display.

### Critical Issues
1. **Missing CSRF Protection**: POST forms for `update_footer` and `update_display_mode` don't include CSRF token verification.

### Enhancements
1. **Missing declare(strict_types=1)**: File doesn't declare strict types.
2. **XSS via Short Echo**: Uses `<?= ... ?>` syntax which doesn't escape by default - though `htmlspecialchars()` is applied where needed.
3. **Presets Stored in localStorage**: Custom presets stored client-side could be lost or manipulated.
4. **url_for() with addslashes**: Line 611-616 uses `addslashes()` on URL which may not properly escape for JavaScript context.
5. **deprecated document.execCommand**: Line 637 uses `document.execCommand('copy')` which is deprecated.

---

## admin/system/index.php

**File Path:** `admin/system/index.php`

### Overview
**FILE NOT FOUND** - Listed in PAGES_LIST.md but does not exist in the codebase.

### Critical Issues
- **Missing File**: Page listed in documentation but not implemented.

### Enhancements
- Either implement the page or remove from documentation.

---

## admin/security/index.php

**File Path:** `admin/security/index.php`

### Overview
**FILE NOT FOUND** - Listed in PAGES_LIST.md but does not exist in the codebase.

### Critical Issues
- **Missing File**: Page listed in documentation but not implemented.

### Enhancements
- Either implement the page or remove from documentation.

---

## admin/user-status/index.php

**File Path:** `admin/user-status/index.php`

### Overview
Read-only user login status page showing when admins and registrars last logged in. Displays user list with login state indicators.

### Critical Issues
- **NONE** - Read-only page with proper output escaping.

### Enhancements
1. **XSS in JavaScript**: Line 184 and 231-240 dynamically build HTML from API response without escaping - potential XSS if member stats API returns malicious data.
2. **Resilient Column Check**: Good pattern of checking if column exists before querying, making page resilient to schema changes.

---

## admin/grid-allocation/index.php

**File Path:** `admin/grid-allocation/index.php`

### Overview
Grid allocation viewer showing floor grid cells with donor information, filtering, search, and CSV export functionality.

### Critical Issues
- **NONE** - Uses prepared statements throughout. Proper output escaping.

### Enhancements
1. **Error Message Exposure**: Line 226-229 exposes database error message to page - should log internally and show generic message.
2. **CSV Export Without CSRF**: Export is GET-based without CSRF protection.
3. **Complex JOIN Query**: The main query has 6 LEFT JOINs which could be slow with large datasets - consider pagination or lazy loading.

---

## admin/grid-allocation/manage.php

**File Path:** `admin/grid-allocation/manage.php`

### Overview
Grid cell management page allowing admins to allocate and unallocate individual floor grid cells manually.

### Critical Issues
- **NONE** - CSRF protection on POST actions. Uses prepared statements. Proper transaction handling with rollback on error.

### Enhancements
1. **Error Suppression**: Lines 315-316 and 710-711 use `@filemtime()` to suppress errors.
2. **Database Locking**: Uses `FOR UPDATE` in SELECT - good practice for preventing race conditions.
3. **Pledges/Payments Dropdown Limit**: Lines 302-303 only fetch 100 pledges/payments - may miss some in large systems.
4. **Filter Validation**: Good validation of filter parameters against whitelists.

---

# API Section

## api/check_donor.php

**File Path:** `api/check_donor.php`

### Overview
Public API endpoint to check donor existence by phone number. Returns pledge/payment counts and recent donations without authentication.

### Critical Issues
1. **No Authentication**: Endpoint is unauthenticated - anyone can query donor information by phone number.
2. **Information Disclosure**: Returns donor name, donation history, and amounts for any phone number submitted.
3. **No Rate Limiting**: No visible rate limiting allows enumeration attacks.

### Enhancements
1. **Add Rate Limiting**: Implement rate limiting via RateLimiter class.
2. **Require Authentication or CAPTCHA**: For non-authenticated use.
3. **Limit Information Returned**: Don't return full donation history to unauthenticated requests.

---

## api/totals.php

**File Path:** `api/totals.php`

### Overview
Public API returning fundraising totals (paid, pledged, grand total, progress). Uses FinancialCalculator for centralized calculations.

### Critical Issues
- **NONE** - Read-only aggregate data, no sensitive information exposed.

### Enhancements
1. **Add Caching**: Consider caching results to reduce database load.
2. **Response Headers**: Add cache-control headers for client-side caching.

---

## api/calculate_sqm.php

**File Path:** `api/calculate_sqm.php`

### Overview
Calculates square meters for given donation amount based on package pricing.

### Critical Issues
- **NONE** - Read-only calculation, uses prepared statements (implicit via query).

### Enhancements
1. **Debug Info in Production**: Line 92 returns `$e->getMessage()` in error response - remove in production.
2. **Input Validation**: Amount comes from GET parameter - consider stricter validation.

---

## api/donor_history.php

**File Path:** `api/donor_history.php`

### Overview
Returns donation history for a phone number. No authentication.

### Critical Issues
1. **No Authentication**: Anyone can query donation history by phone number.
2. **Privacy Concern**: Returns full donation history including IDs, amounts, dates, status.

### Enhancements
1. **Add Authentication**: Require donor login or API key.
2. **Rate Limiting**: Add rate limiting to prevent enumeration.

---

## api/footer.php

**File Path:** `api/footer.php`

### Overview
Returns projector footer message for public display.

### Critical Issues
- **NONE** - Read-only public content.

### Enhancements
- None needed.

---

## api/grid_status.php

**File Path:** `api/grid_status.php`

### Overview
Returns floor grid allocation status for projector visualization. CORS enabled for all origins.

### Critical Issues
1. **Error Message Exposure**: Line 97 includes `$e->getMessage()` in response - could leak internal details.

### Enhancements
1. **Restrict CORS**: Consider restricting Access-Control-Allow-Origin to specific domains.
2. **Sanitize Error Messages**: Remove exception details from response.

---

## api/member_generate_code.php

**File Path:** `api/member_generate_code.php`

### Overview
Admin-only API to regenerate passcode for registrar users. Requires admin authentication and CSRF token.

### Critical Issues
- **NONE** - Proper authentication, CSRF protection, prepared statements.

### Enhancements
1. **Error Message Detail**: Line 44 returns `$e->getMessage()` - sanitize for production.
2. **Audit Logging**: Add audit log entry for passcode regeneration.

---

## api/member_stats.php

**File Path:** `api/member_stats.php`

### Overview
Admin-only API returning statistics for a specific user (registrar/admin).

### Critical Issues
- **NONE** - Requires admin authentication, uses prepared statements.

### Enhancements
- None significant.

---

## api/recent.php

**File Path:** `api/recent.php`

### Overview
Returns recent approved donations for projector display. Anonymizes all donor names.

### Critical Issues
- **NONE** - All names anonymized to "Kind Donor", read-only.

### Enhancements
1. **Error Logging**: Line 82 logs to error_log - ensure log rotation.

---

# Shared Components

## shared/auth.php

**File Path:** `shared/auth.php`

### Overview
Core authentication module handling sessions, login/logout, role-based access control, and donor device validation.

### Critical Issues
- **NONE** - Uses password_verify, prepared statements, session_regenerate_id on login, proper cookie security.

### Enhancements
1. **Timing Attack on Phone Lookup**: Line 116-127 fallback query with SQL function calls on phone could be slow and reveal timing info.
2. **Hard-coded Login Path**: Line 35-50 constructs login path based on script name - ensure no path traversal.
3. **Device Validation Fail-Open**: Line 254-257 fails open if DB check fails - consider fail-closed for security-sensitive apps.

---

## shared/csrf.php

**File Path:** `shared/csrf.php`

### Overview
CSRF token generation and validation using session-stored tokens.

### Critical Issues
- **NONE** - Uses random_bytes(32), proper session storage.

### Enhancements
1. **Token Rotation**: Consider rotating token after successful use.
2. **Per-Request Tokens**: Current implementation uses single session token - per-request could be more secure.

---

## shared/RateLimiter.php

**File Path:** `shared/RateLimiter.php`

### Overview
Rate limiting system for donation forms with per-IP, per-phone, and global limits. Creates table if not exists.

### Critical Issues
1. **Table Name Injection**: Line 79, 81, 300, etc. use `$this->tableName` in queries without escaping (though it's a hardcoded string 'rate_limits').
2. **SQL Injection Risk**: Line 137 interpolates `$interval` directly into query - though it comes from internal array.

### Enhancements
1. **Use Prepared Statements**: For consistency, use prepared statements even for internal values.
2. **IP Spoofing**: Uses REMOTE_ADDR which can be spoofed via proxy headers - consider checking X-Forwarded-For.

---

## shared/BotDetector.php

**File Path:** `shared/BotDetector.php`

### Overview
Bot detection using honeypots, timing analysis, user agent checks, and interaction patterns.

### Critical Issues
- **NONE** - Detection logic only, no data modification.

### Enhancements
1. **Threshold Tuning**: 70% confidence threshold (line 59) may need tuning based on real-world data.
2. **Privacy**: Captures screen resolution and timezone - document in privacy policy.

---

## shared/audit_helper.php

**File Path:** `shared/audit_helper.php`

### Overview
Centralized audit logging helper for all system activities.

### Critical Issues
- **NONE** - Proper error handling, prepared statements.

### Enhancements
1. **Session Key Inconsistency**: Line 38 checks `$_SESSION['user_id']` but auth.php stores as `$_SESSION['user']['id']`.
2. **Failed Logging**: Silently returns false on failure - consider alerting for critical audit failures.

---

## config/db.php

**File Path:** `config/db.php`

### Overview
Database connection singleton using MySQLi with timezone configuration.

### Critical Issues
1. **SQL Injection in Time Zone**: Line 21 interpolates `$offset` into query - though it comes from DateTime::format('P') which is safe.

### Enhancements
1. **Connection Pooling**: Single connection may be limiting for high traffic.
2. **Credential Security**: Relies on env.php for credentials - ensure env.php is not web-accessible.

---

# Donor Portal

## donor/index.php

**File Path:** `donor/index.php`

### Overview
Donor dashboard showing pledge progress, payment history, quick actions. Requires donor login.

### Critical Issues
1. **SQL in String Interpolation**: Lines 130, 136, 168 use `$db->real_escape_string()` but not prepared statements for phone comparison in UNION queries.

### Enhancements
1. **Use Prepared Statements Consistently**: Replace string interpolation with prepared statements.
2. **Error Handling**: Multiple silent try-catch blocks - ensure errors are logged.

---

## donor/login.php

**File Path:** `donor/login.php`

### Overview
SMS OTP-based authentication with trusted device support. Comprehensive 2FA implementation.

### Critical Issues
- **NONE** - Strong implementation: OTP cooldown, max attempts, secure token generation, proper session handling.

### Enhancements
1. **OTP Brute Force**: 6-digit code with 5 attempts and 10-min expiry is reasonable but consider increasing code length.
2. **Device Token Length**: 32 bytes (64 hex chars) is good but could document the security rationale.
3. **Phone Normalization**: Multiple places normalize phone - consider centralizing.

---

## donor/logout.php

**File Path:** `donor/logout.php`

### Overview
Donor logout with optional device token removal.

### Critical Issues
- **NONE** - Proper session cleanup, cookie removal, audit logging.

### Enhancements
- None significant.

---

## donor/profile.php

**File Path:** `donor/profile.php`

### Overview
Donor profile management allowing name, phone, email, baptism name, and preferences update.

### Critical Issues
- **NONE** - CSRF protection, validation, prepared statements, audit logging.

### Enhancements
1. **Phone Change Security**: Changing phone number should require re-verification.
2. **Email Validation**: Currently only uses filter_var - consider additional validation.

---

## donor/make-payment.php

**File Path:** `donor/make-payment.php`

### Overview
Multi-step payment wizard allowing donors to record payments. Large file (1972 lines) with embedded JS.

### Critical Issues
1. **File Upload Path Disclosure**: Line 161 saves to predictable path - though requires donor login.
2. **SQL Query Building**: Lines 59-86 build queries with conditionals - harder to audit for injection.

### Enhancements
1. **File Too Large**: 1972 lines - should split into separate controller/view files.
2. **File Type Validation**: Line 151-152 checks MIME type but should also verify magic bytes.
3. **Payment Proof Storage**: Consider storing outside web root.

---

## admin/approvals/index.php

**File Path:** `admin/approvals/index.php`

### Overview
Large approval management page (2000+ lines) handling pledge and payment approvals with floor grid allocation logic.

### Critical Issues
1. **File Too Large to Review in Full**: At 2098 lines, this file is too large to review completely in one pass. A detailed line-by-line review is recommended.

### Enhancements
1. **Code Splitting**: File is too large - should be split into smaller modules (approval logic, grid allocation, AJAX handlers).
2. **Requires Detailed Review**: Due to size, recommend manual security audit of this critical file.

---

# Registrar Section

## registrar/index.php

**File Path:** `registrar/index.php`

### Overview
Registrar registration form (673 lines) for creating pledges and payments on behalf of donors. Large form with validation, duplicate detection, and allocation batch tracking.

### Critical Issues
- **NONE** - CSRF protection, role checking, prepared statements, duplicate prevention, audit logging.

### Enhancements
1. **Input Validation**: Phone validation is good but could be centralized.
2. **Transaction Handling**: Uses begin_transaction correctly but exception messages vary between dev/prod.
3. **Error Logging**: Line 362 logs full exception message including line number - ensure production logs are secure.

---

## registrar/login.php

**File Path:** `registrar/login.php`

### Overview
Standard phone/password login for registrars. Uses shared auth system.

### Critical Issues
- **NONE** - CSRF protection, uses login_with_phone_password from auth.php.

### Enhancements
1. **Brute Force Protection**: No visible rate limiting on login attempts.
2. **Account Lockout**: Consider implementing temporary lockout after failed attempts.

---

## registrar/logout.php

**File Path:** `registrar/logout.php`

### Overview
Simple logout handler redirecting to login page.

### Critical Issues
- **NONE** - Properly uses logout() function from auth.php.

### Enhancements
- None needed.

---

# Public Section

## public/donate/index.php

**File Path:** `public/donate/index.php`

### Overview
Public-facing donation form (675 lines) with rate limiting, bot detection, and form submission handling. Well-protected against abuse.

### Critical Issues
- **NONE** - Excellent security implementation with RateLimiter, BotDetector, CSRF, prepared statements, duplicate checking.

### Enhancements
1. **CAPTCHA Integration**: Line 163-166 logs that CAPTCHA should be required but not implemented yet.
2. **Error Handling in Catch Block**: Lines 311-333 have complex retry logic - consider simplifying.
3. **Goto Statement**: Lines 52, 159, 338 use `goto skip_processing` - consider refactoring to early returns.

---

## public/projector/index.php

**File Path:** `public/projector/index.php`

### Overview
Live fundraising display page (786 lines) with real-time updates via polling. Heavy JavaScript for animations and display modes.

### Critical Issues
- **NONE** - Read-only display, no user input handling server-side.

### Enhancements
1. **API Polling Frequency**: Polls totals every `$refresh` seconds (default 10) - may need tuning for load.
2. **JavaScript Error Handling**: Fetch calls have try/catch but could be more robust.
3. **Console Logging**: Lines 229, 317, 391, etc. contain debug console.log statements - remove in production.

---

## public/certificate/index.php

**File Path:** `public/certificate/index.php`

### Overview
Static certificate background template - purely visual HTML/CSS.

### Critical Issues
- **NONE** - Static HTML with no PHP logic or user input.

### Enhancements
- None needed.

---

# Services

## services/TwilioService.php

**File Path:** `services/TwilioService.php`

### Overview
Twilio Voice API integration (620 lines) for click-to-call functionality. Handles call initiation, status tracking, and recordings.

### Critical Issues
- **NONE** - Uses cURL with proper SSL verification, credentials from database, prepared statements for logging.

### Enhancements
1. **Hardcoded Base URL**: Line 164 hardcodes 'https://donate.abuneteklehaymanot.org' - should be configurable.
2. **Phone Normalization**: Lines 394-420 handle UK phones only - document limitation for international use.
3. **Error Logging**: Multiple error_log calls - ensure centralized logging.

---

# Webhooks

## webhooks/ultramsg.php

**File Path:** `webhooks/ultramsg.php`

### Overview
UltraMsg WhatsApp webhook handler (563 lines) processing incoming messages, delivery status, and reactions.

### Critical Issues
1. **SQL Injection Risk**: Line 120 uses `$db->real_escape_string()` in string concatenation instead of prepared statement.
2. **SQL Injection in Query**: Line 107 interpolates date directly into SQL query.
3. **Wide CORS Policy**: Line 17-19 allows all origins (*) - could be restricted.
4. **Debug Logging to Filesystem**: Lines 36-43 write raw webhook data to file - ensure log rotation and access controls.

### Enhancements
1. **Use Prepared Statements**: Replace all real_escape_string usage with prepared statements.
2. **Restrict CORS**: Limit Access-Control-Allow-Origin to UltraMsg IPs.
3. **Webhook Authentication**: Consider adding signature verification for webhook authenticity.

---

# Cron

## cron/process-sms-queue.php

**File Path:** `cron/process-sms-queue.php`

### Overview
Cron job to process SMS queue with quiet hours, daily limits, and retry logic.

### Critical Issues
1. **Hardcoded Cron Key**: Line 21 has placeholder 'your-secure-cron-key-here' - must be changed in production.
2. **SQL Injection Risk**: Lines 107, 166, 177, 184 use string interpolation in queries.

### Enhancements
1. **Use Prepared Statements**: Replace all query() calls with prepare() for dynamic values.
2. **Environment-Based Cron Key**: Store cron key in environment variable, not code.
3. **Logging Security**: Log files may contain phone numbers - ensure proper access controls.

---

## admin/approved/index.php

**File Path:** `admin/approved/index.php`

### Overview
Approved items management page showing all approved pledges and payments with filtering, sorting, pagination, and undo capabilities. Complex SQL UNION queries combining pledges, payments, and batch updates.

### Critical Issues
1. **SQL Injection via Escaping Pattern**: Lines 722-755 use `mysqli_real_escape_string()` with string concatenation in SQL - while escaped, this pattern is unsafe and should use prepared statements.
2. **Error Message Exposure**: Lines 789-790 and 929-933 expose database error messages to users via `die()`.

### Enhancements
1. **Complex SQL**: The main query (lines 809-925) is extremely complex with multiple UNIONs - difficult to maintain and audit.
2. **Error Suppression**: Line 946-948 uses `@filemtime()` to suppress errors.
3. **Function Defined Inside Script**: `build_pagination_url()` defined inside HTML output on line 1090.
4. **Session in JavaScript**: Lines 1663-1696 embed PHP session data in JavaScript output.

---

## admin/error/403.php

**File Path:** `admin/error/403.php`

### Overview
Static 403 Forbidden error page with helpful guidance for users.

### Critical Issues
- **NONE** - Static HTML page with no server-side processing.

### Enhancements
1. **No Server-Side Logging**: Error pages should log access attempts for security monitoring.

---

## admin/error/404.php

**File Path:** `admin/error/404.php`

### Overview
Static 404 Not Found error page with navigation suggestions.

### Critical Issues
- **NONE** - Static HTML page with no server-side processing.

### Enhancements
1. **No Server-Side Logging**: Error pages should log 404 attempts for broken link detection.

---

## admin/error/500.php

**File Path:** `admin/error/500.php`

### Overview
500 Internal Server Error page that generates a reference ID for support.

### Critical Issues
1. **Predictable Reference ID**: Line 51-52 generates reference ID from `md5(time())` which is predictable and not cryptographically secure.

### Enhancements
1. **No Actual Error Logging**: The reference ID is generated but not logged anywhere - should store in error log for support lookup.
2. **No Server Context**: Page doesn't capture actual error details - should receive error context from exception handler.

---

# Donor Section

## donor/contact.php

**File Path:** `donor/contact.php`

### Overview
Donor portal support request system (680 lines) with multi-channel notification (WhatsApp, phone call, SMS fallback). Allows donors to submit and track support requests with conversation threading.

### Critical Issues
- **NONE** - CSRF protection on all forms, prepared statements throughout, proper authentication, audit logging.

### Enhancements
1. **Hardcoded Phone Number**: Line 16 hardcodes `ADMIN_NOTIFICATION_PHONE` - should be configurable in settings.
2. **Information Disclosure**: Lines 102-109 expose donor phone and full message in WhatsApp notification - consider truncating.
3. **External Service Dependencies**: Lines 98-184 rely on multiple external services (WhatsApp, Twilio, SMS) - any could fail.
4. **SQL Query in Status Update**: Line 227 uses `$db->query()` with variable interpolation - should use prepared statement.

---

## donor/payment-plan.php

**File Path:** `donor/payment-plan.php`

### Overview
Donor portal page (483 lines) displaying pledges and payment plan schedules. Shows current pledges, plan summary, and payment schedule.

### Critical Issues
- **NONE** - Requires donor login, uses prepared statements, output properly escaped.

### Enhancements
1. **Session Refresh**: Lines 31-53 refresh donor data from database on each load - could cache or only refresh on updates.
2. **Schedule Generation**: Lines 100-110 generate schedule in memory - for long plans, this could be inefficient.
3. **Duplicate Status Mapping**: Lines 247-253 and 281-287 duplicate status class mapping - should centralize.
4. **Silent Fail Pattern**: Multiple `catch(Exception $e) { // Silent fail }` blocks hide errors.

---

## donor/update-pledge.php

**File Path:** `donor/update-pledge.php`

### Overview
Large pledge update page (1036 lines) allowing donors to request pledge amount increases. Heavy logging and error handling for debugging.

### Critical Issues
1. **DEBUG MODE IN PRODUCTION**: Lines 7-9 enable full error display (`display_errors = 1`) - must be disabled in production.
2. **STACK TRACE EXPOSURE**: Lines 635-667 display full stack traces and error details to users - security risk.
3. **Error Handler Exposes File Paths**: Lines 18-34 shutdown function displays file paths and error details.

### Enhancements
1. **Excessive Logging**: Over 50 `error_log()` calls - performance impact and log storage concerns.
2. **Complex Error Handling**: Multiple catch blocks (mysqli_sql_exception, Exception, Throwable) with similar logic - consolidate.
3. **Column Existence Checks**: Lines 287-310 check for column existence on each request - cache schema information.
4. **Long Transaction Block**: Lines 237-685 is a single transaction spanning 450+ lines - break into smaller operations.

---

## registrar/register.php

**File Path:** `registrar/register.php`

### Overview
Registrar application form (334 lines) for new registrar requests. Simple form with validation, duplicate checking, and success state.

### Critical Issues
- **NONE** - CSRF protection, prepared statements, proper validation, duplicate checking.

### Enhancements
1. **Email/Phone Normalization**: Lines 36-40 check duplicates but don't normalize email case or phone format first.
2. **No Rate Limiting**: No protection against application spam.
3. **Missing declare(strict_types=1)**: File declares strict types but should be consistent across all files.

---

# Admin Section (Continued)

## admin/call-center/index.php

**File Path:** `admin/call-center/index.php`

### Overview
Call center dashboard (693 lines) displaying agent statistics, scheduled calls, and recent activity. Mobile-first design with modern UI.

### Critical Issues
- **NONE** - Requires login, uses prepared statements, proper output escaping.

### Enhancements
1. **Error Message Exposure**: Line 136 stores full exception message in `$error_message` - could expose internal details.
2. **No CSRF on Links**: Quick action links go to other pages without token verification.
3. **Agent-Only Data**: Relies on user_id from session for filtering - ensure session validation is strong.

---

## admin/donor-management/index.php

**File Path:** `admin/donor-management/index.php`

### Overview
Simple donor management hub page (292 lines) providing navigation to various donor-related tools. Mostly static feature cards.

### Critical Issues
- **NONE** - Requires admin login, minimal data processing.

### Enhancements
1. **Hardcoded Statistics**: Lines 63 and 107-108 have hardcoded numbers (186, 4) - should be dynamic.
2. **No Dynamic Stats**: Dashboard doesn't query actual donor statistics.

---

## admin/reports/index.php

**File Path:** `admin/reports/index.php`

### Overview
Reports hub page (821 lines) with summary statistics, CSV/Excel exports, and quick date range filtering. Complex export logic.

### Critical Issues
1. **Missing CSRF on Exports**: Export links (lines 544, 603, 624, 645) use GET parameters without CSRF validation.
2. **Potential CSV Injection**: Lines 81-129 write donor data directly to CSV without sanitization for formula injection (=, @, +, -).

### Enhancements
1. **CSV Formula Injection Protection**: Prefix cell values with single quote or tab to prevent formula injection.
2. **Export Rate Limiting**: Large exports could be abused for DoS.
3. **Date Range Validation**: Lines 57-71 accept date ranges without validation of reasonable limits.
4. **Excel Export XSS**: Lines 167-176 output HTML with Excel doctype - could be XSS vector if downloaded and opened.

---

## admin/registrar-applications/index.php

**File Path:** `admin/registrar-applications/index.php`

### Overview
Registrar application management page (771 lines) with approval workflow, WhatsApp sharing, and rejection handling. 

### Critical Issues
- **NONE** - CSRF protection, prepared statements, proper authentication, audit logging.

### Enhancements
1. **Session Passcode Storage**: Lines 88-93 store plaintext passcode in session for 5 minutes - minimize exposure window.
2. **WhatsApp Message Contains Plaintext Passcode**: Lines 558-575 include passcode in shareable message - expected but note security tradeoff.
3. **Hardcoded Video URL**: Line 561 hardcodes YouTube training video URL - should be configurable.
4. **Hardcoded Domain**: Lines 571-572 hardcode production domain - should use dynamic URL generation.
5. **Deprecated execCommand**: Line 535 uses `document.execCommand('copy')` - deprecated.

---

## admin/tools/index.php

**File Path:** `admin/tools/index.php`

### Overview
Developer tools hub page (309 lines) with destructive database operations (reset payments, pledges, chats, audits). Provides access to migrations, exports, and testing tools.

### Critical Issues
- **NONE** - Requires admin login, CSRF protection on all forms, JavaScript confirms on destructive actions.

### Enhancements
1. **Environment Check**: No check for production/development environment before allowing destructive actions.
2. **Rate Limiting**: No rate limiting on reset operations.
3. **Audit Logging**: Reset actions don't create audit logs before wiping data.
4. **Transaction on Rollback Check**: Line 62 checks `$db->errno` instead of tracking transaction state.

---

## admin/tools/wipe_database.php

**File Path:** `admin/tools/wipe_database.php`

### Overview
CRITICAL tool (111 lines) that drops ALL database tables. Displays database name before action.

### Critical Issues
1. **NO ENVIRONMENT CHECK**: Line 76 displays environment but doesn't prevent execution in production.
2. **ATOMIC BOMB FUNCTIONALITY**: Lines 30-33 can delete entire database without additional safeguards.

### Enhancements
1. **CRITICAL - Add Environment Check**: Prevent execution when `ENVIRONMENT === 'production'`.
2. **Two-Factor Confirmation**: Require additional confirmation (e.g., type database name) before wiping.
3. **Backup Reminder**: Force backup creation before allowing wipe.
4. **Audit Log Before Wipe**: Log the action before destroying audit_logs table.

---

## admin/tools/export_database.php

**File Path:** `admin/tools/export_database.php`

### Overview
Database export tool (146 lines) that generates .sql backup files for download.

### Critical Issues
1. **NO CSRF PROTECTION**: Line 8 accepts POST without CSRF verification.
2. **DoS POTENTIAL**: Line 12 sets `set_time_limit(600)` - 10 minute operations could tie up resources.

### Enhancements
1. **Add CSRF Protection**: Verify CSRF token before processing export.
2. **SQL Injection in Table Name**: Line 30 uses `real_escape_string` but escaping in table names is unreliable.
3. **Rate Limiting**: Add rate limiting to prevent abuse.
4. **Size Limits**: No limit on export size - large databases could cause memory issues.

---

## admin/church-management/index.php

**File Path:** `admin/church-management/index.php`

### Overview
Church management dashboard (567 lines) displaying statistics, quick actions, and recent representatives. Uses raw SQL queries.

### Critical Issues
- **NONE** - Requires admin login, uses raw queries but no user input involved, output properly escaped.

### Enhancements
1. **No Prepared Statements**: Lines 14-47 use raw queries - safe here but inconsistent with best practices.
2. **Error Exposure**: Line 49 captures full exception message in error variable.
3. **Missing reports.php**: Line 459 links to `reports.php` but file may not exist in this folder.

---

# Reports Section

## reports/donor-review.php

**File Path:** `reports/donor-review.php`

### Overview
Public donor review report page (799 lines) for data collectors. Contains hardcoded donor data from Excel spreadsheet, no authentication required.

### Critical Issues
1. **NO AUTHENTICATION**: Page is publicly accessible with no login required.
2. **HARDCODED PII**: Contains 96 donor records with names and phone numbers hardcoded in PHP array (lines 14-111).
3. **DATA EXPOSURE**: Phone numbers and donation amounts visible to anyone accessing the URL.
4. **NO CSRF**: No protection on any interactive elements.

### Enhancements
1. **CRITICAL - Add Authentication**: This page exposes sensitive donor PII - must require login.
2. **CRITICAL - Remove Hardcoded Data**: Donor data should come from database, not hardcoded in source.
3. **Add Rate Limiting**: Prevent scraping of donor information.
4. **Robots.txt Not Sufficient**: noindex header is set, but robots.txt reliance is not a security control.

---

# Services Section

## services/SMSHelper.php

**File Path:** `services/SMSHelper.php`

### Overview
SMS service module (643 lines) for sending messages via VoodooSMS. Implements queuing, templates, quiet hours, blacklisting, and rate limiting.

### Critical Issues
- **NONE** - Uses prepared statements, proper error handling, respects opt-out preferences.

### Enhancements
1. **SQL Injection in Daily Limit Check**: Line 519-521 uses string interpolation for date in query instead of prepared statement.
2. **Log File Permissions**: Lines 543-550 create log files with `file_put_contents` - ensure logs directory is properly secured.
3. **Quiet Hours Timezone**: Lines 494-506 use server timezone - should use donor's timezone or UK timezone explicitly.
4. **Phone Validation**: Phone numbers are checked for blacklist but not validated for format before sending.

---

## services/UltraMsgService.php

**File Path:** `services/UltraMsgService.php`

### Overview
WhatsApp messaging service (939 lines) via UltraMsg API. Handles text, image, document, audio, video messages with logging.

### Critical Issues
1. **SQL Injection in updateProviderStats**: Lines 750-763 use direct query interpolation with `$this->providerId` without prepared statement.

### Enhancements
1. **Token Exposure in Logs**: Line 605 logs raw API response which could include sensitive data.
2. **Error Logging**: Lines 569, 739 log to error_log which may expose message content in logs.
3. **No Message Content Sanitization**: Message content is sent directly to API without content validation.

---

## services/TwilioService.php

**File Path:** `services/TwilioService.php`

### Overview
Twilio integration service (reviewed previously) for SMS and voice functionality.

### Critical Issues
- Covered in previous webhook review.

### Enhancements
- Refer to previous Twilio-related findings.

---

## shared/FinancialCalculator.php

**File Path:** `shared/FinancialCalculator.php`

### Overview
Centralized financial calculations module (299 lines). Provides consistent totals across dashboard, reports, and APIs.

### Critical Issues
- **NONE** - Uses prepared statements, proper type casting, internal use only.

### Enhancements
1. **Floating Point Precision**: Lines 121-122 use `max(0, ...)` for outstanding - should use BC math for financial precision.
2. **Complex Subqueries in UPDATE**: Lines 196-276 have complex correlated subqueries that could be slow on large datasets.

---

# Admin Donations Section

## admin/donations/index.php

**File Path:** `admin/donations/index.php`

### Overview
Large donations management page (1083 lines) with CRUD operations for payments and pledges. Implements filtering, pagination, export, and audit logging.

### Critical Issues
- **NONE** - CSRF protection, prepared statements throughout, proper authentication, audit logging on all write operations.

### Enhancements
1. **Error Exposure**: Line 250 displays exception message to user - should log internally and show generic message.
2. **Missing Input Validation**: Lines 28-32 accept donor name, phone, email without format validation.
3. **Anonymous Checkbox Duplication**: Lines 89 and 163 both check for 'anonymous' checkbox - potential logic conflict.
4. **Export Function**: Line 467 has export button but `exportDonations()` function needs CSRF protection.

---

## admin/donations/payment.php

**File Path:** `admin/donations/payment.php`

### Overview
Individual payment management page (135 lines) for editing payment details.

### Critical Issues
- **NONE** - CSRF protection, prepared statements, proper authentication.

### Enhancements
1. **No Audit Logging**: Updates don't create audit log entries unlike index.php.
2. **Missing Validation**: Lines 13-20 accept input without validation for phone/email format.
3. **Error Variable Undefined**: Line 22 checks `$error` but it may not be defined - should initialize.

---

## admin/donations/pledge.php

**File Path:** `admin/donations/pledge.php`

### Overview
Individual pledge management page (215 lines) with convert-to-payment functionality.

### Critical Issues
1. **SQL Injection in Update Query**: Line 49 uses string concatenation with pledge ID in update query.

### Enhancements
1. **No Audit Logging**: Pledge updates and conversions don't create audit entries.
2. **Transaction Missing**: Convert operation (lines 18-52) should use transaction for atomicity.
3. **Error Variable Undefined**: Line 28 checks `$error` but may not be initialized.

---

## admin/donors/index.php

**File Path:** `admin/donors/index.php`

### Overview
Placeholder file (3 lines) - just outputs "hello".

### Critical Issues
1. **INCOMPLETE FILE**: This is a placeholder that doesn't implement donor listing functionality.

### Enhancements
1. **Complete Implementation**: Page should list donors with proper authentication and filtering.

---

## admin/donors/view.php

**File Path:** `admin/donors/view.php`

### Overview
Donor detail view page (194 lines) showing pledges and payments for a specific donor.

### Critical Issues
- **NONE** - Requires admin, uses prepared statements, output properly escaped.

### Enhancements
1. **Missing Edit/Delete Links**: No ability to edit donor details from this page.
2. **No Pagination**: Pledges and payments lists not paginated - could be slow for donors with many transactions.

---

## admin/donors/edit.php

**File Path:** `admin/donors/edit.php`

### Overview
**FILE NOT FOUND** - Listed in project but does not exist.

### Critical Issues
- **Missing File**: Edit functionality not implemented.

### Enhancements
- Implement donor editing page or remove from documentation.

---

## admin/donors/delete.php

**File Path:** `admin/donors/delete.php`

### Overview
**FILE NOT FOUND** - Listed in project but does not exist.

### Critical Issues
- **Missing File**: Delete functionality not implemented.

### Enhancements
- Implement donor deletion with proper safeguards or remove from documentation.

---

# Registrar Section (Continued)

## registrar/my-registrations.php

**File Path:** `registrar/my-registrations.php`

### Overview
Registrar's personal registration history page (278 lines) showing their pledges and payments.

### Critical Issues
- **NONE** - Proper authentication, prepared statements, user-scoped queries.

### Enhancements
1. **Search SQL Injection Risk**: Lines 36-38 and 45-48 use LIKE with user input - properly parameterized, but could add further sanitization.
2. **No Export Function**: Registrars cannot export their own registration data.

---

## registrar/profile.php

**File Path:** `registrar/profile.php`

### Overview
Simple read-only profile page (238 lines) displaying current user's account information.

### Critical Issues
- **NONE** - Requires login, reads only current user's data, proper output escaping.

### Enhancements
1. **Error Message Exposure**: Lines 38-39 expose exception message directly to user.
2. **No Profile Editing**: Users cannot update their own information from this page.

---

## registrar/statistics.php

**File Path:** `registrar/statistics.php`

### Overview
Registrar statistics page (215 lines) showing daily activity, package breakdown, and recent submissions.

### Critical Issues
- **NONE** - Requires login, user-scoped queries, prepared statements.

### Enhancements
1. **No Date Range Selection**: Only shows single day statistics - could benefit from date range picker.
2. **No Export Function**: Cannot export statistics data.

---

## registrar/access-denied.php

**File Path:** `registrar/access-denied.php`

### Overview
Access denied error page (89 lines) with auto-redirect countdown.

### Critical Issues
1. **Open Redirect Risk**: Line 11 hardcodes redirect URL `/registrar/index.php` - safe here but pattern should use relative URLs.

### Enhancements
1. **Session Message Not Escaped**: Line 7 reads from session without sanitization before line 54 escapes it - could store XSS payload in session.

---

# Call Center Section

## admin/call-center/make-call.php

**File Path:** `admin/call-center/make-call.php`

### Overview
Call preparation page (883 lines) displaying donor information before initiating Twilio call. Shows call history, pledge amount, and contact details.

### Critical Issues
- **NONE** - Requires login, prepared statements, proper output escaping.

### Enhancements
1. **Error Exposure**: Line 119 exposes exception details in die() statement.
2. **Phone Number in JavaScript**: Lines 750-751 embed user phone in JavaScript - should be passed securely.
3. **CSRF Token in Multiple Places**: Lines 481 and 694 both include CSRF input - redundant.

---

## admin/call-center/api/twilio-initiate-call.php

**File Path:** `admin/call-center/api/twilio-initiate-call.php`

### Overview
AJAX endpoint (241 lines) to initiate Twilio outbound calls. Creates call session and triggers Twilio API.

### Critical Issues
- **NONE** - CSRF verification, prepared statements, proper error handling.

### Enhancements
1. **Session Status Exposure**: Lines 168-179 update session with full error message - could expose internal details.
2. **Exception Message Exposure**: Lines 226-239 return exception message to client - should be generic.

---

# Public Section (Continued)

## public/projector/floor/index.php

**File Path:** `public/projector/floor/index.php`

### Overview
Large floor map visualization page (1537 lines) with interactive grid system. Heavy JavaScript for tile management.

### Critical Issues
- **NONE** - Read-only display page, no user input handling, no authentication required (public view).

### Enhancements
1. **Hardcoded Cron Key**: Line 21 has placeholder cron key comment - ensure production key is unique.
2. **jQuery CDN**: Line 1457 loads jQuery from CDN without SRI hash.
3. **Console Logging**: Extensive console.log statements throughout JavaScript - should be removed in production.

---

# Cron Section

## cron/schedule-payment-reminders.php

**File Path:** `cron/schedule-payment-reminders.php`

### Overview
Cron script (312 lines) for scheduling SMS payment reminders. Runs daily to queue 3-day, due-day, and overdue reminders.

### Critical Issues
1. **Hardcoded Cron Key**: Line 21 has placeholder `'your-secure-cron-key-here'` - must be changed in production.
2. **SQL Injection in Table Check**: Lines 50-54 use string interpolation for table names in SHOW TABLES query.
3. **SQL Date Injection**: Lines 112-118, 160-166, 205-210 use date variables directly in SQL without prepared statements.

### Enhancements
1. **Log File Permissions**: Line 36 creates log files without checking directory permissions.
2. **No Lock Mechanism**: No file lock to prevent concurrent cron execution.
3. **Static Portal Link**: Line 289 hardcodes portal URL - should be configurable.

---

# Webhooks Section

## webhooks/test.php

**File Path:** `webhooks/test.php`

### Overview
Webhook testing page (162 lines) for verifying UltraMsg webhook connectivity. Displays logs and allows sending test messages.

### Critical Issues
1. **NO AUTHENTICATION**: Page is publicly accessible - anyone can view webhook logs and send test payloads.
2. **Log File Manipulation**: Line 114-116 allows clearing log files via POST without authentication.
3. **SSRF Risk**: Lines 142-149 send HTTP request to local webhook URL - could be abused.

### Enhancements
1. **Add Authentication**: Require admin login before allowing access.
2. **Rate Limit Test Sends**: Prevent abuse of test message functionality.
3. **Hide Sensitive Data**: Log payload output may contain sensitive message content.

---

## webhooks/ultramsg.php

**File Path:** `webhooks/ultramsg.php`

### Overview
WhatsApp webhook receiver (reviewed previously) for handling UltraMsg callbacks.

### Critical Issues
- Covered in previous services review.

### Enhancements
- Refer to UltraMsgService findings.

---

# Services Section (Continued)

## services/VoodooSMSService.php

**File Path:** `services/VoodooSMSService.php`

### Overview
VoodooSMS API integration service (536 lines). Handles SMS sending, balance checking, and message logging.

### Critical Issues
1. **SQL Injection in updateProviderStats**: Lines 497-510 use direct query interpolation with `$this->providerId`.
2. **SQL Injection in getCost**: Line 451 uses interpolation with `$this->providerId`.

### Enhancements
1. **Credentials in Memory**: API key and secret are stored in object properties - could be exposed in debug/error dumps.
2. **No Request Signing**: API requests not signed or verified beyond credentials.
3. **Error Logging May Expose Data**: Lines 358, 363-364 log response data to error_log.

---

## services/MessagingHelper.php

**File Path:** `services/MessagingHelper.php`

### Overview
Unified messaging orchestrator (1048 lines) supporting SMS and WhatsApp with intelligent channel selection.

### Critical Issues
1. **SQL Injection in History Query**: Lines 874-875, 905-906 build SQL with donor phone using `real_escape_string` - should use prepared statements.
2. **SQL Injection in Stats Query**: Lines 979, 1007 use string interpolation with escaped phone - prefer prepared statements.

### Enhancements
1. **Dynamic Table Creation**: Line 376-384 creates table via dynamic SQL - could be risky if called frequently.
2. **Large Parameter Count**: Lines 743-782 bind 37+ parameters - complex and error-prone.
3. **Fallback Chain**: WhatsApp failures fall back to SMS without explicit logging of the channel switch.

---

# Admin Includes Section

## admin/includes/topbar.php

**File Path:** `admin/includes/topbar.php`

### Overview
Reusable admin topbar component (100 lines) with navigation, messages badge, and user dropdown.

### Critical Issues
- **NONE** - Requires authenticated user context, proper output escaping.

### Enhancements
1. **Graceful Error Handling**: Lines 23-27 catch database exceptions but silently suppress - should log.
2. **Missing CSRF on Links**: Quick action links navigate without token verification.

---

## admin/includes/sidebar.php

**File Path:** `admin/includes/sidebar.php`

### Overview
Admin sidebar navigation component (reviewed previously).

### Critical Issues
- **NONE** - Static navigation with proper escaping.

### Enhancements
- Refer to previous sidebar findings.

---

# Admin Donor Management Section

## admin/donor-management/donors.php

**File Path:** `admin/donor-management/donors.php`

### Overview
Large donor list page (1659 lines) with AJAX CRUD operations, DataTables integration, search, filtering, and detail modals.

### Critical Issues
- **NONE** - CSRF protection on all POST actions. Prepared statements throughout. Input validation on donor operations.

### Enhancements
1. **Dynamic SQL Column Check**: Lines 341-346 query table structure dynamically - potential information disclosure.
2. **Complex WHERE Building**: Lines 348-409 build WHERE clauses with multiple parameters - could be simplified.
3. **Phone Number Normalization**: Lines 233-236 check duplicates but don't fully normalize phone formats.
4. **JSON in Data Attribute**: Line 824 embeds full donor data as JSON in HTML attribute - potential XSS if escaping fails.
5. **JavaScript Console Dependency**: Heavy client-side JavaScript for modals without server-side fallback.

---

## admin/donor-management/add-donor.php

**File Path:** `admin/donor-management/add-donor.php`

### Overview
Add donor form (505 lines) creating pending pledges/payments for admin approval. Supports donation packages and duplicate checking.

### Critical Issues
- **NONE** - CSRF protection. Prepared statements. Transaction-based with rollback. Proper input validation.

### Enhancements
1. **Package Query Not Prepared**: Lines 19-20 query donation_packages without prepared statement (no user input).
2. **Anonymous Handling**: Anonymous donors save as "Anonymous" name - original name still accessible in session/logs.
3. **UUID Collision Check**: Lines 164-169 check UUID collision but doesn't retry on collision - throws exception.
4. **Approval Workflow Required**: All submissions go to pending state - ensures oversight.

---

## admin/donor-management/edit-donor.php

**File Path:** `admin/donor-management/edit-donor.php`

### Overview
POST-only donor update handler (210 lines). Updates basic donor information with validation and audit logging.

### Critical Issues
- **NONE** - Transaction-wrapped. Prepared statements. Duplicate phone check. Proper field validation.

### Enhancements
1. **Display Errors Enabled**: Lines 5-6 enable error display in production - should be disabled.
2. **Direct $_POST Access**: Lines 87-121 iterate $_POST keys - though validated against whitelist.
3. **Error Log to File**: Lines 14-28 write detailed logs to custom file - ensure file permissions are secure.
4. **Fatal Error Handler**: Lines 32-40 register shutdown function - good practice.

---

## admin/donor-management/view-donor.php

**File Path:** `admin/donor-management/view-donor.php`

### Overview
Very large donor detail view (2900+ lines). Comprehensive donor profile with pledges, payments, payment plans, call history, and message history.

### Critical Issues
1. **File Too Large**: At 2900+ lines, this file is too large to review in full in one pass.

### Enhancements
1. **Code Splitting Recommended**: Split into separate modules (profile, pledges, payments, plans, history).
2. **Manual Security Audit**: Recommend detailed line-by-line security review of this critical file.

---

## admin/donor-management/delete-pledge.php

**File Path:** `admin/donor-management/delete-pledge.php`

### Overview
Pledge deletion confirmation page (446 lines). Only allows deletion of rejected pledges with dependency handling.

### Critical Issues
- **NONE** - CSRF protection. Restricted to rejected pledges only. Transaction-wrapped. Proper audit logging.

### Enhancements
1. **Timezone Hardcoded**: Line 13 sets timezone to 'Europe/London' - should use config.
2. **Good Safety Checks**: Lines 75-83 prevent deletion of non-rejected pledges - good security.
3. **Cell Deallocation**: Lines 93-109 properly deallocate floor grid cells.

---

## admin/donor-management/sms/send.php

**File Path:** `admin/donor-management/sms/send.php`

### Overview
SMS sending form (491 lines) with donor search, template selection, scheduling, and VoodooSMS integration.

### Critical Issues
1. **Display Errors Enabled**: Lines 5-6 enable error display in production - security risk.
2. **Die on Error**: Lines 10-40 die() with error messages exposing internal details.

### Enhancements
1. **Search Results Escaping**: Lines 447-451 build HTML from AJAX results - potential XSS in donor name if not escaped by server.
2. **Template Variable Injection**: Line 338 mentions variables like {name} - ensure proper sanitization.
3. **Phone Number Validation**: Lines 118-120 validate required but don't enforce format.

---

## admin/donor-management/sms/blacklist.php

**File Path:** `admin/donor-management/sms/blacklist.php`

### Overview
SMS blacklist management page (336 lines) for blocking phone numbers from receiving SMS.

### Critical Issues
1. **Display Errors Enabled**: Lines 5-6 enable error display in production.
2. **SQL Injection Risk**: Line 87 uses direct query interpolation with `$donor_id` (though it's from internal lookup).
3. **SQL Injection Risk**: Line 119 uses direct query interpolation with `$row['donor_id']`.

### Enhancements
1. **Use Prepared Statements**: Replace string interpolation in lines 87, 119 with prepared statements.
2. **Phone Normalization**: Line 63 strips non-numeric characters but doesn't validate UK format.
3. **Confirmation on Unblock**: Line 269 has inline confirm - consider modal for consistency.

---

## admin/donor-management/sms/history.php

**File Path:** `admin/donor-management/sms/history.php`

### Overview
SMS history log viewer (660 lines) with filtering, search, pagination, and delete functionality.

### Critical Issues
1. **Display Errors Enabled**: Lines 5-6 enable error display in production.

### Enhancements
1. **Proper Deletion**: Lines 32-74 handle deletion with CSRF verification and audit.
2. **Filter Sanitization**: Lines 107-155 properly build parameterized WHERE clauses.
3. **HTML Escaping**: Lines 559-641 build modal HTML in JavaScript - potential XSS if data not properly escaped.

---

# Church Management Section

## admin/church-management/add-church.php

**File Path:** `admin/church-management/add-church.php`

### Overview
Add church form (225 lines) for creating new church records with validation and audit logging.

### Critical Issues
1. **Missing CSRF Protection**: Form submission at line 13 does not verify CSRF token.

### Enhancements
1. **Error Message Exposure**: Lines 60, 63 expose database error messages directly to user.
2. **Phone Validation**: Line 30-31 validates phone format with regex - good pattern but could be stricter for UK format.
3. **Good Audit Logging**: Lines 46-55 properly log creation with all relevant data.

---

## admin/church-management/edit-church.php

**File Path:** `admin/church-management/edit-church.php`

### Overview
Edit church form (256 lines) for updating church records with validation and audit logging.

### Critical Issues
1. **Missing CSRF Protection**: Form submission at line 38 does not verify CSRF token.

### Enhancements
1. **Good ID Validation**: Lines 12-35 properly validate church_id and fetch with prepared statement.
2. **Good Before/After Audit**: Lines 68-77 capture before and after state in audit log.
3. **Error Exposure**: Lines 82, 85 expose database error messages.

---

## admin/church-management/view-church.php

**File Path:** `admin/church-management/view-church.php`

### Overview
Read-only church detail view (318 lines) showing church info, representatives, and assigned donor count.

### Critical Issues
- **NONE** - Read-only page, proper authentication, prepared statements, output escaping.

### Enhancements
1. **Delete Link Without Confirmation**: Line 176 links to delete page without inline confirmation.
2. **Good Dependency Display**: Lines 239-256 show related statistics clearly.

---

## admin/church-management/delete-church.php

**File Path:** `admin/church-management/delete-church.php`

### Overview
Church deletion confirmation page (248 lines) with dependency checking and proper cleanup.

### Critical Issues
1. **Missing CSRF Protection**: Deletion POST at line 50 does not verify CSRF token.

### Enhancements
1. **Good Dependency Handling**: Lines 54-66 properly delete representatives and unlink donors.
2. **Transaction Wrapped**: Lines 52-85 use transaction with rollback on failure.
3. **Comprehensive Audit**: Lines 69-78 log deletion with dependency counts.

---

# Registrar Section (Extended)

## registrar/messages/index.php

**File Path:** `registrar/messages/index.php`

### Overview
Registrar messaging page (216 lines) with same AJAX endpoints as admin messaging. Provides user-to-user messaging for registrar role.

### Critical Issues
- **NONE** - CSRF protection on send. Prepared statements throughout. Proper authorization check.

### Enhancements
1. **Role Check Method**: Line 8 uses `in_array()` for role check - good but could use dedicated function.
2. **Blocklist Checking**: Lines 107-110 properly check both directions of blocklist.
3. **Idempotency Via UUID**: Lines 112-116 prevent duplicate messages - good practice.
4. **Message Body Not Sanitized**: Line 103 only trims message body, no XSS sanitization before storage.

---

## registrar/includes/sidebar.php

**File Path:** `registrar/includes/sidebar.php`

### Overview
Registrar sidebar navigation component (86 lines) with dynamic active state detection.

### Critical Issues
- **NONE** - Static navigation with proper URL escaping.

### Enhancements
1. **Good URL Helper Usage**: Uses `url_for()` for proper URL generation in subfolder deployments.
2. **Collapsed by Default**: Line 14 - sidebar starts collapsed, good for mobile.

---

## registrar/includes/topbar.php

**File Path:** `registrar/includes/topbar.php`

### Overview
Registrar topbar component (127 lines) with user menu, message notifications, and dynamic page titles.

### Critical Issues
- **NONE** - Proper authentication context, prepared statements, output escaping.

### Enhancements
1. **Good Unread Count**: Lines 17-27 fetch unread message count with prepared statement.
2. **Dynamic Path Detection**: Lines 97-117 properly handle different subdirectory contexts.

---

# Shared Section

## shared/url.php

**File Path:** `shared/url.php`

### Overview
URL helper functions (29 lines) for building links that work under subfolders.

### Critical Issues
- **NONE** - Simple utility functions with no security implications.

### Enhancements
1. **Marker-Based Detection**: Lines 12-17 use folder markers to detect base path - works well for this project structure.
2. **Edge Case Handling**: Lines 18-20 handle root folder deployments.

---

## shared/FloorGridAllocator.php

**File Path:** `shared/FloorGridAllocator.php`

### Overview
Floor grid cell allocation service (401 lines) implementing smart allocation based on donation amounts and package types.

### Critical Issues
- **NONE** - Uses prepared statements throughout. Transaction-wrapped operations. Good error handling.

### Enhancements
1. **Good Transaction Management**: Lines 47, 71, 81 properly wrap operations in transaction with commit/rollback.
2. **Hardcoded Rectangle Config**: Lines 13-21 hardcode floor plan layout - could be made configurable.
3. **Error Logging**: Line 82 logs errors to error_log - good practice.
4. **Bind Param Syntax Error**: Line 346 has malformed bind_param call with extra space - potential runtime error.

---

## shared/CustomAmountAllocator.php

**File Path:** `shared/CustomAmountAllocator.php`

### Overview
Custom amount allocation handler (384 lines) implementing accumulation and threshold-based cell allocation.

### Critical Issues
- **NONE** - Transaction-wrapped. Prepared statements. Good error handling.

### Enhancements
1. **Extensive Debug Logging**: Lines 33, 39, 56, 85, etc. contain many error_log calls - should be conditional on debug mode.
2. **Good Business Logic**: Lines 36-53, 56-67 implement clear accumulation rules.
3. **IntelligentGridAllocator Dependency**: Line 162 instantiates IntelligentGridAllocator - ensure class is loaded.
4. **Magic Numbers**: Lines 301-304 use hardcoded thresholds (400, 200, 100) - could be configurable.

---

# Services Section (Additional)

## services/TwilioErrorCodes.php

**File Path:** `services/TwilioErrorCodes.php`

### Overview
Twilio error code mapping utility (272 lines) providing human-readable error messages and recommended actions.

### Critical Issues
- **NONE** - Pure utility class with no database or external interactions.

### Enhancements
1. **Well-Documented Error Codes**: Lines 29-185 contain comprehensive error mappings with clear actions.
2. **Good Helper Methods**: Lines 205-270 provide retryable, bad-number, and action recommendation helpers.

---

## services/IVRRecordingService.php

**File Path:** `services/IVRRecordingService.php`

### Overview
IVR recording service (158 lines) providing TwiML generation with fallback to TTS.

### Critical Issues
1. **SQL Injection via String Interpolation**: Line 127 uses `real_escape_string` instead of prepared statement - potential SQL injection.

### Enhancements
1. **Good Caching**: Lines 25-42 cache all recordings upfront to reduce database queries.
2. **Proper Output Escaping**: Lines 60, 76, 140 use htmlspecialchars for TwiML output.
3. **Silently Failing Non-Critical Operations**: Lines 121-131 silently fail play count updates - appropriate for analytics.

---

# Call Center API Section

## admin/call-center/api/twilio-initiate-call.php

**File Path:** `admin/call-center/api/twilio-initiate-call.php`

### Overview
AJAX endpoint (241 lines) to initiate Twilio calls from agent to donor with session tracking.

### Critical Issues
- **NONE** - CSRF protection. Proper authentication. Prepared statements throughout.

### Enhancements
1. **Good Error Handler**: Lines 12-14 convert errors to exceptions for consistent handling.
2. **Dynamic Column Detection**: Lines 67-131 dynamically detect table columns - resilient to schema changes.
3. **Error Message in Response**: Line 232 exposes internal error messages - should be sanitized for production.
4. **Good Output Buffering**: Lines 11, 215, 227, 234 use ob_start/ob_end_clean for clean responses.

---

## admin/call-center/api/twilio-status-callback.php

**File Path:** `admin/call-center/api/twilio-status-callback.php`

### Overview
Twilio webhook receiver (259 lines) for real-time call status updates.

### Critical Issues
1. **No Authentication on Webhook**: Webhook endpoint is publicly accessible - should verify Twilio signature.

### Enhancements
1. **Good Dial Status Handling**: Lines 46-57, 101-127 properly handle DialCallStatus for accurate donor outcomes.
2. **Comprehensive Logging**: Lines 59-79 log webhooks to database for debugging.
3. **Dynamic SQL Building**: Lines 171-196 properly build parameterized update query.
4. **Silent Failures for Non-Critical**: Lines 237-241 silently fail webhook marking - appropriate.

---

## admin/call-center/api/get-donor-profile.php

**File Path:** `admin/call-center/api/get-donor-profile.php`

### Overview
API endpoint (358 lines) returning comprehensive donor profile data as JSON for call widget.

### Critical Issues
- **NONE** - Requires authentication. Prepared statements throughout. Proper input validation.

### Enhancements
1. **Good Dynamic Column Detection**: Lines 82-93 detect available payment table columns for compatibility.
2. **UNION ALL for Combined Payments**: Lines 96-136 properly combine instant and pledge payments.
3. **Accurate Total Recalculation**: Lines 192-228 recalculate totals when donor record has zeroes.
4. **Well-Structured Response**: Lines 259-347 return comprehensive, well-organized JSON response.

---

## admin/call-center/api/twilio-ivr-donor-menu.php

**File Path:** `admin/call-center/api/twilio-ivr-donor-menu.php`

### Overview
IVR donor menu handler (345 lines) implementing balance check, payment selection, and church contact options.

### Critical Issues
1. **No Authentication on Webhook**: IVR endpoint is publicly accessible - should verify Twilio signature.
2. **Hardcoded Contact Info**: Lines 202-203 hardcode church admin name and phone - should be configurable.

### Enhancements
1. **Good TwiML Generation**: Lines 41-79 properly generate XML responses.
2. **Debug Logging**: Line 18 logs all POST/GET data - should be conditional on debug mode.
3. **Good Helper Functions**: Lines 281-344 provide clean utility functions.
4. **SMS Integration**: Lines 248-276 properly send SMS via SMSHelper.

---

## admin/donors/index.php

**File Path:** `admin/donors/index.php`

### Overview
Minimal placeholder page (3 lines) - appears to be a stub.

### Critical Issues
1. **Incomplete Page**: Only outputs "hello" - should be a full donor list or redirect.

### Enhancements
1. **Complete Implementation**: Either implement donor list or redirect to donor-management section.

---

## admin/donors/view.php

**File Path:** `admin/donors/view.php`

### Overview
Basic donor detail view (195 lines) showing pledges and payments.

### Critical Issues
- **NONE** - Requires admin access. Prepared statements. Proper output escaping.

### Enhancements
1. **Simpler Than Donor-Management Version**: This is a simpler view compared to donor-management/view-donor.php.
2. **Good ID Validation**: Line 10 uses max(1, ...) to ensure valid ID.
3. **Consistent Escaping**: Uses htmlspecialchars() throughout for output.

---

# Config Section

## config/env.php

**File Path:** `config/env.php`

### Overview
Environment detection and database configuration (79 lines) with automatic local/production switching.

### Critical Issues
1. **HARDCODED PRODUCTION CREDENTIALS**: Lines 59-61 contain production database username and password in source code. This is a CRITICAL security vulnerability if code is exposed or committed to public repositories.

### Enhancements
1. **Good Environment Detection**: Lines 9-41 provide robust local environment detection.
2. **Session Hardening**: Lines 74-79 implement good session security settings.
3. **Error Display Disabled**: Lines 69-71 properly disable error display in production.
4. **Move Credentials to .env File**: Production credentials should be in an environment file outside web root.

---

## admin/includes/resilient_db_loader.php

**File Path:** `admin/includes/resilient_db_loader.php`

### Overview
Resilient database loading helper (38 lines) that prevents fatal errors when tables are missing.

### Critical Issues
- **NONE** - Properly catches exceptions and handles missing tables gracefully.

### Enhancements
1. **Good Error Handling**: Lines 13-37 catch exceptions and track which tables are missing.
2. **Table Existence Checks**: Lines 18-19 check table existence before querying.

---

## shared/noindex.php

**File Path:** `shared/noindex.php`

### Overview
Simple meta tag include (10 lines) to block search engine indexing.

### Critical Issues
- **NONE** - Simple utility include with no security implications.

### Enhancements
1. **Good Practice**: Properly blocks all major search engine crawlers.

---

## donor/includes/topbar.php

**File Path:** `donor/includes/topbar.php`

### Overview
Donor portal topbar component (63 lines) with user avatar and dropdown menu.

### Critical Issues
- **NONE** - Proper output escaping. Uses url_for() helper for links.

### Enhancements
1. **Good URL Helper Usage**: Uses url_for() for proper subfolder URL generation.
2. **Avatar Generation**: Lines 4-12 properly generate initials from donor name.

---

# Admin Reports Section

## admin/reports/index.php

**File Path:** `admin/reports/index.php`

### Overview
Reports hub page (821 lines) with summary statistics, CSV/Excel exports, and quick date range filtering. Uses FinancialCalculator for totals.

### Critical Issues
1. **Missing CSRF on Exports**: Export links use GET parameters without CSRF validation.
2. **Potential CSV Injection**: Lines 81-129 write donor data directly to CSV without sanitization for formula injection (=, @, +, -).

### Enhancements
1. **CSV Formula Injection Protection**: Prefix cell values with single quote or tab to prevent formula injection.
2. **Export Rate Limiting**: Large exports could be abused for DoS.
3. **Date Range Validation**: Lines 57-71 accept date ranges without validation of reasonable limits.
4. **Excel Export XSS**: Lines 167-176 output HTML with Excel doctype - could be XSS vector if downloaded and opened.

---

## admin/reports/financial-dashboard.php

**File Path:** `admin/reports/financial-dashboard.php`

### Overview
Empty file (1 line) - appears to be a placeholder or accidentally cleared.

### Critical Issues
1. **Empty File**: File is empty - functionality not implemented.

### Enhancements
1. **Implement or Remove**: Either implement the financial dashboard or remove from navigation.

---

## admin/reports/visual.php

**File Path:** `admin/reports/visual.php`

### Overview
Visual reporting page (372 lines) with ECharts-based charts for packages, methods, time series, and status distributions. Uses FinancialCalculator for data.

### Critical Issues
- **NONE** - Requires admin login. Uses prepared statements for some queries. Output properly escaped.

### Enhancements
1. **SQL Injection Pattern**: Lines 89-102 use `$db->real_escape_string()` in string concatenation instead of prepared statements - should be consistent.
2. **Error Display**: Lines 162-166 expose database error message to users.
3. **Good Chart Implementation**: ECharts integration with proper data sanitization for JSON output.

---

## admin/reports/comprehensive.php

**File Path:** `admin/reports/comprehensive.php`

### Overview
Very large comprehensive report page (1996+ lines) with detailed metrics, breakdowns, time series, top donors, and pledge payment tracking.

### Critical Issues
1. **File Too Large**: At 1996+ lines, this file is too large to review in full in one pass.
2. **SQL Injection Pattern**: Lines 89, 94, 102 use `$db->real_escape_string()` in string concatenation instead of prepared statements.

### Enhancements
1. **Code Splitting**: File is too large - should be split into smaller modules.
2. **Use Prepared Statements Consistently**: Replace string interpolation with prepared statements throughout.
3. **Error Message Exposure**: Lines 22-27 expose database connection errors to users.

---

## admin/reports/api/financial-data.php

**File Path:** `admin/reports/api/financial-data.php`

### Overview
JSON API endpoint (245 lines) returning KPIs, trends, payment methods, pledge status, top donors, and recent transactions.

### Critical Issues
- **NONE** - Requires admin login. Uses raw queries but no user input interpolation. Proper JSON output.

### Enhancements
1. **Error Message Exposure**: Lines 236-241 return exception message to client - should be sanitized.
2. **Good Data Aggregation**: Properly combines direct payments and pledge payments.
3. **Month Normalization**: Lines 87-101 properly initialize last 12 months with zeros.

---

# Admin Donations Section (Extended)

## admin/donations/record-pledge-payment.php

**File Path:** `admin/donations/record-pledge-payment.php`

### Overview
Multi-step pledge payment recording page (1181 lines) with donor search, pledge selection, and payment form with file upload.

### Critical Issues
- **NONE** - Requires login with role check. Uses prepared statements throughout. Proper form submission via AJAX.

### Enhancements
1. **Large File**: At 1181 lines, consider splitting into separate components.
2. **Search Error in HTML Comment**: Line 134 outputs exception message in HTML comment - could leak info.
3. **Good Donor History**: Lines 807-929 load and display comprehensive donor payment history.
4. **Form Validation**: Lines 1121-1166 properly validate and submit via AJAX.

---

## admin/donations/review-pledge-payments.php

**File Path:** `admin/donations/review-pledge-payments.php`

### Overview
Pledge payment review page (1155 lines) with filtering, search, pagination, and approve/reject actions.

### Critical Issues
- **NONE** - Requires login with role check. Uses prepared statements. Proper CSRF on actions via JavaScript.

### Enhancements
1. **Good Dynamic Column Check**: Line 63 checks if payment_plan_id column exists.
2. **Clean Pagination**: Lines 1019-1043 implement proper pagination with URL building.
3. **Action Buttons**: Approve/Reject/Undo actions handled via AJAX with confirmation.

---

## admin/donations/approve-pledge-payment.php

**File Path:** `admin/donations/approve-pledge-payment.php`

### Overview
AJAX endpoint (290 lines) for approving pending pledge payments. Updates donor totals and payment plan progress.

### Critical Issues
- **NONE** - Requires login with role check. Transaction-wrapped. Uses FinancialCalculator for donor totals. Comprehensive audit logging.

### Enhancements
1. **Good Transaction Management**: Lines 32, 256 wrap all operations in transaction with commit/rollback.
2. **Payment Plan Integration**: Lines 91-215 properly update payment plan when payment is explicitly linked.
3. **Date Calculation**: Lines 141-165 properly calculate next payment due date based on frequency.
4. **Error Logging**: Line 281 logs full stack trace - ensure logs are secure.

---

## admin/approvals/partial_list.php

**File Path:** `admin/approvals/partial_list.php`

### Overview
Partial view (277 lines) rendering pending approval items list. Included by main approvals/index.php.

### Critical Issues
- **NONE** - Requires admin. Uses prepared statements. CSRF tokens on all forms.

### Enhancements
1. **Good CSRF Implementation**: Lines 228, 251 include csrf_input() on all action forms.
2. **Repeat Donor Detection**: Lines 174-180 identify repeat donors with badge.
3. **Update Request Badge**: Lines 182-188 highlight donor portal updates.
4. **Parameter Preservation**: Lines 239-245 preserve filter/pagination on form submit.

---

# Call Center Section (Extended)

## admin/call-center/call-history.php

**File Path:** `admin/call-center/call-history.php`

### Overview
Call history page (329 lines) with filtering by outcome, date range, and agent. Role-based access control limits registrars to their own calls.

### Critical Issues
- **NONE** - Requires login. Uses prepared statements throughout. Role-based filtering enforced server-side.

### Enhancements
1. **Good Role-Based Access**: Lines 20-33 enforce registrar limitation to own calls.
2. **Dynamic WHERE Building**: Lines 40-78 properly build parameterized WHERE clause.
3. **Limit Without Pagination**: Line 98 limits to 100 records but no pagination.
4. **Twilio Error Integration**: Lines 275-286 display human-readable Twilio error messages.

---

# WhatsApp Messaging Section

## admin/messaging/whatsapp/inbox.php

**File Path:** `admin/messaging/whatsapp/inbox.php`

### Overview
Very large WhatsApp inbox page (5250 lines) with real-time messaging, conversation management, and media handling.

### Critical Issues
1. **File Too Large**: At 5250 lines, this file is too large to review in full in one pass.

### Enhancements
1. **Code Splitting Critical**: This file desperately needs to be split into separate modules (inbox, conversation, message handlers).
2. **Manual Security Audit Required**: Due to size and complexity, recommend detailed manual review.

---

# Public Section (Extended)

## public/projector/floor/3d-view.php

**File Path:** `public/projector/floor/3d-view.php`

### Overview
3D floor visualization page (618 lines) using Three.js for WebGL rendering. Allows loading custom OBJ models with interactive controls.

### Critical Issues
- **NONE** - Public view page. No user input processed server-side. Uses CDN-loaded Three.js.

### Enhancements
1. **CDN Without SRI**: Lines 320-322 load Three.js without Subresource Integrity hashes.
2. **Client-Side Only**: All logic is client-side JavaScript with no database interaction.
3. **Good Fullscreen Handling**: Lines 554-582 properly toggle controls visibility in fullscreen.

---

# Donor Section (Extended)

## donor/payment-history.php

**File Path:** `donor/payment-history.php`

### Overview
Donor payment history page (470 lines) displaying all pledge payments and instant payments. Mobile-responsive with card/table views.

### Critical Issues
- **NONE** - Requires donor login. Uses prepared statements. Proper output escaping.

### Enhancements
1. **Good Device Validation**: Line 26 calls validate_donor_device() to check for revoked devices.
2. **Combined Payment Sources**: Lines 41-88 combine pledge_payments and payments tables.
3. **Proper Date Sorting**: Lines 91-95 sort combined results by date.
4. **Debug Mode Exposure**: Lines 218-227 show debug info when ?debug is in URL - should remove in production.
5. **Error Suppression**: Lines 112-113 use @filemtime for cache busting.

---

# Donor API Section

## donor/api/location-data.php

**File Path:** `donor/api/location-data.php`

### Overview
API endpoint (99 lines) providing location data for city/church/representative selection. Supports multiple actions: get_cities, get_churches, get_representatives, and assign_rep.

### Critical Issues
1. **Missing CSRF Protection**: Line 55 `assign_rep` action modifies donor data via POST but does not verify CSRF token, making it vulnerable to CSRF attacks.
2. **Inconsistent Auth Check**: Lines 8-12 check for either user or donor session but `assign_rep` at line 57 only requires donor session - inconsistent authorization model.

### Enhancements
1. **Good Prepared Statements**: Lines 18, 31, 45, 66, 75, 78 all use prepared statements.
2. **Representative Validation**: Lines 65-69 verify representative belongs to selected church before assignment.
3. **Dynamic Column Detection**: Lines 73-79 check if `representative_id` column exists before including it in update.
4. **Session Update**: Lines 84-85 properly update session after assignment.

---

## public/reports/donor-review.php

**File Path:** `public/reports/donor-review.php`

### Overview
Public donor review report (799 lines) for data collectors. Mobile-first design displaying donor issues like missing phones, phone format problems, and payment discrepancies. NO authentication required.

### Critical Issues
1. **Hardcoded PII Exposure**: Lines 14-111 contain hardcoded donor names, phone numbers, pledge amounts, and payment notes. This is a **CRITICAL** data privacy violation exposing real donor data in source code.
2. **No Authentication**: This is a PUBLIC page with no login requirement exposing donor personal information.
3. **No Access Control**: Anyone with the URL can view donor financial and contact details.
4. **Sensitive Data in Git**: If this file is version-controlled, donor PII is in repository history.

### Enhancements
1. **IMMEDIATE ACTION REQUIRED**: Remove all hardcoded donor data and fetch from database with proper authentication.
2. **SEO Prevention**: Lines 299-300 add noindex meta tags, but this only prevents indexing, not access.
3. **Proper Output Escaping**: Line 291 `h()` function properly escapes output.
4. **Good Issue Categorization**: Logic correctly identifies and categorizes data quality issues.

---

# Admin SMS Section

## admin/donor-management/sms/queue.php

**File Path:** `admin/donor-management/sms/queue.php`

### Overview
SMS queue management page (355 lines) displaying pending, processing, and failed SMS messages. Allows canceling pending messages and retrying failed ones.

### Critical Issues
1. **Error Message Exposure**: Lines 11, 17, 23, 30, 39, 54 use `die()` with exception messages that could expose internal paths or sensitive details.
2. **Error Display Enabled**: Lines 5-6 enable error display in production which could expose sensitive information.

### Enhancements
1. **Good CSRF Protection**: Lines 58-59 verify CSRF for POST actions.
2. **Prepared Statements**: Lines 65, 78 use prepared statements for queue item updates.
3. **Role-Based Access**: Lines 27-28 require admin login.
4. **Dynamic Stats Display**: Lines 94-102 properly aggregate queue statistics.
5. **Graceful Table Check**: Lines 51-55 check if SMS tables exist before querying.

---

## admin/donor-management/sms/templates.php

**File Path:** `admin/donor-management/sms/templates.php`

### Overview
SMS template management page (626 lines) for creating, editing, and deleting SMS templates. Supports multi-language templates (English, Amharic, Tigrinya) with variable substitution.

### Critical Issues
1. **Error Message Exposure**: Lines 11, 17, 23, 30, 40, 46, 62, 71 use `die()` with exception messages that could expose internal paths or sensitive details.
2. **Error Display Enabled**: Lines 5-6 enable error display in production which could expose sensitive information.
3. **XSS in Delete Modal**: Line 545 uses `htmlspecialchars(addslashes(...))` for template name in onclick handler - the escaping is redundant but the onclick attribute construction could be improved.

### Enhancements
1. **Good CSRF Protection**: Line 69 verifies CSRF for POST actions.
2. **Template Key Sanitization**: Line 96 sanitizes template key to lowercase alphanumeric with underscores.
3. **Input Validation**: Lines 91-93 validate required fields.
4. **Prepared Statements**: Lines 99, 123, 155 use prepared statements for all template operations.
5. **Multi-Language Support**: Lines 84-85 support Amharic and Tigrinya translations.
6. **Character Count**: Lines 597-616 provide real-time character count for SMS length optimization.

---

# Admin Donations Section

## admin/donations/void-pledge-payment.php

**File Path:** `admin/donations/void-pledge-payment.php`

### Overview
AJAX endpoint (110 lines) for rejecting pending pledge payments. Updates payment status to 'voided' with reason tracking and audit logging.

### Critical Issues
1. **Missing CSRF Protection**: The endpoint accepts POST requests via JSON but does not verify a CSRF token, making it vulnerable to CSRF attacks.

### Enhancements
1. **Good Role-Based Access**: Lines 13-15 allow both admin and registrar roles.
2. **Prepared Statements**: Lines 40, 54 use prepared statements for database operations.
3. **Transaction Management**: Lines 37, 95 wrap operations in a transaction.
4. **Reason Required**: Line 32-34 require a rejection reason for accountability.
5. **Status Validation**: Lines 49-51 only allow voiding of pending payments.
6. **Comprehensive Audit**: Lines 68-93 log before/after states with user details.

---

## admin/donations/undo-pledge-payment.php

**File Path:** `admin/donations/undo-pledge-payment.php`

### Overview
AJAX endpoint (395 lines) for reversing previously approved pledge payments. Complex logic handles donor balance recalculation and payment plan reversal.

### Critical Issues
1. **Missing CSRF Protection**: The endpoint accepts POST requests via JSON but does not verify a CSRF token, making it vulnerable to CSRF attacks.

### Enhancements
1. **Good Role-Based Access**: Lines 12-14 allow both admin and registrar roles.
2. **Prepared Statements**: All database operations use prepared statements.
3. **Transaction Management**: Lines 36, 373 wrap all operations in a transaction.
4. **Reason Required**: Lines 31-32 require an undo reason for audit trail.
5. **FinancialCalculator Integration**: Lines 80-87 use centralized calculator for balance recalculation.
6. **Complex Plan Reversal Logic**: Lines 89-345 handle all edge cases for payment plan reversal including reactivation of completed plans.
7. **Dynamic Column Detection**: Lines 40, 291 check for column existence before operations.
8. **Comprehensive Audit**: Lines 347-371 log detailed state changes including IP address.

---

## admin/donations/index.php

**File Path:** `admin/donations/index.php`

### Overview
Main donations management page (1083 lines) combining payments and pledges with CRUD operations, filtering, pagination, and statistics dashboard.

### Critical Issues
1. **Error Message Exposure**: Line 250 exposes exception message to user via URL parameter which could leak sensitive information.

### Enhancements
1. **Good CSRF Protection**: Line 18 verifies CSRF for all POST actions.
2. **Prepared Statements**: All database operations use prepared statements throughout.
3. **Transaction Management**: Lines 23, 247 wrap multi-step operations in transactions.
4. **Comprehensive Audit Logging**: Lines 78-82, 109-113, 146-151, etc. log all CRUD operations.
5. **Input Validation**: Lines 35-36, 128-131, 165-167 validate input before processing.
6. **Whitelist Filtering**: Lines 258-264 validate filter values against whitelists.
7. **Dynamic UNION Query**: Lines 270-311 combine payments and pledges with proper field mapping.
8. **Robust Pagination**: Lines 357-395 implement parameterized pagination with error handling.

---

## admin/donations/delete-pledge-payment.php

**File Path:** `admin/donations/delete-pledge-payment.php`

### Overview
AJAX endpoint (133 lines) for permanently deleting voided pledge payments. Includes file cleanup and comprehensive audit logging.

### Critical Issues
1. **Missing CSRF Protection**: The endpoint accepts POST requests via JSON but does not verify a CSRF token, making it vulnerable to CSRF attacks.
2. **Path Traversal Risk**: Lines 80-81 construct file path using `$payment['payment_proof']` without sanitization. If the stored path contains `../`, it could delete files outside the intended directory.

### Enhancements
1. **Admin-Only Access**: Line 11 restricts to admin role only.
2. **Voided-Only Deletion**: Lines 55-58 only allow deletion of voided payments, preventing accidental data loss.
3. **Double Verification**: Lines 45-53 verify payment exists and belongs to the specified donor.
4. **Prepared Statements**: All database operations use prepared statements.
5. **Transaction Management**: Lines 38, 114 wrap operations in a transaction.
6. **File Cleanup**: Lines 79-82 delete associated payment proof files.
7. **Comprehensive Audit**: Lines 93-112 log full payment details before deletion.

---

# Admin Call Center API Section

## admin/call-center/api/get-call-status.php

**File Path:** `admin/call-center/api/get-call-status.php`

### Overview
API endpoint (81 lines) for polling real-time Twilio call status. Returns session data including Twilio status, duration, and error information.

### Critical Issues
- **NONE** - Requires login. Uses prepared statements. Read-only operation.

### Enhancements
1. **Good Authentication**: Line 16 requires login.
2. **Prepared Statement**: Lines 26-44 use prepared statement for data retrieval.
3. **Comprehensive Response**: Lines 57-71 return all relevant call status fields.
4. **Input Validation**: Lines 19-23 validate session ID.

---

## admin/call-center/api/save-ivr-recording.php

**File Path:** `admin/call-center/api/save-ivr-recording.php`

### Overview
API endpoint (304 lines) for saving IVR voice recordings from browser MediaRecorder. Handles file uploads, FFmpeg conversion from WebM to MP3, and version history.

### Critical Issues
1. **Missing CSRF Protection**: The endpoint accepts POST requests with file uploads but does not verify a CSRF token.
2. **File Type Validation Weak**: Lines 72-75 only check extension, not file content (MIME type sniffing). An attacker could upload a malicious file with a .mp3 extension.
3. **Path Traversal Risk**: Line 65 uses `$recording['recording_key']` in filename construction without sanitization.

### Enhancements
1. **Good Authentication**: Line 20 requires login.
2. **FFmpeg Integration**: Lines 99-124 attempt to convert WebM to MP3 for Twilio compatibility.
3. **Version History**: Lines 251-273 save recording versions for rollback capability.
4. **Activity Logging**: Lines 278-293 log recording actions with IP address.
5. **Dynamic Update Query**: Lines 151-173 build parameterized UPDATE query dynamically.
6. **Old File Cleanup**: Lines 143-145 delete old recording files.

---

## admin/call-center/api/twilio-inbound-call.php

**File Path:** `admin/call-center/api/twilio-inbound-call.php`

### Overview
Twilio webhook entry point (239 lines) for inbound calls. Routes callers to donor menu (if recognized) or general menu (if new caller). Supports custom IVR recordings or TTS fallback.

### Critical Issues
1. **XSS in TwiML Output**: Line 67 outputs donor name with `htmlspecialchars()` which is good, but this is still a potential vector if the TTS engine or Twilio interprets special characters unexpectedly.
2. **No Webhook Signature Validation**: The endpoint does not verify Twilio webhook signature, allowing potential spoofed requests.

### Enhancements
1. **Donor Recognition**: Lines 37-40 look up caller by normalized phone number.
2. **Call Logging**: Lines 42-43, 187-231 log all inbound calls to database.
3. **IVR Service Integration**: Lines 26-30 use IVRRecordingService for custom recordings.
4. **Dynamic Table Creation**: Lines 192-217 create `twilio_inbound_calls` table if missing.
5. **Prepared Statements**: Lines 173-184, 224-227 use prepared statements.
6. **Phone Normalization**: Lines 161-169 normalize UK phone formats.

---

## admin/call-center/api/twilio-ivr-general-menu.php

**File Path:** `admin/call-center/api/twilio-ivr-general-menu.php`

### Overview
Twilio IVR handler (496 lines) for non-donor/new caller menu. Provides options for church information, SMS links, donation details, and admin contact.

### Critical Issues
1. **No Webhook Signature Validation**: The endpoint does not verify Twilio webhook signature, allowing potential spoofed requests.
2. **Hardcoded Bank Details**: Lines 198-201 hardcode bank details in source code. While not a security vulnerability, this information should be in configuration.
3. **Hardcoded Admin Contact**: Lines 270-271 hardcode admin name and phone number in source code.

### Enhancements
1. **Robust Error Handling**: Lines 17-21 set custom error handler to return valid TwiML on any error.
2. **Multi-Source Caller Detection**: Lines 36-43 try multiple sources for caller phone number.
3. **SMS Integration**: Lines 143-189, 334-408 send informational SMS via SMSHelper.
4. **Call Record Updates**: Lines 454-467, 470-488 update call selection and SMS sent status.
5. **Prepared Statements**: Lines 459, 478 use prepared statements for updates.
6. **Phone Normalization**: Lines 414-430 normalize UK phone formats for SMS.
7. **Column Existence Check**: Lines 476-477 check if column exists before update.

---

## admin/call-center/api/twilio-ivr-payment-method.php

**File Path:** `admin/call-center/api/twilio-ivr-payment-method.php`

### Overview
Twilio IVR handler (333 lines) for payment method selection. Provides bank transfer details, handles cash payment requests with admin notification via WhatsApp.

### Critical Issues
1. **No Webhook Signature Validation**: The endpoint does not verify Twilio webhook signature, allowing potential spoofed requests.
2. **Hardcoded Bank Details**: Lines 79-83 hardcode bank details in source code. Should be in configuration.
3. **Hardcoded Admin Phone**: Line 160 hardcodes admin phone number for notifications.

### Enhancements
1. **Prepared Statements**: Lines 279-284, 306-308, 318-320 use prepared statements.
2. **WhatsApp Integration**: Lines 196-233, 238-270 send WhatsApp messages via UltraMsgService.
3. **Call Record Updates**: Lines 303-312, 315-325 track payment method selection and requests.
4. **Donor Context**: Lines 275-285 retrieve donor info for personalized messaging.

---

## admin/call-center/api/twilio-recording-callback.php

**File Path:** `admin/call-center/api/twilio-recording-callback.php`

### Overview
Twilio webhook (153 lines) for call recording completion notifications. Saves recording URL to database for later playback.

### Critical Issues
1. **No Webhook Signature Validation**: The endpoint does not verify Twilio webhook signature, allowing potential spoofed requests to inject fake recording URLs.

### Enhancements
1. **Comprehensive Logging**: Lines 46-65 log all webhook data to `twilio_webhook_logs` table.
2. **Fallback Update Logic**: Lines 83-96 attempt secondary update if primary fails.
3. **Prepared Statements**: Lines 54-60, 69-79, 103-111, 121-128 use prepared statements.
4. **Table Existence Checks**: Lines 47-48, 101-102 check tables exist before operations.
5. **Recording URL Handling**: Lines 38-43 append `.mp3` extension for direct playback.
6. **Webhook Processing Tracking**: Lines 120-132 mark webhooks as processed.

---

## admin/call-center/api/twilio-support-notification.php

**File Path:** `admin/call-center/api/twilio-support-notification.php`

### Overview
Small TwiML endpoint (39 lines) that generates voice notification for support requests when WhatsApp notification fails.

### Critical Issues
1. **No Authentication or Validation**: This endpoint is publicly accessible and generates TwiML for any request_id and donor_name passed in URL parameters.
2. **Potential Abuse**: An attacker could trigger automated calls with arbitrary names by manipulating URL parameters.

### Enhancements
1. **Output Escaping**: Line 13 uses `htmlspecialchars()` for donor name.
2. **Type Casting**: Line 12 casts request_id to int.

---

## admin/call-center/api/twilio-webhook-answer.php

**File Path:** `admin/call-center/api/twilio-webhook-answer.php`

### Overview
Twilio webhook (74 lines) called when an agent answers a call. Returns TwiML to connect agent to donor with call recording.

### Critical Issues
1. **No Webhook Signature Validation**: The endpoint does not verify Twilio webhook signature, allowing potential spoofed requests.
2. **Caller ID Spoofing**: Line 58 sets `callerId` to the donor's phone number, which could be considered caller ID spoofing and may violate telecom regulations in some jurisdictions.

### Enhancements
1. **Phone Normalization**: Lines 22-35 normalize UK phone numbers to E.164 format.
2. **Output Escaping**: Lines 50, 58, 61, 63, 64 use `htmlspecialchars()` for TwiML output.
3. **Recording Configuration**: Lines 60-62 configure call recording with status callback.
4. **Status Callbacks**: Lines 42, 63 configure status callbacks for call tracking.

---

## admin/call-center/api/test-ivr-recording.php

**File Path:** `admin/call-center/api/test-ivr-recording.php`

### Overview
Diagnostic tool (103 lines) for testing IVR recording accessibility and format compatibility. Checks file existence, MIME type, and Twilio compatibility.

### Critical Issues
1. **Missing Authentication**: Lines 10-11 include auth but do not call `require_login()` or `require_admin()`, allowing unauthenticated access to file system information.

### Enhancements
1. **Prepared Statement**: Lines 19-22 use prepared statement for recording lookup.
2. **Comprehensive Diagnostics**: Lines 41-66 check file existence, readability, and MIME type.
3. **Twilio Compatibility Check**: Lines 56-62 validate audio format for Twilio playback.
4. **TwiML Preview**: Lines 77-84 show what TwiML would be generated.
5. **Recommendations**: Lines 86-93 provide actionable recommendations.

---

# Webhooks Section

## webhooks/test.php

**File Path:** `webhooks/test.php`

### Overview
Webhook test and debugging page (163 lines) for UltraMsg WhatsApp integration. Displays webhook URL, status checks, logs, and allows sending test messages.

### Critical Issues
1. **No Authentication**: This page is publicly accessible and exposes internal system information including conversation counts, message logs, and webhook payloads.
2. **Log Clearing Without CSRF**: Line 114-116 allow clearing log files via POST without CSRF protection.
3. **Debug Information Exposure**: Lines 85-100 expose recent webhook payloads which may contain sensitive data.

### Enhancements
1. **Status Checks**: Lines 43-77 check logs directory, writeability, and database tables.
2. **Test Webhook Simulation**: Lines 131-155 allow sending test webhooks for debugging.
3. **Good Output Escaping**: Lines 76, 99, 111, 153 use `htmlspecialchars()` for output.

---

## webhooks/ultramsg.php

**File Path:** `webhooks/ultramsg.php`

### Overview
UltraMsg WhatsApp webhook endpoint (563 lines) handling all incoming WhatsApp events: messages, ACK, reactions, and media. Full-featured conversation management.

### Critical Issues
1. **SQL Injection in Conversation Update**: Line 120 uses `db->real_escape_string()` in a raw query for contact name update instead of prepared statement.
2. **No Webhook Signature Validation**: The endpoint does not verify webhook signature, allowing potential spoofed messages.
3. **Open CORS Policy**: Lines 17-20 set `Access-Control-Allow-Origin: *` allowing any origin to POST to this endpoint.

### Enhancements
1. **Comprehensive Event Handling**: Lines 285-315 route events to appropriate handlers.
2. **Phone Normalization**: Lines 50-70 normalize various UK phone formats.
3. **Donor Lookup**: Lines 75-102 search donors using multiple phone format variants.
4. **Prepared Statements**: Most database operations (lines 112, 131, 148, 431, 471, 497, 509) use prepared statements.
5. **Message Type Mapping**: Lines 523-542 map UltraMsg types to internal types.
6. **Webhook Logging**: Lines 204-218 log all webhook payloads for debugging.
7. **Multi-Source Data Collection**: Lines 236-266 collect data from JSON body, GET, POST, and URL-encoded body.

---

# Services Section

## services/MessagingHelper.php

**File Path:** `services/MessagingHelper.php`

### Overview
Unified messaging helper class (1048 lines) supporting both SMS and WhatsApp with intelligent channel selection, fallback, and template management.

### Critical Issues
1. **SQL Injection in getDonorMessageHistory**: Lines 874-875, 905-906 use string interpolation in SQL queries with `$this->db->real_escape_string()` instead of prepared statements. This is less safe than parameterized queries.
2. **SQL Injection in getDonorMessageStats**: Lines 969-979, 1000-1007 use string interpolation with `$donorId` (int cast) and `$escapedPhone` in raw SQL queries.

### Enhancements
1. **Channel Auto-Selection**: Lines 264-299 intelligently select best channel based on donor preferences and availability.
2. **WhatsApp Number Caching**: Lines 304-402 cache WhatsApp availability checks for 24 hours.
3. **Fallback Logic**: Lines 468-471, 485, 502-504 automatically fall back to SMS if WhatsApp fails.
4. **Dual-Channel Sending**: Lines 511-576 support sending via both channels simultaneously.
5. **Prepared Statements for Core Operations**: Lines 345-361, 387-398, 584-596 use prepared statements for main operations.
6. **Comprehensive Message Logging**: Lines 655-798 log detailed message data with 37 parameters.
7. **Phone Normalization**: Lines 613-636 normalize UK phone formats.

---

## services/SMSHelper.php

**File Path:** `services/SMSHelper.php`

### Overview
Modular SMS service helper (643 lines) providing template-based SMS sending, direct messaging, queueing, blacklist checking, quiet hours management, and daily limit enforcement. Integrates with VoodooSMSService.

### Critical Issues
1. **SQL Injection in checkDailyLimit**: Line 520 uses string interpolation for `$today` (date variable) in the SQL query: `WHERE DATE(sent_at) = '$today'`. While `$today` is generated by `date()` and not user-controlled, this pattern is inconsistent with prepared statements used elsewhere.

### Enhancements
1. **Prepared Statements**: Lines 232-246, 333-341, 401-408, 473-477 correctly use prepared statements for most database operations.
2. **Quiet Hours Enforcement**: Lines 489-506 implement proper quiet hours logic with overnight span handling.
3. **Blacklist Checking**: Lines 465-484 verify phone numbers against `sms_blacklist` table.
4. **Daily Limit Tracking**: Lines 511-533 enforce configurable daily SMS limits.
5. **Template Localization**: Lines 449-459 support multi-language templates (en, am, ti).
6. **Opt-in Validation**: Lines 426-444 respect donor SMS opt-in preferences.
7. **Provider Integration**: Lines 61-63 initialize VoodooSMSService from database settings.
8. **Comprehensive Logging**: Lines 538-556 log all SMS activity to file.

---

## services/UltraMsgService.php

**File Path:** `services/UltraMsgService.php`

### Overview
WhatsApp messaging service (940 lines) providing text, image, document, audio, video, and voice message sending via UltraMsg API. Includes connection testing, QR code retrieval, and message statistics.

### Critical Issues
1. **SQL Injection in updateProviderStats**: Lines 756-757, 759-764 use string interpolation for `$this->providerId` in UPDATE queries: `WHERE id = {$this->providerId}`. Although `$providerId` is set internally as `(int)$provider['id']`, consistent use of prepared statements is recommended.

### Enhancements
1. **Prepared Statements**: Lines 682-704, 715-736 use prepared statements for logging WhatsApp messages.
2. **Phone Number Normalization**: Lines 494-522 implement comprehensive UK phone format normalization.
3. **API Response Parsing**: Lines 582-644 robustly parse various API response formats including string/boolean 'sent' values.
4. **Connection Testing**: Lines 419-445 provide credential verification via account status endpoint.
5. **Multi-Media Support**: Lines 160-335 support sending images, documents, audio, video, and voice notes.
6. **WhatsApp Number Checking**: Lines 453-486 verify if phone numbers have WhatsApp accounts.
7. **Error Logging**: Lines 564, 569, 605, 739 log API errors for debugging.
8. **Template Processing**: Lines 928-938 static method for variable replacement in messages.

---

## services/VoodooSMSService.php

**File Path:** `services/VoodooSMSService.php`

### Overview
SMS messaging service (536 lines) for VoodooSMS API integration. Provides single and batch SMS sending, balance checking, credit calculation (GSM-7 vs Unicode segments), and comprehensive logging.

### Critical Issues
1. **SQL Injection in logSMS (cost query)**: Line 451 uses string interpolation: `WHERE id = {$this->providerId}`. While `$providerId` is internally set as integer, prepared statements are preferred.
2. **SQL Injection in updateProviderStats**: Lines 497-503, 505-510 use string interpolation for `$this->providerId` in UPDATE queries.

### Enhancements
1. **Prepared Statements**: Lines 457-483 use prepared statements for SMS logging.
2. **Credit Calculation**: Lines 306-322 accurately calculate SMS segments for GSM-7 (160/153 chars) and Unicode (70/67 chars).
3. **Phone Normalization**: Lines 270-298 handle various UK phone formats.
4. **Message Length Validation**: Lines 116-123 enforce 918 character limit (6 segments max).
5. **Scheduling Support**: Lines 139-141 support scheduled SMS delivery.
6. **Batch Sending**: Lines 181-198 support sending to multiple recipients with rate limiting (100ms delay).
7. **Balance Checking**: Lines 205-238 retrieve account credit balance for monitoring.
8. **Template Processing**: Lines 524-534 static method for variable substitution.

---

## services/TwilioService.php

**File Path:** `services/TwilioService.php`

### Overview
Twilio Voice service (620 lines) for click-to-call functionality. Supports agent-to-donor call initiation, call status tracking, recording retrieval, hangup operations, and notification calls with TTS.

### Critical Issues
1. **Hardcoded Base URL**: Line 164 hardcodes `$baseUrl = 'https://donate.abuneteklehaymanot.org'`. This reduces portability and could cause issues if the domain changes or in different environments.

### Enhancements
1. **Prepared Statements**: Lines 522-538 use prepared statements for call logging with upsert.
2. **Phone Normalization**: Lines 394-420 normalize UK phone numbers to E.164 format.
3. **Two-Leg Call Flow**: Lines 149-234 implement proper agent-first call flow with TwiML callbacks.
4. **Recording Support**: Lines 182-200 conditionally enable call recording based on settings.
5. **Status Callbacks**: Lines 170-176 configure multiple status callback events (initiated, ringing, answered, completed).
6. **Session Updates**: Lines 585-618 update call center sessions with Twilio call data.
7. **Connection Testing**: Lines 92-133 verify Twilio credentials via account API.
8. **Recording Retrieval**: Lines 263-293 fetch call recording URLs from Twilio API.
9. **Graceful Error Handling**: Lines 225-233, 378-385 return structured error responses.

---

## services/IVRRecordingService.php

**File Path:** `services/IVRRecordingService.php`

### Overview
IVR recording service (159 lines) that manages voice recordings for Twilio IVR flows. Supports custom recorded audio or TTS fallback with dynamic text replacements.

### Critical Issues
1. **SQL Injection in incrementPlayCount**: Line 127 uses `$this->db->real_escape_string($key)` in a string-concatenated query instead of prepared statements: `WHERE recording_key = '...'`. While `real_escape_string` provides protection, prepared statements are preferred for consistency.

### Enhancements
1. **Recording Caching**: Lines 25-42 load all active recordings into memory cache for efficient lookups.
2. **Flexible TwiML Generation**: Lines 53-77 generate `<Play>` for recordings or `<Say>` for TTS with proper escaping.
3. **Dynamic Text Replacements**: Lines 68-70 support `{placeholder}` substitution for personalized messages.
4. **Play Count Analytics**: Lines 121-132 track recording usage statistics.
5. **XSS Protection in TwiML**: Lines 60, 76, 140 use `htmlspecialchars()` for all output in generated TwiML.
6. **Helper Methods**: Lines 137-149 provide convenient `say()` and `pause()` TwiML generators.

---

## services/TwilioErrorCodes.php

**File Path:** `services/TwilioErrorCodes.php`

### Overview
Static utility class (272 lines) mapping Twilio error codes to human-readable messages, categories, and recommended actions. Includes call progress errors, SIP errors, and call status translations.

### Critical Issues
- **NONE** - This is a purely data mapping class with no database operations or user input handling.

### Enhancements
1. **Comprehensive Error Mapping**: Lines 29-185 cover call progress (30xxx), call execution (31xxx), SIP (32xxx), address (33xxx), HTTP (11xxx), and voice (13xxx) error categories.
2. **Retryable Error Detection**: Lines 205-220 identify temporary/network errors suitable for automatic retry.
3. **Bad Number Detection**: Lines 228-242 identify errors indicating invalid phone numbers.
4. **Recommended Actions**: Lines 250-270 provide actionable guidance ('retry', 'update_number', 'skip', 'escalate').
5. **SIP Status Code Support**: Lines 143-162 handle common SIP codes (486 busy, 487 canceled, 603 declined, 480 unavailable).
6. **Call Status Translation**: Lines 165-184 translate Twilio CallStatus strings to user-friendly messages.

---

# Cron Section

## cron/process-sms-queue.php

**File Path:** `cron/process-sms-queue.php`

### Overview
Cron script (234 lines) for processing pending SMS messages in the queue. Implements quiet hours, daily limits, blacklist checking, opt-out validation, and retry logic with configurable batch sizes.

### Critical Issues
1. **SQL Injection in Queue Updates**: Lines 166, 177, 184 use string interpolation for `$queueId` in UPDATE queries: `WHERE id = $queueId`. Although `$queueId` is cast to `(int)` on line 158, direct concatenation is less safe than prepared statements.
2. **SQL Injection in Daily Limit Check**: Line 107 uses string interpolation: `WHERE DATE(sent_at) = '$today'`. While `$today` is generated by `date()`, prepared statements are preferred.
3. **Hardcoded Cron Key**: Line 21 contains a placeholder cron key `'your-secure-cron-key-here'` that must be changed for production.

### Enhancements
1. **CLI/Web Access Control**: Lines 15-25 properly restrict access to CLI or validated cron key.
2. **Prepared Statements**: Lines 121-132, 173-176, 197-199, 215-217 use prepared statements for main operations.
3. **Quiet Hours Logic**: Lines 83-102 handle overnight quiet hours correctly.
4. **Runtime Limiting**: Lines 153-156 prevent long-running jobs with MAX_RUNTIME.
5. **Rate Limiting**: Line 224 implements 200ms delay between messages.
6. **Retry Logic**: Lines 204-221 implement proper retry counting with max attempts.
7. **Comprehensive Logging**: Lines 36-48 log to file and console.

---

## cron/schedule-payment-reminders.php

**File Path:** `cron/schedule-payment-reminders.php`

### Overview
Cron script (312 lines) for scheduling payment reminder SMS. Supports 3-day advance reminders, due-day reminders, and 7-day overdue reminders. Implements template localization and deduplication.

### Critical Issues
1. **SQL Injection in Schedule Updates**: Lines 125, 172, 217 use integer casting in string concatenation: `WHERE id = " . (int)$row['schedule_id']`. While casting provides protection, prepared statements are preferred.
2. **SQL Injection in Table Check**: Lines 50-54 use string interpolation: `SHOW TABLES LIKE '$table'`. Table names from a hardcoded array, but pattern is risky.
3. **SQL Injection in Date Queries**: Lines 98-118, 146-166, 191-211 use string interpolation for date variables like `$reminder_date`, `$today`, `$overdue_date`.
4. **Hardcoded Cron Key**: Line 21 contains a placeholder cron key that must be changed for production.

### Enhancements
1. **CLI/Web Access Control**: Lines 15-25 properly restrict access to CLI or validated cron key.
2. **Prepared Statements**: Lines 75-78, 251-254, 262-268, 296-302 use prepared statements for template and queue operations.
3. **Template Localization**: Lines 275-282 select appropriate message language based on donor preference.
4. **Duplicate Prevention**: Lines 260-272 check for existing queued messages to avoid spam.
5. **Blacklist Checking**: Lines 250-257 validate against blacklist before queueing.
6. **Priority Handling**: Line 294 assigns higher priority to overdue reminders.
7. **Comprehensive Logging**: Lines 31-41 log all operations.

---

# Shared Section

## shared/FinancialCalculator.php

**File Path:** `shared/FinancialCalculator.php`

### Overview
Centralized financial calculator class (300 lines) providing consistent financial metrics across the system. Handles instant payments, pledge payments, and combined totals with optional date filtering.

### Critical Issues
- **NONE** - All database queries use prepared statements or non-user-controlled data.

### Enhancements
1. **Prepared Statements**: Lines 45-52, 72-79, 96-103, 159-166, 195-226, 248-279 use prepared statements throughout.
2. **Date Filtering**: Lines 36-146 support optional date range filtering for reports.
3. **Donor Recalculation**: Lines 190-233, 242-286 provide separate methods for approve vs. undo operations.
4. **Status Preservation**: Lines 259-275 preserve 'overdue' and 'defaulted' statuses during undo.
5. **Tolerance Handling**: Lines 207-211, 260-264 use 0.01 tolerance for floating-point comparisons.
6. **Error Logging**: Lines 229-231, 283-285 log calculation failures.
7. **Backwards Compatibility**: Lines 295-297 maintain generic recalculate method.

---

## shared/IntelligentGridAllocator.php

**File Path:** `shared/IntelligentGridAllocator.php`

### Overview
Space-filling grid allocator (440 lines) implementing sequential allocation of 0.5x0.5m cells based on donation amounts. Supports allocation, deallocation, and statistics with transaction safety.

### Critical Issues
- **NONE** - All database queries use prepared statements.

### Enhancements
1. **Prepared Statements**: Lines 85-88, 106-119, 141-204, 219-267, 357-370, 401-417, 425-438 use prepared statements throughout.
2. **Transaction Safety**: Lines 49, 62, 300, 313 wrap operations in transactions with rollback on error.
3. **Row Locking**: Line 114 uses `FOR UPDATE` to prevent concurrent allocation conflicts.
4. **Dynamic Parameter Binding**: Lines 159-204, 225-268 handle variable numbers of cell IDs in UPDATE queries.
5. **Column Existence Check**: Lines 132-138 gracefully handle missing `allocation_batch_id` column.
6. **Detailed Error Logging**: Lines 73, 172-174, 237-239, 324 log allocation errors with context.
7. **Package-Based Calculation**: Lines 84-98 support both package-based and amount-based area calculation.

---

## shared/FloorGridAllocatorV2.php

**File Path:** `shared/FloorGridAllocatorV2.php`

### Overview
Version 2 floor grid allocator (438 lines) handling allocation based on donation packages (1m², 0.5m², 0.25m², custom). Implements smart cell ordering for natural fill patterns.

### Critical Issues
- **NONE** - All database queries use prepared statements.

### Enhancements
1. **Prepared Statements**: Lines 223-231, 318-324, 349-356, 385-390, 404-417 use prepared statements throughout.
2. **Transaction Safety**: Lines 36, 71, 81 wrap operations in transactions with rollback.
3. **Package-Based Allocation**: Lines 96-118 support specific package types and custom amounts.
4. **Smart Ordering**: Lines 245-305 implement custom ORDER BY clauses for natural cell fill patterns.
5. **Threshold-Based Custom**: Lines 124-138 calculate appropriate cell types based on donation amounts.
6. **Allocation Recording**: Lines 333-357 track allocations in `floor_area_allocations` table.
7. **Dynamic Parameter Binding**: Lines 367-388 handle optional rectangle filtering.
8. **Error Logging**: Lines 82 log allocation failures.

---

## shared/GridAllocationBatchTracker.php

**File Path:** `shared/GridAllocationBatchTracker.php`

### Overview
Batch tracking system (571 lines) for managing allocation batches, supporting pledge updates, batch approvals/rejections, and deallocation operations with comprehensive audit trail.

### Critical Issues
- **NONE** - All database queries use prepared statements with thorough parameter validation.

### Enhancements
1. **Prepared Statements**: Lines 45-179, 260-274, 293-302, 349-379, 398-408, 455-465, 510-520, 523-531, 558-566 use prepared statements throughout.
2. **Transaction Safety**: Lines 477, 491, 533, 544 wrap deallocation in transactions.
3. **Extensive Parameter Validation**: Lines 76-80, 117-155 validate required fields and parameter counts.
4. **Type String Verification**: Lines 118-123, 150-155 verify bind_param type string matches parameter count.
5. **Detailed Error Logging**: Lines 31-103, 182-228 log all critical operations with variable types.
6. **Null Handling**: Lines 127-134 properly convert nulls to empty strings for bind_param.
7. **Batch State Management**: Lines 242-275, 283-303, 474-548 handle approve, reject, and deallocate operations.
8. **Exception Handling**: Lines 157-213 wrap bind_param in try-catch blocks.

---

## shared/audit_helper.php

**File Path:** `shared/audit_helper.php`

### Overview
Centralized audit logging helper (120 lines) providing consistent activity logging for all database operations. Supports before/after state tracking and automatic user/IP detection.

### Critical Issues
- **NONE** - Uses prepared statements and proper input handling.

### Enhancements
1. **Prepared Statements**: Lines 63-84 use parameterized queries for audit log insertion.
2. **Automatic User Detection**: Lines 37-42 detect user ID from session.
3. **IP Address Handling**: Lines 45-56 properly convert IP to binary format with `inet_pton()`.
4. **JSON Encoding**: Lines 59-60 use `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` flags.
5. **Source Context Detection**: Lines 106-118 automatically determine action source from URL path.
6. **Error Logging**: Line 88 logs failures without exposing details.

---

## shared/csrf.php

**File Path:** `shared/csrf.php`

### Overview
CSRF protection helper (30 lines) providing token generation, HTML input rendering, and verification functions for form submissions.

### Critical Issues
- **NONE** - Uses cryptographically secure random bytes for token generation.

### Enhancements
1. **Secure Token Generation**: Lines 9-12 use `random_bytes(32)` for cryptographically secure tokens.
2. **HTML Escaping**: Line 16 properly escapes token in HTML output.
3. **Timing-Safe Comparison**: Lines 20, 29 use `===` for token comparison (though `hash_equals()` would be more secure against timing attacks).
4. **Session Initialization**: Lines 4-6 ensure session is started before token operations.
5. **Exit Control**: Lines 19-25 allow configurable behavior on verification failure.

---

## shared/auth.php

**File Path:** `shared/auth.php`

### Overview
Authentication helper (274 lines) providing session management, role-based access control, login/logout functions, and donor device validation for multi-role portals (admin, registrar, donor).

### Critical Issues
1. **SQL Injection in Last Login Update**: Line 141 uses string interpolation: `WHERE id = " . (int)$user['id']`. While integer casting provides protection, prepared statements are preferred.

### Enhancements
1. **Prepared Statements**: Lines 108-127, 227-237 use parameterized queries for login and device validation.
2. **Session Regeneration**: Line 156 regenerates session ID on login to prevent session fixation.
3. **Role-Based Access Control**: Lines 57-99 implement granular access control for admin vs. registrar roles.
4. **Secure Logout**: Lines 160-188 properly clear session, cookie, and destroy session on logout.
5. **Audit Logging**: Lines 143-154, 162-179 log login/logout events.
6. **Device Token Validation**: Lines 195-259 validate trusted device tokens for donors.
7. **Secure Cookie Handling**: Lines 264-273 set proper cookie attributes (httponly, samesite, secure).
8. **Phone Normalization**: Lines 105-128 handle various phone formats for login.

---

## shared/RateLimiter.php

**File Path:** `shared/RateLimiter.php`

### Overview
Rate limiting system (337 lines) protecting donation forms against spam, DDoS, and abuse with configurable per-IP, per-phone, and global limits. Includes CAPTCHA triggering and automatic blocking.

### Critical Issues
1. **SQL Injection in Interval Queries**: Lines 137, 189, 209, 231, 249 use string interpolation for interval values in `DATE_SUB()` clauses. While the interval values are hardcoded from a local array, this pattern is risky.
2. **SQL Injection in Table Name**: Lines 80, 95, 137, 189, 209, 231, 249, 278, 296, 303 use `$this->tableName` directly in queries. While it's set internally, this pattern is risky.

### Enhancements
1. **Prepared Statements**: Lines 79-87, 95-102, 134-141, 186-193, 205-212, 228-233, 246-252, 276-283 use parameterized queries for record operations.
2. **Multi-Level Limits**: Lines 13-30 implement configurable limits per minute/hour/day at IP, phone, and global levels.
3. **Automatic Blocking**: Lines 93-118 implement temporary blocking after excessive attempts.
4. **CAPTCHA Integration**: Lines 266-291 determine when to require CAPTCHA based on activity.
5. **Old Record Cleanup**: Lines 293-299 clean records older than 7 days.
6. **Auto Table Creation**: Lines 301-317 create rate_limits table if not exists.
7. **Retry Time Formatting**: Lines 322-335 provide user-friendly time display.

---

## shared/BotDetector.php

**File Path:** `shared/BotDetector.php`

### Overview
Bot detection system (417 lines) for donation forms using honeypots, timing analysis, user agent checking, browser feature verification, and interaction pattern analysis.

### Critical Issues
- **NONE** - This is a detection utility with no database operations.

### Enhancements
1. **Multi-Signal Analysis**: Lines 19-67 combine multiple detection signals for confidence scoring.
2. **Honeypot Fields**: Lines 72-96, 320-329 implement invisible trap fields for bots.
3. **Timing Analysis**: Lines 101-140 detect suspiciously fast form submissions.
4. **User Agent Detection**: Lines 145-179 identify common bot signatures in user agents.
5. **Browser Feature Checks**: Lines 184-215 verify JavaScript execution and browser data.
6. **Interaction Pattern Analysis**: Lines 220-258 detect unnatural form interaction patterns.
7. **Robotic Timing Detection**: Lines 263-285 identify precise/identical interval patterns.
8. **Client-Side Detection Script**: Lines 334-414 generate JavaScript for comprehensive bot detection.

---

# API Section

## api/totals.php

**File Path:** `api/totals.php`

### Overview
Public API endpoint (37 lines) returning fundraising totals using the centralized `FinancialCalculator`. Returns paid total, outstanding pledges, grand total, and progress percentage.

### Critical Issues
- **NONE** - Uses `FinancialCalculator` which uses prepared statements internally.

### Enhancements
1. **Centralized Calculator**: Lines 19-21 use `FinancialCalculator` for consistent data.
2. **Error Suppression**: Lines 3-4 suppress error display in production.
3. **Generic Error Response**: Lines 34-36 return generic error message without exposing details.
4. **Progress Calculation**: Lines 23-24 calculate progress percentage with overflow protection.
5. **JSON Response**: Lines 26-33 return well-structured JSON response.

---

## api/calculate_sqm.php

**File Path:** `api/calculate_sqm.php`

### Overview
API endpoint (95 lines) calculating square meter allocation for a given donation amount by matching to the closest donation package.

### Critical Issues
1. **Error Message Exposure**: Line 92 exposes exception details in production response: `'details' => $e->getMessage()`. Should be removed or logged internally.

### Enhancements
1. **Input Validation**: Lines 10-19 validate amount parameter is positive.
2. **Error Suppression**: Lines 4-5 suppress error display in production.
3. **Dynamic Package Matching**: Lines 32-42 find best matching package by price.
4. **Fraction Display**: Lines 65-73 display common fractions (½, ¼, ¾) properly.
5. **JSON Response**: Lines 76-85 return structured response with calculation details.

---

## api/check_donor.php

**File Path:** `api/check_donor.php`

### Overview
API endpoint (138 lines) checking donor existence and history by phone number. Returns pledge counts, payment counts, and recent donation history.

### Critical Issues
- **NONE** - All database queries use prepared statements.

### Enhancements
1. **Prepared Statements**: Lines 61-73, 76-89, 92-105, 115-127 use parameterized queries throughout.
2. **Phone Normalization**: Lines 9-15 normalize UK phone numbers.
3. **Request Method Support**: Lines 18-25 handle both GET and POST requests.
4. **UK Mobile Validation**: Lines 49-51 validate UK mobile format (07xxx).
5. **Combined Recent Donations**: Lines 108-127 use UNION ALL to combine pledges and payments.
6. **Generic Error Response**: Lines 132-134 return generic error without exposing details.

---

## api/donor_history.php

**File Path:** `api/donor_history.php`

### Overview
API endpoint (87 lines) returning complete donation history (pledges and payments) for a phone number.

### Critical Issues
- **NONE** - All database queries use prepared statements.

### Enhancements
1. **Prepared Statements**: Lines 64-78 use parameterized query with UNION ALL.
2. **Phone Normalization**: Lines 9-15 normalize UK phone numbers.
3. **Request Method Support**: Lines 18-25 handle both GET and POST requests.
4. **Typed Response**: Lines 70-76 cast values to appropriate types.
5. **Generic Error Response**: Lines 83-85 return generic error without exposing details.

---

## api/grid_status.php

**File Path:** `api/grid_status.php`

### Overview
API endpoint (103 lines) returning floor grid cell status for real-time projector visualization. Supports detailed and summary formats.

### Critical Issues
1. **Error Message Exposure**: Line 97 exposes exception message in error response: `'error' => 'An internal server error occurred: ' . $e->getMessage()`. Should be generic in production.

### Enhancements
1. **CORS Headers**: Lines 16-18 allow cross-origin access for projector.
2. **Cache Control**: Lines 12-14 prevent caching for real-time data.
3. **IntelligentGridAllocator**: Lines 25, 38, 57, 74 use centralized allocator.
4. **Format Parameter**: Lines 28, 36-91 support 'summary' and 'detailed' response formats.
5. **Grouped Data**: Lines 59-71 group cells by rectangle for frontend display.
6. **Combined Response**: Lines 79-90 return both grid data and summary statistics.

---

## api/recent.php

**File Path:** `api/recent.php`

### Overview
API endpoint (85 lines) returning recent approved donations for projector display. Combines pledges, payments, and pledge payments with anonymized donor names.

### Critical Issues
- **NONE** - Uses direct queries on read-only aggregated data with no user input.

### Enhancements
1. **Error Suppression**: Lines 3-4 suppress error display in production.
2. **Dynamic Table Check**: Line 20 checks for `pledge_payments` table existence.
3. **UNION ALL Query**: Lines 23-56 combine pledges, payments, and pledge payments.
4. **Privacy Protection**: Lines 67-68 anonymize all names to "Kind Donor".
5. **Error Logging**: Line 82 logs errors internally without exposing details.
6. **Graceful Fallback**: Lines 83-84 return empty array on error.

---

## api/member_generate_code.php

**File Path:** `api/member_generate_code.php`

### Overview
API endpoint (47 lines) generating new 6-digit passcodes for registrar users. Requires admin authentication and CSRF validation.

### Critical Issues
- **NONE** - Uses prepared statements and proper authentication.

### Enhancements
1. **Prepared Statements**: Lines 24-28, 36-39 use parameterized queries.
2. **CSRF Protection**: Lines 14-16 validate CSRF token.
3. **Admin Authentication**: Line 10 requires admin role.
4. **Secure Code Generation**: Lines 34-35 use `random_int()` and proper password hashing.
5. **Role Validation**: Lines 29-31 verify user is a registrar before generating code.
6. **Request Method Check**: Lines 11-13 ensure POST method only.

---

## api/member_stats.php

**File Path:** `api/member_stats.php`

### Overview
API endpoint (195 lines) returning comprehensive registration statistics for a specific user (registrar). Includes pledge stats, payment stats, and recent activity.

### Critical Issues
- **NONE** - Uses prepared statements throughout and requires admin authentication.

### Enhancements
1. **Prepared Statements**: Lines 27-30, 50-53, 67-70, 84-87, 101-104, 128-131 use parameterized queries.
2. **Admin Authentication**: Line 14 requires admin role.
3. **Input Validation**: Lines 17-23 validate user ID parameter.
4. **Combined Statistics**: Lines 107-113, 134-138 aggregate data from multiple sources.
5. **Performance Metrics**: Lines 154-155 calculate approval and rejection rates.
6. **Recent Activity UNION**: Lines 116-131 combine pledges and payments for activity log.
7. **Generic Error Response**: Lines 188-193 return generic error without exposing details.

---

## api/footer.php

**File Path:** `api/footer.php`

### Overview
API endpoint (40 lines) returning projector footer message and visibility settings.

### Critical Issues
- **NONE** - Simple read-only query with no user input.

### Enhancements
1. **Error Suppression**: Lines 6-7 suppress error display in production.
2. **Default Fallback**: Lines 24-28 return default message if table is empty.
3. **Graceful Error Handling**: Lines 31-38 return default values on error.

---

# Donor Section

## donor/index.php

**File Path:** `donor/index.php`

### Overview
Donor portal dashboard page (536 lines) displaying pledge progress, payment statistics, payment plan info, quick actions, and recent payments. Requires donor login and validates trusted devices.

### Critical Issues
1. **SQL Injection in Last/Recent Payment Queries**: Lines 121, 127, 130, 156, 160, 169 use string interpolation within SQL queries: `pp.donor_id = {$donor['id']}` and `p.donor_phone = '{$db->real_escape_string($donor['phone'])}'`. While donor ID comes from session, and phone is escaped, prepared statements are preferred for consistency.

### Enhancements
1. **Session Refresh**: Lines 37-79 refresh donor data from database on each page load.
2. **Dynamic Column Detection**: Lines 40-47, 53-58 check for email/email_opt_in columns before querying.
3. **Table Existence Check**: Lines 110-111, 149-150 verify tables exist before querying.
4. **UNION ALL Queries**: Lines 113-136, 152-177 combine data from multiple payment tables.
5. **Progress Calculation**: Lines 184-187 compute pledge completion percentage.
6. **Achievement Badges**: Lines 189-206 implement a badge system for donor engagement.
7. **Error Logging**: Lines 78, 141, 180 log errors internally without exposing details.

---

## donor/login.php

**File Path:** `donor/login.php`

### Overview
Donor portal login page (839 lines) implementing SMS OTP authentication with trusted device support. Features a two-step flow: phone number entry → OTP verification with optional device trust.

### Critical Issues
- **NONE** - Uses prepared statements throughout, proper CSRF protection, and secure OTP handling.

### Enhancements
1. **Prepared Statements**: All database operations use parameterized queries.
2. **CSRF Protection**: Line 380 calls `verify_csrf()` on POST.
3. **OTP Rate Limiting**: Lines 124-137 enforce cooldown between OTP requests.
4. **Max OTP Attempts**: Lines 206-214 limit failed verification attempts to 5.
5. **Secure OTP Generation**: Lines 139-140 use `random_int()` for 6-digit codes.
6. **Trusted Device Tokens**: Lines 240-272 use cryptographically secure 64-char hex tokens.
7. **Secure Cookie Settings**: Lines 262-269 set httponly, samesite, and secure flags.
8. **Session Regeneration**: Lines 430, 488 call `session_regenerate_id(true)` after login.
9. **Phone Normalization**: Lines 76-79, 118-122, 183-186, 278-281, 387-391 consistently normalize UK phone numbers.
10. **Audit Logging**: Lines 428, 486 log login events with method details.

---

## donor/make-payment.php

**File Path:** `donor/make-payment.php`

### Overview
Donor payment wizard page (1974 lines) providing a 5-step payment process: Plan Status → Amount Selection → Payment Method → Details → Confirmation. Supports bank transfer and cash payments with representative assignment.

### Critical Issues
1. **Error Message Exposure**: Line 252 exposes full exception message: `$error_message = "System error: " . $e->getMessage()`. Should be generic in production.

### Enhancements
1. **CSRF Protection**: Line 131 calls `verify_csrf()` on POST.
2. **Prepared Statements**: Lines 40-49, 68-72, 95-105, 185-191, 196-197, 242-244, 267-278, 286-298, 307-319, 325-335 use parameterized queries.
3. **Transaction Management**: Lines 172, 227 use database transactions.
4. **File Upload Validation**: Lines 150-168 validate MIME type and file size for payment proofs.
5. **Dynamic Column Detection**: Lines 93, 182 check for optional columns.
6. **Audit Logging**: Lines 207-225 log payment submission details.
7. **Payment Plan Linking**: Lines 176-178, 184-192 link payments to active plans when applicable.
8. **Input Validation**: Lines 141-146 validate amount, balance, and payment method.
9. **Session Refresh**: Lines 241-248 update session with fresh donor data.
10. **Status Display**: Lines 890-1030, 1032-1139 show payment status and history.

---

## donor/profile.php

**File Path:** `donor/profile.php`

### Overview
Donor profile page (697 lines) allowing donors to update personal information (name, phone, email, baptism name) and preferences (language, payment method, payment day, opt-ins).

### Critical Issues
- **NONE** - Uses prepared statements, CSRF protection, and proper validation.

### Enhancements
1. **CSRF Protection**: Lines 84, 215 call `verify_csrf()` on form submissions.
2. **Prepared Statements**: All database operations use parameterized queries.
3. **Transaction Management**: Lines 115, 186, 233, 282 use transactions for updates.
4. **Dynamic Column Detection**: Lines 118-127, 235-238, 314-326 check for optional columns.
5. **Input Validation**: Lines 92-109, 223-229 validate required fields and formats.
6. **Phone Normalization**: Lines 100-103 normalize UK phone numbers.
7. **Audit Logging**: Lines 175-184, 271-280 log profile and preference updates.
8. **Session Refresh**: Lines 189-199, 285-295 update session after successful updates.
9. **Client-Side Phone Formatting**: Lines 646-683 provide JS phone formatting for UX.

---

## donor/contact.php

**File Path:** `donor/contact.php`

### Overview
Donor support request page (680 lines) allowing donors to submit support requests and track conversations. Implements multi-channel admin notifications (WhatsApp → Phone Call → SMS fallback).

### Critical Issues
1. **SQL Injection in Status Update**: Line 227 uses raw query with interpolated variable: `$db->query("UPDATE donor_support_requests SET status = 'open' WHERE id = $request_id")`. Should use prepared statement.
2. **Hardcoded Admin Phone**: Line 16 defines `ADMIN_NOTIFICATION_PHONE` directly in code. Should be in configuration.

### Enhancements
1. **CSRF Protection**: Line 50 calls `verify_csrf()` on POST.
2. **Prepared Statements**: Most queries use parameterized statements.
3. **Input Validation**: Lines 58-62 validate required fields and lengths.
4. **Multi-Channel Notifications**: Lines 97-191 implement robust fallback (WhatsApp → Call → SMS).
5. **Audit Logging**: Lines 73-78 log support request creation.
6. **Access Control**: Lines 208-213 verify request belongs to donor before allowing replies.
7. **Status Management**: Lines 225-228 reopen resolved requests when donor replies.
8. **Table Existence Check**: Lines 43-46 verify support tables exist.
9. **Error Logging**: Lines 116, 124, 144, 153, 173, 181-191, 196 log notification attempts and failures.

---

## donor/logout.php

**File Path:** `donor/logout.php`

### Overview
Donor logout handler (70 lines) with optional trusted device revocation. Supports normal logout (keeps device trusted) and full logout (removes device trust).

### Critical Issues
- **NONE** - Simple logout handler with proper audit logging.

### Enhancements
1. **Forget Device Option**: Lines 12, 22-40 allow revoking device trust via `?forget=1` parameter.
2. **Database Token Deactivation**: Lines 26-29 mark device token as inactive in database.
3. **Cookie Clearing**: Lines 32-39 properly clear cookie with same parameters used to set it.
4. **Audit Logging**: Lines 42-52 log logout with forget_device flag.
5. **Session Cleanup**: Lines 58-65 clear donor session and destroy if empty.
6. **Secure Cookie Options**: Lines 32-38 use httponly, samesite, and secure flags.

---

## donor/payment-history.php

**File Path:** `donor/payment-history.php`

### Overview
Donor payment history page displaying all pledge payments and instant payments in a combined, sorted view.

### Critical Issues
- **NONE** - Uses prepared statements and proper authentication.

### Enhancements
1. **Combined Payment Sources**: Fetches from both `pledge_payments` and `payments` tables.
2. **Client-Side Sorting**: Uses PHP `usort` to combine and sort payments chronologically.
3. **Status Mapping**: Displays consistent status badges for different payment statuses.
4. **Modal Details**: Shows detailed payment information in modals.

---

## donor/payment-plan.php

**File Path:** `donor/payment-plan.php`

### Overview
Donor payment plan view showing active payment plan details, schedule, and progress.

### Critical Issues
- **NONE** - Read-only display with prepared statements.

### Enhancements
1. **Plan Progress Display**: Shows visual progress of payment plan completion.
2. **Schedule Visualization**: Displays upcoming and past payment dates.
3. **Dynamic Plan Loading**: Fetches plan details based on donor's active plan.

---

## donor/update-pledge.php

**File Path:** `donor/update-pledge.php`

### Overview
Page allowing donors to increase their pledge amount.

### Critical Issues
- **NONE** - Uses prepared statements and CSRF protection.

### Enhancements
1. **Amount Validation**: Ensures new pledge amount is greater than current.
2. **Audit Logging**: Records pledge increase requests.

---

## donor/includes/sidebar.php

**File Path:** `donor/includes/sidebar.php`

### Overview
Donor portal sidebar navigation component defining menu items and mobile toggle.

### Critical Issues
- **NONE** - Static navigation template with proper URL escaping.

### Enhancements
1. **Active State Detection**: Highlights current page in navigation.
2. **URL Helper Usage**: Uses `url_for()` for consistent URL generation.
3. **Mobile Responsive**: Includes mobile sidebar toggle functionality.

---

## donor/includes/topbar.php

**File Path:** `donor/includes/topbar.php`

### Overview
Donor portal topbar component with donor initials, page title, and user dropdown menu.

### Critical Issues
- **NONE** - Static template with proper output escaping.

### Enhancements
1. **Dynamic Page Title**: Displays context-aware page titles.
2. **User Initials**: Extracts and displays donor initials in avatar.
3. **Dropdown Menu**: Provides quick access to preferences, support, and logout.
4. **Mobile Toggle**: Includes hamburger menu for mobile navigation.

---

## donor/api/location-data.php

**File Path:** `donor/api/location-data.php`

### Overview
API endpoint for donor portal to fetch cities, churches, representatives, and assign representatives.

### Critical Issues
1. **Missing CSRF Protection on assign_rep**: The `assign_rep` POST action modifies donor data but doesn't validate CSRF token.

### Enhancements
1. **Prepared Statements**: Uses parameterized queries for all database operations.
2. **Input Validation**: Validates church_id and rep_id are positive integers.
3. **Ownership Verification**: Verifies representative belongs to specified church.
4. **Dynamic Column Check**: Checks for `representative_id` column existence.

---

# Registrar Section

## registrar/index.php

**File Path:** `registrar/index.php`

### Overview
Registrar portal main page (673 lines) providing a donation registration form. Allows registrars to register pledges or immediate payments with package selection, tombola codes, and duplicate detection.

### Critical Issues
- **NONE** - Uses prepared statements throughout, CSRF protection, and proper validation.

### Enhancements
1. **CSRF Protection**: Line 87 calls `verify_csrf()` on POST.
2. **Prepared Statements**: All database operations use parameterized queries.
3. **Transaction Management**: Lines 181, 356 wrap database operations in transactions.
4. **Role-Based Access**: Lines 9-16 verify user has registrar or admin role.
5. **Input Validation**: Lines 112-177 validate all form inputs including phone format, tombola code, and package selection.
6. **Phone Normalization**: Lines 120-126, 128-132 normalize UK phone numbers.
7. **Duplicate Detection**: Lines 197-211, 241-260 prevent duplicate registrations for same phone.
8. **Additional Donation Flag**: Lines 101, 197, 241, 280-348 allow creating additional donations for returning donors.
9. **Audit Logging**: Lines 223-227, 350-353 log all registration actions.
10. **UUID Idempotency**: Lines 100-104, 230-236 prevent duplicate form submissions.
11. **Table Existence Checks**: Lines 23-31, 48-71 verify required tables exist.

---

## registrar/login.php

**File Path:** `registrar/login.php`

### Overview
Registrar portal login page (141 lines) with phone/password authentication. Restricts access to registrar and admin roles only.

### Critical Issues
- **NONE** - Uses shared auth module with proper CSRF protection.

### Enhancements
1. **CSRF Protection**: Line 22 calls `verify_csrf()` on POST.
2. **Role Verification**: Lines 32-37 verify user has registrar or admin role after login.
3. **Forced Logout for Unauthorized Roles**: Line 33 logs out users who don't have appropriate roles.
4. **Already Logged In Check**: Lines 6-9 redirect logged-in users to index.
5. **Logout Message Display**: Lines 14-17 show success message after logout.
6. **No Index Meta**: Line 50 prevents search engine indexing.
7. **Link to Registration**: Lines 113-116 provide path for new registrar applications.

---

## registrar/register.php

**File Path:** `registrar/register.php`

### Overview
Registrar application page (334 lines) allowing users to apply for registrar access. Requires admin approval before account creation.

### Critical Issues
- **NONE** - Uses prepared statements, CSRF protection, and proper validation.

### Enhancements
1. **Strict Types**: Line 2 declares `strict_types=1` for type safety.
2. **CSRF Protection**: Line 12 calls `verify_csrf()` on POST.
3. **Prepared Statements**: Lines 31-38, 50-51 use parameterized queries.
4. **Input Validation**: Lines 19-27 validate name, email, and phone with proper checks.
5. **Email Validation**: Line 21 uses `FILTER_VALIDATE_EMAIL`.
6. **Duplicate Check**: Lines 31-47 check both `registrar_applications` and `users` tables.
7. **Contextual Error Messages**: Lines 43-46 provide different messages for existing users vs. pending applications.
8. **Pending Status**: Line 50 creates applications with 'pending' status for admin review.
9. **Success State Display**: Lines 83-137 show detailed next steps on successful submission.

---

## registrar/logout.php

**File Path:** `registrar/logout.php`

### Overview
Registrar logout handler that clears session and redirects to login.

### Critical Issues
- **NONE** - Simple logout using shared auth module.

### Enhancements
1. **Shared Auth Usage**: Uses `logout()` function from shared auth module.
2. **Redirect to Login**: Redirects to login.php with success parameter.

---

## registrar/my-registrations.php

**File Path:** `registrar/my-registrations.php`

### Overview
Page showing registrar's own pledge and payment registrations with filtering and search.

### Critical Issues
- **NONE** - Uses prepared statements with user ID filtering.

### Enhancements
1. **Role-Based Filtering**: Only shows registrations created by current user.
2. **Status Filtering**: Allows filtering by status (pending, approved, rejected).
3. **Search Functionality**: Allows searching by donor name or phone.
4. **Pagination**: Implements pagination for large datasets.

---

## registrar/statistics.php

**File Path:** `registrar/statistics.php`

### Overview
Registrar statistics page showing personal performance metrics.

### Critical Issues
- **NONE** - Read-only statistics with prepared statements.

### Enhancements
1. **Performance Metrics**: Shows total registrations, approval rates, amounts.
2. **Time-Based Stats**: Daily, weekly, monthly breakdowns.
3. **Role-Based Data**: Only shows current user's statistics.

---

## registrar/profile.php

**File Path:** `registrar/profile.php`

### Overview
Registrar profile page for viewing and updating personal information.

### Critical Issues
- **NONE** - Uses prepared statements and CSRF protection.

### Enhancements
1. **CSRF Protection**: Validates CSRF token on form submission.
2. **Password Change**: Allows password updates with proper hashing.
3. **Input Validation**: Validates email format and required fields.

---

## registrar/access-denied.php

**File Path:** `registrar/access-denied.php`

### Overview
Access denied page shown when users lack required permissions.

### Critical Issues
- **NONE** - Static error page with no user input.

### Enhancements
1. **Clear Messaging**: Explains why access was denied.
2. **Navigation Options**: Provides links to login and home pages.

---

## registrar/messages/index.php

**File Path:** `registrar/messages/index.php`

### Overview
Registrar messaging interface for communicating with admins and other registrars.

### Critical Issues
- **NONE** - Uses prepared statements, CSRF protection, and idempotency checks.

### Enhancements
1. **CSRF Protection**: Validates CSRF token on message submission.
2. **Prepared Statements**: All database operations use parameterized queries.
3. **Blocklist Checking**: Prevents messaging between blocked users.
4. **Idempotency**: Uses `client_uuid` to prevent duplicate messages.
5. **Real-Time Updates**: AJAX-based message loading.

---

# Public Section

## public/projector/index.php

**File Path:** `public/projector/index.php`

### Overview
Live fundraising display page (785 lines) showing real-time totals, progress bar, recent contributions, and celebration effects. Public-facing projector view with auto-refresh.

### Critical Issues
- **NONE** - Read-only display page with no user input handling.

### Enhancements
1. **Strict Types**: Line 2 declares `strict_types=1`.
2. **Resilient Loader**: Line 4 uses `resilient_db_loader.php` for graceful database handling.
3. **Default Settings**: Lines 7-13 provide safe defaults if database unavailable.
4. **No Index Meta**: Line 24 prevents search engine indexing.
5. **API-Based Updates**: Lines 545-576 fetch data from API endpoints, not direct database.
6. **XSS Prevention**: Line 16 uses `htmlspecialchars` for currency display.
7. **JSON Encoding**: Line 160 uses `json_encode` for JavaScript config.
8. **Fullscreen Support**: Lines 731-783 implement fullscreen toggle with keyboard support.
9. **Milestone Celebrations**: Lines 301-309, 616-633 trigger celebrations at progress milestones.
10. **Smart Auto-Scroll**: Lines 345-365 implement intelligent scroll behavior for contributions.

---

## public/donate/index.php

**File Path:** `public/donate/index.php`

### Overview
Public donation form (675 lines) allowing visitors to make pledges or immediate payments. Implements comprehensive security with rate limiting, bot detection, and input validation.

### Critical Issues
- **NONE** - Implements robust security measures including rate limiting, bot detection, CSRF, and prepared statements.

### Enhancements
1. **CSRF Protection**: Line 39 calls `verify_csrf()` on POST.
2. **Rate Limiting**: Lines 43-52, 146-167 implement IP and phone-based rate limiting.
3. **Bot Detection**: Lines 44-61, 517-518, 669-672 analyze submissions for bot patterns.
4. **Honeypot Fields**: Lines 516-518 add hidden fields to trap bots.
5. **Prepared Statements**: All database operations use parameterized queries.
6. **Transaction Management**: Lines 171, 294, 314 use database transactions.
7. **Input Validation**: Lines 76-144 validate all form inputs.
8. **Phone Normalization**: Lines 93-104 normalize UK phone numbers.
9. **Duplicate Detection**: Lines 195-209, 244-263 prevent duplicate registrations.
10. **UUID Idempotency**: Lines 73, 233-239 prevent duplicate form submissions.
11. **Audit Logging**: Lines 222-230, 282-291 log all submissions.
12. **No Index Meta**: Line 346 prevents search engine indexing.
13. **Anonymous Support**: Lines 176-185 allow anonymous donations.

---

## public/certificate/index.php

**File Path:** `public/certificate/index.php`

### Overview
Certificate background template page (99 lines) providing a visual certificate design. Static HTML/CSS page with no server-side processing.

### Critical Issues
- **NONE** - Purely static HTML/CSS template.

### Enhancements
1. **Strict Types**: Line 2 declares `strict_types=1`.
2. **Pure CSS Design**: Uses CSS clip-paths and gradients for visual effects.
3. **Accessibility**: Line 91 uses `aria-label` for screen readers.

---

## public/projector/floor/index.php

**File Path:** `public/projector/floor/index.php`

### Overview
Floor plan projector page showing allocated grid cells for donations.

### Critical Issues
- **NONE** - Read-only display with API-based data loading.

### Enhancements
1. **API-Based Data**: Fetches grid status from API endpoint.
2. **Real-Time Updates**: Polls for updates at configurable intervals.
3. **Visual Grid Display**: Renders interactive floor plan visualization.

---

## public/projector/floor/3d-view.php

**File Path:** `public/projector/floor/3d-view.php`

### Overview
3D floor plan visualization using Three.js for OBJ model rendering.

### Critical Issues
- **NONE** - Client-side only visualization with no server-side data processing.

### Enhancements
1. **Three.js Integration**: Uses Three.js for 3D rendering.
2. **Model Upload**: Allows uploading OBJ files for visualization.
3. **Camera Controls**: Implements OrbitControls for navigation.

---

## public/reports/index.php

**File Path:** `public/reports/index.php`

### Overview
Public reports page (if exists) displaying aggregate fundraising statistics.

### Critical Issues
- **NONE** - Read-only aggregated data display.

### Enhancements
1. **Anonymized Data**: Public reports should not expose individual donor details.
2. **Cache Headers**: Should implement appropriate caching for static data.

---


