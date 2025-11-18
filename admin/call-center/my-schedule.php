<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

// Set timezone to London
date_default_timezone_set('Europe/London');

$user_id = (int)$_SESSION['user']['id'];
$user_name = $_SESSION['user']['name'] ?? 'Unknown';

$page_title = 'My Schedule';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - Call Center</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/call-center.css">
    <style>
        .schedule-page {
            padding: 0.75rem;
        }
        
        /* Header Section */
        .schedule-header {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .schedule-header .title-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .schedule-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #0a6286;
            margin: 0;
        }
        
        /* View Switcher */
        .view-switcher {
            display: flex;
            gap: 0.5rem;
            background: #f1f5f9;
            padding: 0.25rem;
            border-radius: 8px;
        }
        
        .view-btn {
            padding: 0.5rem 1rem;
            border: none;
            background: transparent;
            color: #64748b;
            font-weight: 600;
            font-size: 0.875rem;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .view-btn:hover {
            background: rgba(255, 255, 255, 0.5);
        }
        
        .view-btn.active {
            background: white;
            color: #0a6286;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        /* Controls Section */
        .schedule-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
        }
        
        .nav-controls {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
        
        .nav-btn {
            padding: 0.5rem 0.875rem;
            border: 1px solid #e2e8f0;
            background: white;
            color: #475569;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .nav-btn:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }
        
        .current-period {
            font-size: 1rem;
            font-weight: 700;
            color: #1e293b;
            padding: 0 0.5rem;
            min-width: 200px;
            text-align: center;
        }
        
        /* Filters & Search */
        .filters-section {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-left: auto;
        }
        
        .search-box {
            position: relative;
            width: 200px;
        }
        
        .search-box input {
            width: 100%;
            padding: 0.5rem 0.875rem 0.5rem 2.25rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.875rem;
        }
        
        .search-box .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }
        
        .filter-btn {
            padding: 0.5rem 0.875rem;
            border: 1px solid #e2e8f0;
            background: white;
            color: #475569;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .filter-btn.active {
            background: #0a6286;
            color: white;
            border-color: #0a6286;
        }
        
        /* Calendar Container */
        .calendar-container {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            min-height: 600px;
        }
        
        /* Month View */
        .month-view {
            display: none;
        }
        
        .month-view.active {
            display: block;
        }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1px;
            background: #e2e8f0;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .calendar-header-cell {
            background: #f8fafc;
            padding: 0.75rem 0.5rem;
            text-align: center;
            font-weight: 700;
            font-size: 0.875rem;
            color: #475569;
        }
        
        .calendar-day {
            background: white;
            min-height: 100px;
            padding: 0.5rem;
            position: relative;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .calendar-day:hover {
            background: #f8fafc;
        }
        
        .calendar-day.other-month {
            background: #fafafa;
            color: #cbd5e1;
        }
        
        .calendar-day.today {
            background: #eff6ff;
            border: 2px solid #0a6286;
        }
        
        .day-number {
            font-size: 0.875rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.25rem;
        }
        
        .calendar-day.other-month .day-number {
            color: #cbd5e1;
        }
        
        .day-appointments {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        
        .appointment-pill {
            background: #0a6286;
            color: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            transition: all 0.2s;
        }
        
        .appointment-pill:hover {
            transform: scale(1.05);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }
        
        .appointment-pill.confirmed {
            background: #22c55e;
        }
        
        .appointment-pill.completed {
            background: #94a3b8;
        }
        
        .appointment-pill.cancelled {
            background: #ef4444;
        }
        
        /* Week View */
        .week-view {
            display: none;
        }
        
        .week-view.active {
            display: block;
        }
        
        .week-grid {
            display: grid;
            grid-template-columns: 80px repeat(7, 1fr);
            gap: 1px;
            background: #e2e8f0;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .week-time-slot {
            background: #f8fafc;
            padding: 0.5rem;
            text-align: center;
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748b;
            border-right: 2px solid #e2e8f0;
        }
        
        .week-day-slot {
            background: white;
            min-height: 60px;
            padding: 0.25rem;
            position: relative;
        }
        
        .week-appointment {
            background: #0a6286;
            color: white;
            padding: 0.375rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            margin-bottom: 2px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .week-appointment:hover {
            transform: translateX(2px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }
        
        /* Day View */
        .day-view {
            display: none;
        }
        
        .day-view.active {
            display: block;
        }
        
        .day-timeline {
            display: grid;
            grid-template-columns: 80px 1fr;
            gap: 1px;
            background: #e2e8f0;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .timeline-slot {
            display: contents;
        }
        
        .timeline-time {
            background: #f8fafc;
            padding: 0.75rem 0.5rem;
            text-align: center;
            font-size: 0.875rem;
            font-weight: 600;
            color: #64748b;
        }
        
        .timeline-content {
            background: white;
            padding: 0.75rem;
            min-height: 80px;
            position: relative;
        }
        
        .timeline-appointment {
            background: #0a6286;
            color: white;
            padding: 0.75rem;
            border-radius: 6px;
            margin-bottom: 0.5rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .timeline-appointment:hover {
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transform: translateY(-1px);
        }
        
        .appointment-time {
            font-size: 0.875rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .appointment-donor {
            font-size: 0.875rem;
            margin-bottom: 0.125rem;
        }
        
        .appointment-phone {
            font-size: 0.75rem;
            opacity: 0.9;
        }
        
        /* Loading State */
        .loading-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 400px;
            color: #64748b;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #64748b;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .schedule-header .title-section {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
            }
            
            .schedule-controls {
                flex-direction: column;
            }
            
            .nav-controls {
                width: 100%;
                justify-content: space-between;
            }
            
            .current-period {
                font-size: 0.875rem;
                min-width: auto;
            }
            
            .filters-section {
                width: 100%;
                margin-left: 0;
            }
            
            .search-box {
                flex: 1;
            }
            
            .calendar-day {
                min-height: 80px;
                padding: 0.25rem;
            }
            
            .day-number {
                font-size: 0.75rem;
            }
            
            .appointment-pill {
                font-size: 0.625rem;
                padding: 1px 4px;
            }
            
            .week-grid {
                grid-template-columns: 60px repeat(7, 1fr);
                font-size: 0.75rem;
            }
            
            .day-timeline {
                grid-template-columns: 60px 1fr;
            }
        }
        
        @media (max-width: 576px) {
            .calendar-grid {
                gap: 0;
            }
            
            .calendar-header-cell {
                font-size: 0.75rem;
                padding: 0.5rem 0.25rem;
            }
            
            .calendar-day {
                min-height: 60px;
            }
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="schedule-page">
                <!-- Header -->
                <div class="schedule-header">
                    <div class="title-section">
                        <div>
                            <h1 class="schedule-title">
                                <i class="fas fa-calendar-alt me-2"></i>My Schedule
                            </h1>
                            <p class="text-muted mb-0" style="font-size: 0.875rem;">Manage your appointments and availability</p>
                        </div>
                        
                        <div class="view-switcher">
                            <button class="view-btn active" data-view="month">
                                <i class="fas fa-calendar me-1"></i>Month
                            </button>
                            <button class="view-btn" data-view="week">
                                <i class="fas fa-calendar-week me-1"></i>Week
                            </button>
                            <button class="view-btn" data-view="day">
                                <i class="fas fa-calendar-day me-1"></i>Day
                            </button>
                        </div>
                    </div>
                    
                    <div class="schedule-controls">
                        <div class="nav-controls">
                            <button class="nav-btn" id="prev-btn">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                            <button class="nav-btn" id="today-btn">Today</button>
                            <button class="nav-btn" id="next-btn">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                            <div class="current-period" id="current-period">Loading...</div>
                        </div>
                        
                        <div class="filters-section">
                            <div class="search-box">
                                <i class="fas fa-search search-icon"></i>
                                <input type="text" id="search-input" placeholder="Search donor..." />
                            </div>
                            <button class="filter-btn" data-filter="all">
                                <i class="fas fa-th"></i>All
                            </button>
                            <button class="filter-btn" data-filter="scheduled">
                                <i class="fas fa-clock"></i>Scheduled
                            </button>
                            <button class="filter-btn" data-filter="confirmed">
                                <i class="fas fa-check"></i>Confirmed
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Calendar Container -->
                <div class="calendar-container">
                    <!-- Month View -->
                    <div class="month-view active" id="month-view">
                        <div class="loading-container">
                            <i class="fas fa-spinner fa-spin fa-2x me-2"></i>
                            Loading calendar...
                        </div>
                    </div>
                    
                    <!-- Week View -->
                    <div class="week-view" id="week-view">
                        <div class="loading-container">
                            <i class="fas fa-spinner fa-spin fa-2x me-2"></i>
                            Loading week view...
                        </div>
                    </div>
                    
                    <!-- Day View -->
                    <div class="day-view" id="day-view">
                        <div class="loading-container">
                            <i class="fas fa-spinner fa-spin fa-2x me-2"></i>
                            Loading day view...
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
const agentId = <?php echo $user_id; ?>;
let currentView = 'month';
let currentDate = new Date();
let appointments = [];
let filteredAppointments = [];
let currentFilter = 'all';
let searchQuery = '';

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    setupViewSwitcher();
    setupNavigation();
    setupFilters();
    setupSearch();
    loadAppointments();
});

function setupViewSwitcher() {
    document.querySelectorAll('.view-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const view = this.dataset.view;
            switchView(view);
        });
    });
}

function switchView(view) {
    currentView = view;
    
    // Update buttons
    document.querySelectorAll('.view-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.view === view);
    });
    
    // Update views
    document.getElementById('month-view').classList.toggle('active', view === 'month');
    document.getElementById('week-view').classList.toggle('active', view === 'week');
    document.getElementById('day-view').classList.toggle('active', view === 'day');
    
    renderCalendar();
}

