# LINO: Links IN / Links Out - Complete Documentation

## Overview

**LINO (Links IN / Links Out)** is a comprehensive WordPress plugin that provides Bitly-style short link functionality with advanced features including custom routing, dual redirect modes, click statistics, and seamless Gutenberg integration. The plugin is self-contained, requiring no external services or API keys, making it perfect for affiliate marketing, link tracking, and creating memorable short URLs for your content.

### Plugin Information

- **Plugin Name:** LINO: Links IN / Links Out
- **Version:** 1.0.0
- **Author:** Enrico Murru
- **License:** All rights reserved
- **Requires:** WordPress 5.0+ (for Gutenberg block support)
- **PHP Version:** 7.0+

---

## Core Features

### 1. Custom URL Routing

The plugin creates a custom WordPress rewrite rule that enables short links in the format:

```
https://your-domain.com/go/{slug}
```

- **Slug Format:** Alphanumeric characters (a-z, A-Z, 0-9) and underscores (_)
- **Slug Length:** 1-50 characters
- **Auto-generation:** If no slug is provided, the plugin automatically generates a unique 6-character random slug

### 2. Dual Redirect Modes

#### Backend Mode (HTTP Redirect)
- **Type:** HTTP 302 redirect (temporary redirect)
- **Behavior:** Immediate server-side redirect with no user interface
- **Use Cases:** 
  - API integrations
  - Campaign tracking
  - Fast redirects without user interaction
  - Affiliate link management
- **Performance:** Fastest option, minimal server load

#### UI Mode (Countdown Page)
- **Type:** Custom page with countdown timer
- **Behavior:** Displays a customizable page before redirecting
- **Features:**
  - Configurable countdown timer (default: 10 seconds)
  - Custom title and description display
  - "Skip waiting" button for immediate redirect
  - Two template options:
    1. **Default Template:** Minimal black & white design, fully responsive
    2. **Custom Page:** Use any WordPress page with shortcode `[wp_sl_redirect]`

### 3. Click Statistics

- **Tracking Method:** Daily aggregated statistics
- **Storage:** Separate database table (`wp_sl_stats`)
- **Metrics Tracked:**
  - Total clicks per link
  - Last click date
  - Daily click counts
- **Display:** Statistics shown directly in the admin interface link table
- **Automatic:** Stats increment automatically on each visit (both modes)

### 4. Admin Management Interface

#### Links Tab
- **Add/Edit Form:** Two-column responsive layout
- **Link Management:**
  - Create new links
  - Edit existing links
  - Enable/disable links individually
  - Delete links (with confirmation)
  - Bulk operations (delete, enable, disable)
- **Link Table Features:**
  - Sortable columns (title, slug, URL, mode, active status, clicks, dates)
  - Real-time search/filter functionality
  - Checkbox selection for bulk actions
  - Direct links to short URLs and target URLs
  - Visual indicators for active/inactive status
- **Export/Import:**
  - Export all links to CSV format
  - Import links from CSV with duplicate handling
  - UTF-8 encoding with BOM for Excel compatibility

#### Settings Tab
- **UI Countdown Seconds:** Global setting for countdown duration (minimum: 1 second)
- **Custom UI Page:** Select a WordPress page to use as custom redirect template
- **Shortcode Integration:** Use `[wp_sl_redirect]` in custom pages

### 5. Gutenberg Block Integration

- **Block Name:** LINO Link (`wp-sl/short-link`)
- **Category:** Common blocks
- **Features:**
  - Dropdown selector of all active links
  - Displays link title and slug for easy identification
  - Optional custom label for link text
  - Server-side rendering (PHP) for optimal performance
  - Real-time preview in editor
- **Usage:** Insert block → Select link → Optionally customize label → Publish

### 6. Database Architecture

The plugin creates two custom database tables:

