# Complete List of Pages - Fundraising System

This document lists all PHP pages in the system, organized by section.

**Total Pages: ~200+**

---

## Root Level (1 page)

- `index.php` - Homepage/Landing page

---

## Admin Section (~150+ pages)

### Core Admin Pages
- `admin/index.php` - Admin dashboard
- `admin/login.php` - Admin login
- `admin/logout.php` - Admin logout
- `admin/register.php` - Admin registration

### Dashboard
- `admin/dashboard/index.php` - Main dashboard

### Profile
- `admin/profile/index.php` - Admin profile management

### Settings
- `admin/settings/index.php` - System settings

### Audit
- `admin/audit/index.php` - Audit logs viewer

### Members
- `admin/members/index.php` - Members management

### Messages
- `admin/messages/index.php` - Internal messaging system

### Payments
- `admin/payments/index.php` - Payments management

### Pledges
- `admin/pledges/index.php` - Pledges management

### Projector
- `admin/projector/index.php` - Projector display

### System
- `admin/system/index.php` - System management

### Security
- `admin/security/index.php` - Security settings

### User Status
- `admin/user-status/index.php` - User status management

### Grid Allocation
- `admin/grid-allocation/index.php` - Grid allocation main page
- `admin/grid-allocation/manage.php` - Grid management

### Approvals
- `admin/approvals/index.php` - Approvals main page
- `admin/approvals/partial_list.php` - Partial approvals list
- `admin/approvals/partial_payments.php` - Partial payments

### Approved
- `admin/approved/index.php` - Approved items main page
- `admin/approved/partial_list.php` - Approved items list

### Registrar Applications
- `admin/registrar-applications/index.php` - Registrar applications management

### Error Pages
- `admin/error/403.php` - Forbidden error page
- `admin/error/404.php` - Not found error page
- `admin/error/500.php` - Server error page

### Donations (12 pages)
- `admin/donations/index.php` - Donations main page
- `admin/donations/payment.php` - Payment processing
- `admin/donations/pledge.php` - Pledge processing
- `admin/donations/record-pledge-payment.php` - Record pledge payment
- `admin/donations/review-pledge-payments.php` - Review pledge payments
- `admin/donations/approve-pledge-payment.php` - Approve pledge payment
- `admin/donations/delete-pledge-payment.php` - Delete pledge payment
- `admin/donations/void-pledge-payment.php` - Void pledge payment
- `admin/donations/undo-pledge-payment.php` - Undo pledge payment
- `admin/donations/save-pledge-payment.php` - Save pledge payment
- `admin/donations/get-donor-history.php` - Get donor history (API)
- `admin/donations/get-donor-pledges.php` - Get donor pledges (API)

### Donor Management (~58 pages)
- `admin/donor-management/index.php` - Donor management main page
- `admin/donor-management/donors.php` - Donors list
- `admin/donor-management/add-donor.php` - Add new donor
- `admin/donor-management/edit-donor.php` - Edit donor
- `admin/donor-management/view-donor.php` - View donor details
- `admin/donor-management/donor-portal.php` - Donor portal
- `admin/donor-management/payments.php` - Payments management
- `admin/donor-management/add-payment.php` - Add payment
- `admin/donor-management/edit-payment.php` - Edit payment
- `admin/donor-management/delete-payment.php` - Delete payment
- `admin/donor-management/get-payment-data.php` - Get payment data (API)
- `admin/donor-management/pledges.php` - Pledges management
- `admin/donor-management/add-pledge.php` - Add pledge
- `admin/donor-management/edit-pledge.php` - Edit pledge
- `admin/donor-management/delete-pledge.php` - Delete pledge
- `admin/donor-management/get-pledge-data.php` - Get pledge data (API)
- `admin/donor-management/payment-plans.php` - Payment plans list
- `admin/donor-management/list-payment-plans.php` - List payment plans
- `admin/donor-management/add-payment-plan.php` - Add payment plan
- `admin/donor-management/edit-payment-plan.php` - Edit payment plan
- `admin/donor-management/update-payment-plan.php` - Update payment plan
- `admin/donor-management/delete-payment-plan.php` - Delete payment plan
- `admin/donor-management/view-payment-plan.php` - View payment plan
- `admin/donor-management/get-payment-plan-data.php` - Get payment plan data (API)
- `admin/donor-management/update-payment-plan-status.php` - Update payment plan status
- `admin/donor-management/fix-payment-plans.php` - Fix payment plans
- `admin/donor-management/payment-plan-database-inspector.php` - Payment plan database inspector
- `admin/donor-management/payment-plan-database-report.php` - Payment plan database report
- `admin/donor-management/message-history.php` - Message history
- `admin/donor-management/support-requests.php` - Support requests
- `admin/donor-management/trusted-devices.php` - Trusted devices management
- `admin/donor-management/donor.php` - Donor details page
- `admin/donor-management/get-donor-data.php` - Get donor data (API)
- `admin/donor-management/database-analysis-report.php` - Database analysis report
- `admin/donor-management/table-usage-analysis-report.php` - Table usage analysis
- `admin/donor-management/migration-complete-next-steps.php` - Migration completion steps
- `admin/donor-management/setup_donor_type.php` - Setup donor type
- `admin/donor-management/delete-assignment.php` - Delete assignment
- `admin/donor-management/edit-assignment.php` - Edit assignment
- `admin/donor-management/get-call-session-data.php` - Get call session data (API)
- `admin/donor-management/edit-call-session.php` - Edit call session
- `admin/donor-management/delete-call-session.php` - Delete call session
- `admin/donor-management/delete-call-session-debug.php` - Delete call session debug
- `admin/donor-management/delete-call-session-debug-full.php` - Delete call session debug full
- `admin/donor-management/ajax-reschedule-plan.php` - AJAX reschedule plan
- `admin/donor-management/ajax-update-schedule.php` - AJAX update schedule
- `admin/donor-management/test-deletion-minimal.php` - Test deletion minimal