function setupNavigation() {
    document.getElementById('prev-btn').addEventListener('click', () => {
        navigateCalendar(-1);
    });
    
    document.getElementById('next-btn').addEventListener('click', () => {
        navigateCalendar(1);
    });
    
    document.getElementById('today-btn').addEventListener('click', () => {
        currentDate = new Date();
        renderCalendar();
    });
}

function navigateCalendar(direction) {
    if (currentView === 'month') {
        currentDate.setMonth(currentDate.getMonth() + direction);
    } else if (currentView === 'week') {
        currentDate.setDate(currentDate.getDate() + (7 * direction));
    } else {
        currentDate.setDate(currentDate.getDate() + direction);
    }
    renderCalendar();
}

function setupFilters() {
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const filter = this.dataset.filter;
            currentFilter = filter;
            
            document.querySelectorAll('.filter-btn').forEach(b => {
                b.classList.remove('active');
            });
            this.classList.add('active');
            
            applyFilters();
        });
    });
}

function setupSearch() {
    const searchInput = document.getElementById('search-input');
    let searchTimeout;
    
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            searchQuery = this.value.toLowerCase();
            applyFilters();
        }, 300);
    });
}

function applyFilters() {
    filteredAppointments = appointments.filter(apt => {
        // Filter by status
        if (currentFilter !== 'all' && apt.status !== currentFilter) {
            return false;
        }
        
        // Filter by search query
        if (searchQuery && !apt.donor_name.toLowerCase().includes(searchQuery)) {
            return false;
        }
        
        return true;
    });
    
    renderCalendar();
}