#### `wp_sl_links` Table
Stores all short link definitions.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | BIGINT(20) UNSIGNED | PRIMARY KEY, AUTO_INCREMENT | Unique link identifier |
| `slug` | VARCHAR(50) | UNIQUE, NOT NULL | Short link slug (1-50 chars) |
| `target_url` | TEXT | NOT NULL | Full destination URL |
| `title` | VARCHAR(255) | NULL | Optional display title |
| `description` | TEXT | NULL | Optional description text |
| `mode` | VARCHAR(10) | NOT NULL, DEFAULT 'backend' | Redirect mode: 'backend' or 'ui' |
| `is_active` | TINYINT(1) | NOT NULL, DEFAULT 1 | Active status (1 = active, 0 = inactive) |
| `created_at` | DATETIME | NOT NULL | Creation timestamp |
| `updated_at` | DATETIME | NOT NULL | Last update timestamp |
| `created_by` | BIGINT(20) UNSIGNED | NULL | WordPress user ID of creator |

#### `wp_sl_stats` Table
Stores daily aggregated click statistics.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | BIGINT(20) UNSIGNED | PRIMARY KEY, AUTO_INCREMENT | Unique stat record ID |
| `link_id` | BIGINT(20) UNSIGNED | NOT NULL | Foreign key to wp_sl_links.id |
| `stat_date` | DATE | NOT NULL | Date of clicks (YYYY-MM-DD) |
| `clicks` | BIGINT(20) UNSIGNED | NOT NULL, DEFAULT 0 | Number of clicks for this date |
| **Composite Key** | `(link_id, stat_date)` | UNIQUE | Ensures one record per link per day |

**Statistics Aggregation:**
- Stats are automatically incremented on each link visit
- Uses INSERT ... ON DUPLICATE KEY UPDATE pattern (via UPDATE then INSERT fallback)
- Aggregated by date to minimize database size
- Old statistics are preserved indefinitely (no automatic cleanup)

---

## Installation & Setup

### Step 1: Install the Plugin

1. Download the plugin ZIP file
2. Navigate to **WordPress Admin → Plugins → Add New → Upload Plugin**
3. Upload the ZIP file and click **Install Now**
4. Click **Activate Plugin**

### Step 2: Flush Permalinks (Required)

The plugin registers a custom rewrite rule (`/go/{slug}`). WordPress must flush rewrite rules for it to work.

**Method 1: Via Admin**
1. Go to **Settings → Permalinks**
2. Click **Save Changes** (no changes needed, just save)

**Method 2: Via Code**
Add this to `wp-config.php` temporarily:
```php
define('WP_SHORT_LINKS_FLUSH_REWRITES', true);
```

### Step 3: Verify Installation

1. Navigate to **LINO** in the WordPress admin menu
2. Create a test link:
   - Leave slug empty (auto-generate) or enter a custom slug
   - Enter a target URL (e.g., `https://example.com`)
   - Select mode (Backend or UI)
   - Click **Create Link**
3. Test the short link by visiting: `https://your-domain.com/go/{slug}`

---

## Usage Guide

### Creating a Short Link

1. **Navigate to:** LINO → Links
2. **Fill in the form:**
   - **Title** (optional): Display title for UI mode
   - **Slug** (optional): Custom slug or leave blank for auto-generation
   - **Full URL** (required): Target destination URL
   - **Mode:** Select Backend or UI
   - **Active:** Check to enable the link
   - **Description** (optional): Text shown in UI mode
3. **Click:** Create Link

### Editing a Link

1. Find the link in the **All Links** table
2. Click **Edit** in the Actions column
3. Modify fields as needed
4. Click **Update Link**

### Enabling/Disabling Links

**Individual:**
- Click **Enable** or **Disable** in the Actions column

**Bulk:**
1. Check the boxes next to links you want to modify
2. Use the bulk action buttons that appear:
   - **Bulk Enable**
   - **Bulk Disable**
   - **Bulk Delete**

### Using the Gutenberg Block