#### SMS Management
- `admin/donor-management/sms/index.php` - SMS main page
- `admin/donor-management/sms/send.php` - Send SMS
- `admin/donor-management/sms/send-ajax.php` - Send SMS (AJAX)
- `admin/donor-management/sms/history.php` - SMS history
- `admin/donor-management/sms/queue.php` - SMS queue
- `admin/donor-management/sms/templates.php` - SMS templates
- `admin/donor-management/sms/settings.php` - SMS settings
- `admin/donor-management/sms/blacklist.php` - SMS blacklist
- `admin/donor-management/sms/whatsapp-settings.php` - WhatsApp settings

#### Twilio Integration
- `admin/donor-management/twilio/index.php` - Twilio main page
- `admin/donor-management/twilio/settings.php` - Twilio settings

### Call Center (~55 pages)
- `admin/call-center/index.php` - Call center main page
- `admin/call-center/make-call.php` - Make call
- `admin/call-center/call-history.php` - Call history
- `admin/call-center/call-status.php` - Call status
- `admin/call-center/call-details.php` - Call details
- `admin/call-center/call-complete.php` - Call complete
- `admin/call-center/conversation.php` - Conversation management
- `admin/call-center/process-conversation.php` - Process conversation
- `admin/call-center/process-conversation-debug.php` - Process conversation debug
- `admin/call-center/conversation-analysis.php` - Conversation analysis
- `admin/call-center/inbound-callbacks.php` - Inbound callbacks
- `admin/call-center/schedule-callback.php` - Schedule callback
- `admin/call-center/callback-scheduled.php` - Callback scheduled
- `admin/call-center/callback-reason.php` - Callback reason
- `admin/call-center/get-appointments.php` - Get appointments (API)
- `admin/call-center/get-available-slots.php` - Get available slots (API)
- `admin/call-center/my-schedule.php` - My schedule
- `admin/call-center/appointment-detail.php` - Appointment detail
- `admin/call-center/delete-appointment.php` - Delete appointment
- `admin/call-center/assign-donors.php` - Assign donors
- `admin/call-center/process-assignment.php` - Process assignment
- `admin/call-center/add-to-queue.php` - Add to queue
- `admin/call-center/availability-check.php` - Availability check
- `admin/call-center/plan-success.php` - Plan success
- `admin/call-center/edit-payment-plan-flow.php` - Edit payment plan flow
- `admin/call-center/process-refusal.php` - Process refusal
- `admin/call-center/confirm-invalid.php` - Confirm invalid
- `admin/call-center/mark-invalid.php` - Mark invalid
- `admin/call-center/reset-call-history.php` - Reset call history
- `admin/call-center/edit-call-record.php` - Edit call record
- `admin/call-center/delete-call-record.php` - Delete call record
- `admin/call-center/ivr-recordings.php` - IVR recordings
- `admin/call-center/twilio-error-report.php` - Twilio error report
- `admin/call-center/database-report.php` - Database report
- `admin/call-center/donor-info-database-report.php` - Donor info database report
- `admin/call-center/campaigns.php` - Campaigns
- `admin/call-center/reports.php` - Reports
- `admin/call-center/check-database.php` - Check database
- `admin/call-center/cleanup-analysis.php` - Cleanup analysis
- `admin/call-center/diagnostic.php` - Diagnostic
- `admin/call-center/debug.php` - Debug
- `admin/call-center/debug-duration.php` - Debug duration
- `admin/call-center/call-status-debug.php` - Call status debug
- `admin/call-center/index-debug.php` - Index debug
- `admin/call-center/index-minimal.php` - Index minimal
- `admin/call-center/index-safe.php` - Index safe
- `admin/call-center/test-exact-error.php` - Test exact error
- `admin/call-center/test-includes.php` - Test includes
- `admin/call-center/test-no-includes.php` - Test no includes
- `admin/call-center/test-simple.php` - Test simple
- `admin/call-center/test-with-auth.php` - Test with auth
- `admin/call-center/test-with-db.php` - Test with DB
- `admin/call-center/ultra-simple-test.php` - Ultra simple test
- `admin/call-center/verify-agent-column.php` - Verify agent column