async function loadAppointments() {
    try {
        const response = await fetch(`get-appointments.php?agent_id=${agentId}`);
        const data = await response.json();
        
        if (data.success) {
            appointments = data.appointments;
            filteredAppointments = appointments;
            renderCalendar();
        } else {
            console.error('Failed to load appointments:', data.message);
        }
    } catch (error) {
        console.error('Error loading appointments:', error);
    }
}

function renderCalendar() {
    updatePeriodLabel();
    
    if (currentView === 'month') {
        renderMonthView();
    } else if (currentView === 'week') {
        renderWeekView();
    } else {
        renderDayView();
    }
}

function updatePeriodLabel() {
    const months = ['January', 'February', 'March', 'April', 'May', 'June',
                   'July', 'August', 'September', 'October', 'November', 'December'];
    
    let label = '';
    if (currentView === 'month') {
        label = `${months[currentDate.getMonth()]} ${currentDate.getFullYear()}`;
    } else if (currentView === 'week') {
        const weekStart = getWeekStart(currentDate);
        const weekEnd = new Date(weekStart);
        weekEnd.setDate(weekEnd.getDate() + 6);
        label = `${months[weekStart.getMonth()]} ${weekStart.getDate()} - ${months[weekEnd.getMonth()]} ${weekEnd.getDate()}, ${weekStart.getFullYear()}`;
    } else {
        label = `${months[currentDate.getMonth()]} ${currentDate.getDate()}, ${currentDate.getFullYear()}`;
    }
    
    document.getElementById('current-period').textContent = label;
}

function getWeekStart(date) {
    const d = new Date(date);
    const day = d.getDay();
    const diff = d.getDate() - day + (day === 0 ? -6 : 1); // Monday as first day
    return new Date(d.setDate(diff));
}

function renderMonthView() {
    // Implementation in next part - Month calendar grid
    const container = document.getElementById('month-view');
    container.innerHTML = '<div class="loading-container"><i class="fas fa-spinner fa-spin fa-2x me-2"></i>Loading month view...</div>';
    
    setTimeout(() => {
        container.innerHTML = generateMonthCalendar();
    }, 100);
}