1. **Open a post/page** in the block editor
2. **Click the + button** to add a block
3. **Search for:** "LINO Link" or "wp-sl"
4. **Select the block**
5. **In the sidebar:**
   - Choose a link from the dropdown
   - Optionally enter a custom label
6. **Publish** the post/page

The block will render as a clickable link to your short URL.

### Exporting Links

1. Go to **LINO → Links**
2. Click **Export CSV** button
3. A CSV file will download with all link data
4. File format: UTF-8 with BOM (Excel-compatible)

**CSV Columns:**
- slug
- target_url
- title
- description
- mode
- is_active
- created_at
- updated_at

### Importing Links

1. Go to **LINO → Links**
2. Click **Import CSV**
3. **Upload your CSV file:**
   - Must match export format
   - Header row is optional
   - Minimum required: slug, target_url
4. **Select Import Mode:**
   - **Skip duplicates:** Keep existing links, only import new ones
   - **Update duplicates:** Overwrite existing links with same slug
5. Click **Import Links**
6. Review results: imported, updated, skipped counts

**Import Validation:**
- Invalid slugs are skipped
- Invalid URLs are skipped
- Missing required fields are skipped
- Errors are logged but don't stop the import

### Setting Up Custom UI Page

1. **Create a WordPress page:**
   - Go to **Pages → Add New**
   - Title it (e.g., "Redirect Page")
   - Add content around the shortcode:
     ```
     Welcome! You're being redirected.
     
     [wp_sl_redirect]
     
     Thank you for your patience.
     ```
   - Publish the page

2. **Configure in plugin:**
   - Go to **LINO → Settings**
   - Select your custom page from the dropdown
   - Set countdown seconds
   - Click **Save Settings**

3. **Customize the page:**
   - Use page builders (Elementor, Gutenberg, etc.)
   - Add images, branding, custom CSS
   - The shortcode handles all redirect functionality

---

## Technical Details

### Rewrite Rules

The plugin registers a WordPress rewrite rule:

```php
'^go/([A-Za-z0-9_]{1,50})/?$' => 'index.php?lnk_slug=$matches[1]'
```

- **Priority:** Top (takes precedence over other rules)
- **Query Var:** `lnk_slug` (registered via `query_vars` filter)
- **Handler:** `template_redirect` action hook

### Request Flow

1. **User visits:** `/go/{slug}`
2. **WordPress routing:** Matches rewrite rule, sets `lnk_slug` query var
3. **Plugin intercepts:** `handle_shortlink_request()` method
4. **Database lookup:** Queries `wp_sl_links` table by slug
5. **Validation:**
   - Link exists?
   - Link is active?
   - If no: Return 404
6. **Statistics:** Increment click count for today
7. **Redirect:**
   - **Backend mode:** HTTP 302 redirect
   - **UI mode:** Render countdown page or redirect to custom page

### Security Features

- **Nonce verification:** All admin form submissions use WordPress nonces
- **Capability checks:** Requires `manage_options` capability
- **SQL injection protection:** All queries use `$wpdb->prepare()`
- **XSS protection:** All output uses `esc_html()`, `esc_url()`, `esc_attr()`
- **CSRF protection:** WordPress nonces on all forms
- **Input sanitization:** All user input is sanitized before database operations
- **URL validation:** Target URLs validated with `esc_url_raw()`

### Performance Considerations

- **Database queries:** Optimized with proper indexes (slug is unique, link_id + stat_date is unique)
- **Statistics aggregation:** Daily aggregation minimizes table size
- **Caching:** No caching implemented (by design for accurate statistics)
- **Rewrite rules:** Flushed only on activation/deactivation
- **Block rendering:** Server-side PHP rendering (no client-side JavaScript required)

### Hooks & Filters

The plugin uses standard WordPress hooks:

**Actions:**
- `init` - Register rewrite rules and block
- `admin_menu` - Add admin menu
- `admin_head` - Add admin styles
- `template_redirect` - Handle shortlink requests
- `enqueue_block_editor_assets` - Localize block data
- `admin_post_*` - Handle form submissions

