# Donations Management System

## Overview
A comprehensive admin page for managing all fundraising donations (payments and pledges) with advanced filtering, detailed views, and full CRUD operations.

## Features

### 📊 Dashboard Statistics
- **Total Donations**: Combined count of payments and pledges
- **Approved Total**: Sum of all approved donations with breakdown
- **Pending Review**: Amount awaiting approval
- **Approval Rate**: Percentage of approved vs. total submissions

### 🔍 Advanced Filtering
- **Donation Type**: Filter by payments or pledges
- **Status**: Pending, approved, rejected, voided
- **Payment Method**: Cash, bank transfer, card, other
- **Date Range**: From/to date filtering
- **Search**: Name, phone, email, notes/reference

### 📋 Comprehensive List View
- **Unified Table**: Payments and pledges in one view
- **Detailed Information**: ID, date/time, donor info, amount, method, status
- **Package Information**: Shows associated donation packages
- **Processing Details**: Who processed and when

### 🔧 CRUD Operations
- **View Details**: Complete donation information in modal
- **Edit**: Update donor info, amounts, status, method, notes
- **Delete**: Remove donations with confirmation
- **Audit Trail**: All changes logged for accountability

### 📱 Mobile-First Design
- **Responsive Layout**: Adapts to all screen sizes
- **Touch-Friendly**: Optimized for mobile interaction
- **Collapsible Filters**: Clean mobile interface
- **Modern UI**: Following 2025 design standards

## Technical Implementation

### Files Structure
```
admin/donations/
├── index.php              # Main page with PHP logic
├── assets/
│   ├── donations.css      # Modern responsive styling
│   └── donations.js       # Interactive functionality
└── README.md              # This documentation
```

### Database Integration
- **Unified Query**: Combines `payments` and `pledges` tables
- **Audit Logging**: All changes tracked in `audit_logs`
- **Package Support**: Links to `donation_packages`
- **User Tracking**: Records who processed each donation

### Security Features
- **CSRF Protection**: All forms protected
- **Authentication**: Admin access required
- **Input Validation**: Server-side sanitization
- **SQL Injection Prevention**: Prepared statements

### Performance Optimizations
- **Pagination**: Efficient data loading (25 records per page)
- **Indexed Queries**: Optimized database access
- **Lazy Loading**: Resources loaded as needed
- **Caching**: Browser optimization

## Usage Instructions

### Accessing the Page
Navigate to **Admin → Management → Donations Management** in the sidebar.

### Filtering Donations
1. Use the advanced filters to narrow down results
2. Filters auto-submit for immediate results
3. Search supports partial matches across multiple fields
4. Date range filtering for time-based analysis

### Managing Donations
1. **View**: Click the eye icon to see full details
2. **Edit**: Click the edit icon to modify information
3. **Delete**: Click trash icon for removal (requires confirmation)

### Exporting Data
Click the "Export" button to download filtered results as CSV.

## Browser Compatibility
- **Modern Browsers**: Chrome 90+, Firefox 88+, Safari 14+, Edge 90+
- **Mobile Support**: iOS Safari, Android Chrome
- **Accessibility**: WCAG 2.1 AA compliant
- **Performance**: Optimized for fast loading

## Integration Points
- **Sidebar Navigation**: Added to Management section
- **Audit System**: All actions logged
- **User Management**: Links to user records
- **Package System**: Shows donation package details
- **Settings**: Respects currency and display preferences