function generateMonthCalendar() {
    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const startDay = firstDay.getDay();
    const daysInMonth = lastDay.getDate();
    
    let html = '<div class="calendar-grid">';
    
    // Header
    ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].forEach(day => {
        html += `<div class="calendar-header-cell">${day}</div>`;
    });
    
    // Days
    let day = 1;
    for (let i = 0; i < 6; i++) {
        for (let j = 0; j < 7; j++) {
            if (i === 0 && j < startDay) {
                html += '<div class="calendar-day other-month"></div>';
            } else if (day > daysInMonth) {
                html += '<div class="calendar-day other-month"></div>';
            } else {
                const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                const isToday = dateStr === new Date().toISOString().split('T')[0];
                const dayAppointments = filteredAppointments.filter(apt => apt.appointment_date === dateStr);
                
                html += `<div class="calendar-day${isToday ? ' today' : ''}" data-date="${dateStr}">`;
                html += `<div class="day-number">${day}</div>`;
                
                if (dayAppointments.length > 0) {
                    html += '<div class="day-appointments">';
                    dayAppointments.slice(0, 3).forEach(apt => {
                        html += `<div class="appointment-pill ${apt.status}" onclick="window.location.href='appointment-detail.php?id=${apt.id}'" style="cursor: pointer;" title="${apt.donor_name} - ${apt.appointment_time.substring(0, 5)}">${apt.appointment_time.substring(0, 5)}</div>`;
                    });
                    if (dayAppointments.length > 3) {
                        html += `<div class="appointment-pill" onclick="window.location.href='my-schedule.php?view=day&date=${dateStr}'" style="cursor: pointer;">+${dayAppointments.length - 3} more</div>`;
                    }
                    html += '</div>';
                }
                
                html += '</div>';
                day++;
            }
        }
        if (day > daysInMonth) break;
    }
    
    html += '</div>';
    return html;
}

function renderWeekView() {
    const container = document.getElementById('week-view');
    const weekStart = getWeekStart(currentDate);
    
    let html = '<div class="week-grid">';
    
    // Header (Empty corner + Days)
    html += '<div class="calendar-header-cell">Time</div>';
    for (let i = 0; i < 7; i++) {
        const day = new Date(weekStart);
        day.setDate(day.getDate() + i);
        const isToday = day.toDateString() === new Date().toDateString();
        const dayName = day.toLocaleDateString('en-US', { weekday: 'short' });
        const dayNum = day.getDate();
        
        html += `
            <div class="calendar-header-cell${isToday ? ' text-primary' : ''}">
                ${dayName} ${dayNum}
            </div>
        `;
    }
    
    // Time slots (Full 24 Hours)
    for (let hour = 0; hour < 24; hour++) {
        // Time label
        const displayHour = hour;
        const timeLabel = `${String(hour).padStart(2, '0')}:00`;
        html += `<div class="week-time-slot">${timeLabel}</div>`;
        
        // Day columns
        for (let i = 0; i < 7; i++) {
            const day = new Date(weekStart);
            day.setDate(day.getDate() + i);
            const dateStr = day.toISOString().split('T')[0];
            const timePrefix = String(hour).padStart(2, '0');
            
            // Find appointments in this hour slot
            const hourAppointments = filteredAppointments.filter(apt => 
                apt.appointment_date === dateStr && 
                apt.appointment_time.startsWith(timePrefix)
            );
            
            html += `<div class="week-day-slot" data-date="${dateStr}" data-hour="${hour}">`;
            
            hourAppointments.forEach(apt => {
                html += `
                    <div class="week-appointment ${apt.status}" 
                         onclick="window.location.href='appointment-detail.php?id=${apt.id}'" 
                         style="cursor: pointer;" 
                         title="${apt.donor_name} - ${apt.appointment_time}">
                        <div class="fw-bold text-truncate">${apt.donor_name}</div>
                        <div class="small text-truncate">${apt.appointment_time.substring(0, 5)}</div>
                    </div>
                `;
            });
            
            html += '</div>';
        }
    }
    
    html += '</div>';
    container.innerHTML = html;
}

function renderDayView() {
    const container = document.getElementById('day-view');
    
    const dateStr = currentDate.toISOString().split('T')[0];
    const dayAppointments = filteredAppointments.filter(apt => apt.appointment_date === dateStr);
    
    let html = '<div class="day-timeline">';
    
    // Generate time slots (Full 24 Hours)
    for (let hour = 0; hour < 24; hour++) {
        const timeStr = `${String(hour).padStart(2, '0')}:00`;
        const appointmentsInSlot = dayAppointments.filter(apt => apt.appointment_time.startsWith(String(hour).padStart(2, '0')));
        
        html += '<div class="timeline-slot">';
        html += `<div class="timeline-time">${timeStr}</div>`;
        html += '<div class="timeline-content">';
        
        appointmentsInSlot.forEach(apt => {
            const time = apt.appointment_time.substring(0, 5);
            html += `
                <div class="timeline-appointment ${apt.status}" 
                     onclick="window.location.href='appointment-detail.php?id=${apt.id}'" 
                     style="cursor: pointer;">
                    <div class="appointment-time">${time}</div>
                    <div class="appointment-donor">${apt.donor_name}</div>
                    <div class="appointment-phone">${apt.donor_phone}</div>
                </div>
            `;
        });
        
        html += '</div>';
        html += '</div>';
    }
    
    html += '</div>';
    container.innerHTML = html;
}
</script>
</body>
</html>