**Filters:**
- `query_vars` - Register `lnk_slug` query variable

**Shortcodes:**
- `[wp_sl_redirect]` - Display redirect UI in custom pages

### File Structure

```
wp-short-links/
├── wp-short-links.php          # Main plugin file
├── assets/
│   ├── images/
│   │   ├── icon.png            # Admin menu icon (fallback)
│   │   └── icon2.png           # Admin menu icon (primary)
│   └── js/
│       └── lnk-block.js        # Gutenberg block JavaScript
├── LICENSE                     # License file
└── README.md                   # User-facing documentation
```

### Class Structure

**Main Class:** `WP_Short_Links_Plugin`

**Key Methods:**
- `instance()` - Singleton pattern
- `register_rewrite()` - Register URL rewrite rules
- `handle_shortlink_request()` - Process shortlink requests
- `increment_stats()` - Update click statistics
- `render_ui_page()` - Display countdown page
- `register_admin_menu()` - Add admin interface
- `render_admin_page()` - Display admin UI
- `handle_save_link()` - Save/update links
- `handle_export_links()` - Export CSV
- `handle_import_links()` - Import CSV
- `register_block()` - Register Gutenberg block
- `render_block()` - Server-side block rendering
- `shortcode_redirect_ui()` - Shortcode handler

---

## Configuration Options

### WordPress Options

The plugin stores settings in WordPress options table:

| Option Name | Default | Description |
|-------------|---------|-------------|
| `wp_sl_countdown_seconds` | 10 | Countdown duration for UI mode (seconds) |
| `wp_sl_ui_page_id` | 0 | WordPress page ID for custom UI template (0 = use default) |

### Constants

| Constant | Description |
|----------|-------------|
| `WP_SHORT_LINKS_VERSION` | Plugin version (1.0.0) |

---

## Troubleshooting

### Short Links Return 404

**Problem:** Visiting `/go/{slug}` shows 404 page.

**Solutions:**
1. **Flush permalinks:** Go to Settings → Permalinks → Save Changes
2. **Check link status:** Ensure link is active in admin
3. **Verify slug:** Check for typos in the slug
4. **Check rewrite rules:** Ensure no other plugin conflicts

### Statistics Not Updating

**Problem:** Click counts remain at zero.

**Solutions:**
1. **Check database:** Verify `wp_sl_stats` table exists
2. **Check link mode:** Statistics update in both backend and UI modes
3. **Check database permissions:** Ensure WordPress can write to database
4. **Clear cache:** If using caching plugins, clear cache

### Gutenberg Block Not Appearing

**Problem:** LINO Link block not in block inserter.

**Solutions:**
1. **Check WordPress version:** Requires WordPress 5.0+
2. **Check JavaScript file:** Verify `assets/js/lnk-block.js` exists
3. **Check browser console:** Look for JavaScript errors
4. **Clear cache:** Clear browser and WordPress cache

### Import CSV Fails

**Problem:** CSV import shows errors or doesn't import.

**Solutions:**
1. **Check file format:** Must be CSV, UTF-8 encoded
2. **Check required columns:** slug and target_url are required
3. **Check slug format:** Only a-z, A-Z, 0-9, _ allowed
4. **Check file size:** Large files may timeout (increase PHP limits)
5. **Check permissions:** Ensure WordPress can read uploaded files

### Custom UI Page Not Working

**Problem:** Custom page doesn't show redirect UI.

**Solutions:**
1. **Check shortcode:** Ensure `[wp_sl_redirect]` is in page content
2. **Check settings:** Verify page is selected in LINO → Settings
3. **Check link mode:** Link must be set to "UI mode"
4. **Check page status:** Page must be published
5. **Check theme:** Some themes may interfere with shortcode output

---

## Development Notes

### Extending the Plugin

**Adding Custom Fields:**
1. Modify `wp_sl_links` table structure (add column)
2. Update `handle_save_link()` to process new field
3. Update `render_admin_page()` to display/edit field
4. Update export/import functions to include field

