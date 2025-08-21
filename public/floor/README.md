# 🏢 Modern 3D Floor Map - Fundraising Project

This directory contains cutting-edge 3D floor map implementations using modern web technologies that integrate seamlessly with your existing PHP/MySQL backend.

## 🚀 Available Implementations

### 1. **Three.js + Vanilla JS** (`3d-floor-map.html`)
- **Best for**: High-performance 3D graphics, real-time rendering
- **Features**: Interactive 3D grid, mouse controls, real-time data updates
- **Performance**: Excellent for large datasets and smooth animations

### 2. **Svelte + Modern UI** (`svelte-floor-map.html`)
- **Best for**: Reactive UI components, modern development experience
- **Features**: Advanced filtering, search, modals, notifications
- **Performance**: Optimized rendering with reactive state management

## 🛠️ Technology Stack

- **Frontend**: Three.js (3D graphics), Svelte (reactive UI), Modern CSS
- **Backend**: PHP 8+, MySQL/MariaDB
- **APIs**: RESTful JSON endpoints
- **Styling**: CSS Grid, Flexbox, CSS Variables, Backdrop Filters

## 📁 File Structure

```
public/floor/
├── 3d-floor-map.html          # Three.js implementation
├── svelte-floor-map.html      # Svelte implementation
├── README.md                  # This file
└── assets/                    # Additional assets (if needed)
```

## 🚀 Quick Start

### Option 1: Three.js Implementation
1. Navigate to `/public/floor/3d-floor-map.html`
2. The page will automatically load with mock data
3. Use mouse to rotate, scroll to zoom, right-click to pan
4. Click cells to select them

### Option 2: Svelte Implementation
1. Navigate to `/public/floor/svelte-floor-map.html`
2. Experience the reactive UI with advanced features
3. Use search, filters, and modals for enhanced interaction

## 🔧 Backend Integration

### Database Schema Requirements

Your database should have these tables for full functionality:

```sql
-- Grid cells table
CREATE TABLE grid_cells (
    id INT PRIMARY KEY AUTO_INCREMENT,
    row_position INT NOT NULL,
    col_position INT NOT NULL,
    status ENUM('available', 'occupied', 'reserved', 'premium') DEFAULT 'available',
    donor_id INT NULL,
    amount DECIMAL(10,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_position (row_position, col_position)
);

-- Donors table
CREATE TABLE donors (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    phone VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Add foreign key constraint
ALTER TABLE grid_cells 
ADD CONSTRAINT fk_donor 
FOREIGN KEY (donor_id) REFERENCES donors(id);
```

### API Endpoints

The system uses these API endpoints:

- **`/api/grid_status.php`** - Get real-time grid status and statistics
- **`/api/member_stats.php`** - Get member/donor statistics
- **`/api/totals.php`** - Get financial totals

## 🎨 Customization Options

### Colors and Themes

Modify the CSS variables in the `<style>` sections:

```css
:root {
    --primary-color: #667eea;
    --secondary-color: #764ba2;
    --success-color: #4CAF50;
    --warning-color: #FF9800;
    --danger-color: #F44336;
    --premium-color: #9C27B0;
}
```

### Grid Configuration

Adjust the grid size in the JavaScript:

```javascript
// In createCellGrid() function
const gridSize = 10; // Change from 10x10 to any size
const cellSize = 8;  // Adjust cell dimensions
const spacing = 2;   // Adjust spacing between cells
```

### 3D Effects

Customize Three.js rendering:

```javascript
// Lighting
const ambientLight = new THREE.AmbientLight(0x404040, 0.6);
const directionalLight = new THREE.DirectionalLight(0xffffff, 0.8);

// Materials
const cellMaterial = new THREE.MeshLambertMaterial({ 
    color: 0x4CAF50,
    transparent: true,
    opacity: 0.8
});
```

## 🔌 Integration with Existing Admin Panel

### 1. Add Navigation Links

Add these links to your admin sidebar:

```php
<!-- In admin/includes/sidebar.php -->
<li class="nav-item">
    <a href="/public/floor/3d-floor-map.html" class="nav-link">
        <i class="fas fa-cube"></i>
        <span>3D Floor Map</span>
    </a>
</li>
<li class="nav-item">
    <a href="/public/floor/svelte-floor-map.html" class="nav-link">
        <i class="fas fa-chart-line"></i>
        <span>Modern Floor Map</span>
    </a>
</li>
```

### 2. Embed in Admin Pages

You can embed the 3D viewer in existing admin pages:

```php
<!-- In any admin page -->
<div class="card">
    <div class="card-header">
        <h5>Floor Map Overview</h5>
    </div>
    <div class="card-body">
        <iframe src="/public/floor/3d-floor-map.html" 
                width="100%" 
                height="600px" 
                frameborder="0">
        </iframe>
    </div>
</div>
```

## 📱 Responsive Design

Both implementations are fully responsive:

- **Desktop**: Full 3D experience with all UI panels
- **Tablet**: Optimized layout with collapsible panels
- **Mobile**: Touch-friendly controls and simplified interface

## 🎯 Advanced Features

### Real-time Updates

Enable WebSocket or Server-Sent Events for live updates:

```javascript
// Add to your JavaScript
const eventSource = new EventSource('/api/grid_updates.php');
eventSource.onmessage = function(event) {
    const data = JSON.parse(event.data);
    updateFloorMap(data);
};
```

### Export Functionality

The system includes data export capabilities:

- **JSON Export**: Download complete grid data
- **CSV Export**: Spreadsheet-friendly format
- **PDF Reports**: Printable floor plan reports

### Search and Filter

Advanced search capabilities:

- **Text Search**: Find donors, cell references
- **Status Filtering**: Filter by availability, occupation
- **Date Range**: Filter by creation/update dates

## 🚀 Performance Optimization

### 1. Lazy Loading

```javascript
// Load cells only when visible
const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            loadCellData(entry.target.dataset.cellId);
        }
    });
});
```

### 2. Data Caching

```javascript
// Cache API responses
const cache = new Map();
const cacheTimeout = 30000; // 30 seconds

async function getCachedData(url) {
    if (cache.has(url) && Date.now() - cache.get(url).timestamp < cacheTimeout) {
        return cache.get(url).data;
    }
    
    const response = await fetch(url);
    const data = await response.json();
    cache.set(url, { data, timestamp: Date.now() });
    return data;
}
```

### 3. WebGL Optimization

```javascript
// Optimize Three.js rendering
renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
renderer.shadowMap.enabled = true;
renderer.shadowMap.type = THREE.PCFSoftShadowMap;
```

## 🔒 Security Considerations

### 1. CORS Configuration

Ensure your API endpoints have proper CORS headers:

```php
// In your PHP API files
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
```

### 2. Input Validation

Always validate user inputs:

```php
// Sanitize inputs
$row = filter_input(INPUT_POST, 'row', FILTER_VALIDATE_INT);
$col = filter_input(INPUT_POST, 'col', FILTER_VALIDATE_INT);

if ($row === false || $col === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}
```

### 3. Authentication

Protect sensitive operations:

```php
// Check user permissions
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
```

## 🧪 Testing and Debugging

### Browser Compatibility

Tested and optimized for:

- **Chrome**: 90+ (Full support)
- **Firefox**: 88+ (Full support)
- **Safari**: 14+ (Full support)
- **Edge**: 90+ (Full support)

### Debug Mode

Enable debug mode for development:

```javascript
// Add to your JavaScript
const DEBUG = true;

if (DEBUG) {
    console.log('Floor map initialized');
    console.log('Grid cells:', floorCells);
    console.log('Camera position:', camera.position);
}
```

### Performance Monitoring

Monitor rendering performance:

```javascript
// FPS counter
let frameCount = 0;
let lastTime = performance.now();

function animate() {
    frameCount++;
    const currentTime = performance.now();
    
    if (currentTime - lastTime >= 1000) {
        const fps = Math.round((frameCount * 1000) / (currentTime - lastTime));
        console.log('FPS:', fps);
        frameCount = 0;
        lastTime = currentTime;
    }
    
    requestAnimationFrame(animate);
}
```

## 🚀 Future Enhancements

### Planned Features

1. **VR Support**: Oculus Quest and HTC Vive compatibility
2. **AR Integration**: Mobile AR for on-site viewing
3. **AI Analytics**: Predictive occupancy patterns
4. **Multi-language**: Internationalization support
5. **Dark/Light Themes**: User preference switching

### Integration Possibilities

- **Slack/Teams**: Real-time notifications
- **Google Calendar**: Event scheduling integration
- **Stripe/PayPal**: Payment processing
- **Email Marketing**: Automated donor communications

## 📞 Support and Maintenance

### Regular Updates

- **Monthly**: Security patches and bug fixes
- **Quarterly**: Feature updates and performance improvements
- **Annually**: Major version releases

### Backup Strategy

```bash
# Database backup
mysqldump -u username -p database_name > backup_$(date +%Y%m%d).sql

# File backup
tar -czf floor_map_backup_$(date +%Y%m%d).tar.gz public/floor/
```

## 🎉 Conclusion

These modern 3D floor map implementations provide:

- **Stunning Visuals**: Professional 3D graphics
- **Modern UX**: Intuitive, responsive interfaces
- **Performance**: Optimized for smooth operation
- **Integration**: Seamless PHP/MySQL compatibility
- **Scalability**: Ready for enterprise use

Choose the implementation that best fits your needs:
- **Three.js**: For maximum 3D performance
- **Svelte**: For modern, reactive UI development

Both options will transform your fundraising project's user experience and provide a competitive edge in the market!

---

**Happy Coding! 🚀**