#### Call Center API Endpoints
- `admin/call-center/api/twilio-inbound-call.php` - Twilio inbound call webhook
- `admin/call-center/api/twilio-initiate-call.php` - Twilio initiate call
- `admin/call-center/api/twilio-status-callback.php` - Twilio status callback
- `admin/call-center/api/twilio-recording-callback.php` - Twilio recording callback
- `admin/call-center/api/twilio-webhook-answer.php` - Twilio webhook answer
- `admin/call-center/api/twilio-ivr-general-menu.php` - Twilio IVR general menu
- `admin/call-center/api/twilio-ivr-donor-menu.php` - Twilio IVR donor menu
- `admin/call-center/api/twilio-ivr-payment-method.php` - Twilio IVR payment method
- `admin/call-center/api/get-call-status.php` - Get call status (API)
- `admin/call-center/api/get-donor-profile.php` - Get donor profile (API)
- `admin/call-center/api/save-ivr-recording.php` - Save IVR recording (API)
- `admin/call-center/api/test-ivr-recording.php` - Test IVR recording (API)
- `admin/call-center/api/twilio-support-notification.php` - Twilio support notification

### Church Management (~14 pages)
- `admin/church-management/index.php` - Church management main page
- `admin/church-management/churches.php` - Churches list
- `admin/church-management/add-church.php` - Add church
- `admin/church-management/edit-church.php` - Edit church
- `admin/church-management/delete-church.php` - Delete church
- `admin/church-management/view-church.php` - View church
- `admin/church-management/representatives.php` - Representatives list
- `admin/church-management/add-representative.php` - Add representative
- `admin/church-management/edit-representative.php` - Edit representative
- `admin/church-management/delete-representative.php` - Delete representative
- `admin/church-management/view-representative.php` - View representative
- `admin/church-management/assign-donors.php` - Assign donors
- `admin/church-management/church-assigned-donors.php` - Church assigned donors
- `admin/church-management/get-representatives.php` - Get representatives (API)

### Messaging (~12 pages)
- `admin/messaging/whatsapp/inbox.php` - WhatsApp inbox
- `admin/messaging/whatsapp/new-chat.php` - New chat
- `admin/messaging/whatsapp/templates.php` - WhatsApp templates

#### Messaging API Endpoints
- `admin/messaging/whatsapp/api/send-message.php` - Send message (API)
- `admin/messaging/whatsapp/api/get-conversations.php` - Get conversations (API)
- `admin/messaging/whatsapp/api/get-new-messages.php` - Get new messages (API)
- `admin/messaging/whatsapp/api/get-templates.php` - Get templates (API)
- `admin/messaging/whatsapp/api/send-media.php` - Send media (API)
- `admin/messaging/whatsapp/api/delete-message.php` - Delete message (API)
- `admin/messaging/whatsapp/api/delete-conversation.php` - Delete conversation (API)
- `admin/messaging/whatsapp/api/bulk-mark-read.php` - Bulk mark read (API)
- `admin/messaging/whatsapp/api/bulk-delete.php` - Bulk delete (API)

### Reports (~5 pages)
- `admin/reports/index.php` - Reports main page
- `admin/reports/comprehensive.php` - Comprehensive reports
- `admin/reports/financial-dashboard.php` - Financial dashboard
- `admin/reports/visual.php` - Visual reports

#### Reports API
- `admin/reports/api/financial-data.php` - Financial data (API)