**Custom Redirect Logic:**
Hook into `template_redirect` with higher priority, or modify `handle_shortlink_request()`.

**Custom Statistics:**
Extend `increment_stats()` to track additional metrics, or create new stats table.

### Database Migrations

The plugin uses `dbDelta()` for table creation. To modify tables:

1. Update table structure in `activate()` and `create_tables()`
2. Increment plugin version
3. Create migration script if needed

### Uninstall Behavior

**Current:** Plugin does NOT delete tables on uninstall.

**To add uninstall cleanup:**
1. Create `uninstall.php` file
2. Add cleanup code:
```php
global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sl_links");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sl_stats");
delete_option('wp_sl_countdown_seconds');
delete_option('wp_sl_ui_page_id');
```

### Testing Checklist

- [ ] Create link with auto-generated slug
- [ ] Create link with custom slug
- [ ] Test backend mode redirect (302)
- [ ] Test UI mode with default template
- [ ] Test UI mode with custom page
- [ ] Verify statistics increment
- [ ] Test link enable/disable
- [ ] Test link deletion
- [ ] Test bulk operations
- [ ] Test CSV export
- [ ] Test CSV import (skip mode)
- [ ] Test CSV import (update mode)
- [ ] Test Gutenberg block insertion
- [ ] Test Gutenberg block rendering
- [ ] Test inactive link returns 404
- [ ] Test invalid slug returns 404
- [ ] Test permalink flush requirement
- [ ] Test on different WordPress versions
- [ ] Test with different themes
- [ ] Test with page builders

---

## API Reference

### Public Methods (for developers)

While the plugin doesn't expose a public API, these methods could be used by other plugins:

**Get Link by Slug:**
```php
global $wpdb;
$link = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}sl_links WHERE slug = %s AND is_active = 1",
    $slug
));
```

**Get Link Statistics:**
```php
global $wpdb;
$stats = $wpdb->get_results($wpdb->prepare(
    "SELECT stat_date, clicks FROM {$wpdb->prefix}sl_stats WHERE link_id = %d ORDER BY stat_date DESC",
    $link_id
));
```

**Get Total Clicks:**
```php
global $wpdb;
$total = $wpdb->get_var($wpdb->prepare(
    "SELECT SUM(clicks) FROM {$wpdb->prefix}sl_stats WHERE link_id = %d",
    $link_id
));
```

---

## Version History

### 1.0.0 (Current)
- Initial release
- Custom `/go/{slug}` routing
- Backend and UI redirect modes
- Daily click statistics
- Admin management interface
- CSV export/import
- Gutenberg block integration
- Custom UI page support
- Bulk operations
- Search and sort functionality

---

## Support & Credits

**Author:** Enrico Murru  
**Portfolio:** https://portfolio.organizer.solutions

For issues, feature requests, or contributions, please refer to the plugin repository or contact the author.

---

## License

All rights reserved. This plugin is proprietary software.

---

## Appendix: CSV Import Format Example

```csv
slug,target_url,title,description,mode,is_active,created_at,updated_at
example1,https://example.com/page1,Example Link 1,This is an example link,backend,1,2024-01-01 00:00:00,2024-01-01 00:00:00
example2,https://example.com/page2,Example Link 2,Another example,ui,1,2024-01-01 00:00:00,2024-01-01 00:00:00
campaign_2024,https://example.com/campaign,2024 Campaign,Special campaign link,backend,1,2024-01-01 00:00:00,2024-01-01 00:00:00
```

**Notes:**
- Header row is optional
- Order of columns doesn't matter if header is present
- If no header, order must match: slug, target_url, title, description, mode, is_active, created_at, updated_at
- Dates can be empty (will use current timestamp)
- Mode must be 'backend' or 'ui'
- is_active can be 1, 0, 'yes', 'no', 'true', 'false'

---

*End of Documentation*