### Tools (~28 pages)
- `admin/tools/index.php` - Tools main page
- `admin/tools/donor-import-wizard.php` - Donor import wizard
- `admin/tools/import_database.php` - Import database
- `admin/tools/export_database.php` - Export database
- `admin/tools/populate_grid_cells.php` - Populate grid cells
- `admin/tools/populate_from_json.php` - Populate from JSON
- `admin/tools/reset_floor_map.php` - Reset floor map
- `admin/tools/force_floor_refresh.php` - Force floor refresh
- `admin/tools/analyze_grid_totals.php` - Analyze grid totals
- `admin/tools/fix_allocation_table.php` - Fix allocation table
- `admin/tools/fix_cell_mismatch.php` - Fix cell mismatch
- `admin/tools/fix_partial_migration.php` - Fix partial migration
- `admin/tools/upgrade_grid_schema.php` - Upgrade grid schema
- `admin/tools/test_grid_allocation.php` - Test grid allocation
- `admin/tools/test_allocation_cycle.php` - Test allocation cycle
- `admin/tools/test_deallocation.php` - Test deallocation
- `admin/tools/test_smart_allocation.php` - Test smart allocation
- `admin/tools/test_immediate_refresh.php` - Test immediate refresh
- `admin/tools/debug_cell_mapping.php` - Debug cell mapping
- `admin/tools/debug_refresh_issue.php` - Debug refresh issue
- `admin/tools/check_migration_status.php` - Check migration status
- `admin/tools/database_status.php` - Database status
- `admin/tools/migrate_donors_system.php` - Migrate donors system
- `admin/tools/migrate_messaging_system.php` - Migrate messaging system
- `admin/tools/test-messaging.php` - Test messaging
- `admin/tools/local_failover_guide.php` - Local failover guide
- `admin/tools/wipe_database.php` - Wipe database

### Donors (4 pages)
- `admin/donors/index.php` - Donors main page
- `admin/donors/view.php` - View donor
- `admin/donors/data-clarity.php` - Data clarity
- `admin/donors/test-simple.php` - Test simple

---

## Donor Section (10 pages)

- `donor/index.php` - Donor dashboard
- `donor/login.php` - Donor login
- `donor/logout.php` - Donor logout
- `donor/profile.php` - Donor profile
- `donor/make-payment.php` - Make payment
- `donor/payment-history.php` - Payment history
- `donor/payment-plan.php` - Payment plan
- `donor/update-pledge.php` - Update pledge
- `donor/contact.php` - Contact page

### Donor API
- `donor/api/location-data.php` - Location data (API)

---

## Registrar Section (9 pages)

- `registrar/index.php` - Registrar dashboard
- `registrar/login.php` - Registrar login
- `registrar/logout.php` - Registrar logout
- `registrar/register.php` - Registrar registration
- `registrar/profile.php` - Registrar profile
- `registrar/my-registrations.php` - My registrations
- `registrar/statistics.php` - Statistics
- `registrar/access-denied.php` - Access denied page

### Registrar Messages
- `registrar/messages/index.php` - Messages

---

## Public Section (6 pages)

- `public/donate/index.php` - Public donation page
- `public/projector/index.php` - Public projector display
- `public/projector/floor/index.php` - Floor map view
- `public/projector/floor/3d-view.php` - 3D floor view
- `public/certificate/index.php` - Certificate page
- `public/reports/donor-review.php` - Donor review report

---

## API Section (9 pages)

- `api/calculate_sqm.php` - Calculate square meters
- `api/check_donor.php` - Check donor
- `api/donor_history.php` - Donor history
- `api/footer.php` - Footer data
- `api/grid_status.php` - Grid status
- `api/member_generate_code.php` - Generate member code
- `api/member_stats.php` - Member statistics
- `api/recent.php` - Recent donations
- `api/totals.php` - Totals

---

## Reports Section (1 page)

- `reports/donor-review.php` - Donor review report

---

## Summary

| Section | Page Count |
|---------|------------|
| Root Level | 1 |
| Admin Section | ~150+ |
| Donor Section | 10 |
| Registrar Section | 9 |
| Public Section | 6 |
| API Section | 9 |
| Reports Section | 1 |
| **TOTAL** | **~186+** |

---

**Note:** This count includes user-facing pages and API endpoints. Excluded are:
- Include files (`includes/` directories)
- Service files (`services/` directory)
- Config files (`config/` directory)
- Shared helper files (`shared/` directory)
- Database migration files (`.sql` files)
- Documentation files (`.md` files)
- Cron jobs (`cron/` directory)
- Webhooks (`webhooks/` directory)

