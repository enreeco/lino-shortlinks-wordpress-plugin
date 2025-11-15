<?php
/**
 * Plugin Name: LINO: Links IN / Links Out
 * Description: Simple Bitly-style short links with /go/slug routing, UI/backend modes, stats, and a Gutenberg block.
 * Version: 1.0.0
 * Author: Enrico Murru
 * License: All rights reserved.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin version - update this when releasing a new version
define( 'WP_SHORT_LINKS_VERSION', '1.0.0' );

class WP_Short_Links_Plugin {

    const VERSION          = WP_SHORT_LINKS_VERSION;
    const OPTION_COUNTDOWN = 'wp_sl_countdown_seconds';
    const OPTION_UI_PAGE   = 'wp_sl_ui_page_id';

    private static $instance = null;
    private $table_links;
    private $table_stats;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_links = $wpdb->prefix . 'sl_links';
        $this->table_stats = $wpdb->prefix . 'sl_stats';

        add_action( 'init', array( $this, 'register_rewrite' ) );
        add_filter( 'query_vars', array( $this, 'register_query_var' ) );
        add_action( 'template_redirect', array( $this, 'handle_shortlink_request' ) );

        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'admin_head', array( $this, 'admin_menu_icon_styles' ) );
        add_action( 'admin_post_wp_sl_save_link', array( $this, 'handle_save_link' ) );
        add_action( 'admin_post_wp_sl_delete_link', array( $this, 'handle_delete_link' ) );
        add_action( 'admin_post_wp_sl_toggle_link', array( $this, 'handle_toggle_link' ) );
        add_action( 'admin_post_wp_sl_save_settings', array( $this, 'handle_save_settings' ) );
        add_action( 'admin_post_wp_sl_export_links', array( $this, 'handle_export_links' ) );
        add_action( 'admin_post_wp_sl_import_links', array( $this, 'handle_import_links' ) );
        add_action( 'admin_post_wp_sl_bulk_delete', array( $this, 'handle_bulk_delete' ) );
        add_action( 'admin_post_wp_sl_bulk_enable', array( $this, 'handle_bulk_enable' ) );
        add_action( 'admin_post_wp_sl_bulk_disable', array( $this, 'handle_bulk_disable' ) );

        // Gutenberg block
        add_action( 'init', array( $this, 'register_block' ) );
        add_action( 'enqueue_block_editor_assets', array( $this, 'localize_block_data' ) );
        
        // Shortcode for custom UI page
        add_shortcode( 'wp_sl_redirect', array( $this, 'shortcode_redirect_ui' ) );
    }

    /* -------------------------------------------------------------------------
     * Activation / Deactivation
     * ---------------------------------------------------------------------- */

    public static function activate() {
        global $wpdb;
        
        // Create tables directly without instantiating the class
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $table_links = $wpdb->prefix . 'sl_links';
        $table_stats = $wpdb->prefix . 'sl_stats';

        $sql_links = "CREATE TABLE {$table_links} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(50) NOT NULL,
            target_url TEXT NOT NULL,
            title VARCHAR(255) NULL,
            description TEXT NULL,
            mode VARCHAR(10) NOT NULL DEFAULT 'backend',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            created_by BIGINT(20) UNSIGNED NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug)
        ) $charset_collate;";

        $sql_stats = "CREATE TABLE {$table_stats} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            link_id BIGINT(20) UNSIGNED NOT NULL,
            stat_date DATE NOT NULL,
            clicks BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY link_date (link_id, stat_date)
        ) $charset_collate;";

        dbDelta( $sql_links );
        dbDelta( $sql_stats );
        
        // Set default countdown if not exists
        if ( get_option( self::OPTION_COUNTDOWN ) === false ) {
            add_option( self::OPTION_COUNTDOWN, 10 ); // default 10 seconds
        }
        
        // Set default UI page if not exists
        if ( get_option( self::OPTION_UI_PAGE ) === false ) {
            add_option( self::OPTION_UI_PAGE, 0 ); // 0 means use default template
        }

        // Register rewrite rules (simple version for activation)
        add_rewrite_rule(
            '^go/([A-Za-z0-9_]{1,50})/?$',
            'index.php?lnk_slug=$matches[1]',
            'top'
        );
        
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    private function tables_exist() {
        global $wpdb;
        $links_table = $wpdb->get_var( "SHOW TABLES LIKE '{$this->table_links}'" );
        $stats_table = $wpdb->get_var( "SHOW TABLES LIKE '{$this->table_stats}'" );
        return ( $links_table === $this->table_links && $stats_table === $this->table_stats );
    }

    private function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $sql_links = "CREATE TABLE {$this->table_links} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(50) NOT NULL,
            target_url TEXT NOT NULL,
            title VARCHAR(255) NULL,
            description TEXT NULL,
            mode VARCHAR(10) NOT NULL DEFAULT 'backend', /* 'backend' or 'ui' */
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            created_by BIGINT(20) UNSIGNED NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug)
        ) $charset_collate;";

        $sql_stats = "CREATE TABLE {$this->table_stats} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            link_id BIGINT(20) UNSIGNED NOT NULL,
            stat_date DATE NOT NULL,
            clicks BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY link_date (link_id, stat_date)
        ) $charset_collate;";

        dbDelta( $sql_links );
        dbDelta( $sql_stats );
    }

    /* -------------------------------------------------------------------------
     * Rewrite / Routing
     * ---------------------------------------------------------------------- */

    public function register_rewrite() {
        // /go/abc123  (slug: 1-50 chars, a-zA-Z0-9_)
        add_rewrite_rule(
            '^go/([A-Za-z0-9_]{1,50})/?$',
            'index.php?lnk_slug=$matches[1]',
            'top'
        );
    }

    public function register_query_var( $vars ) {
        $vars[] = 'lnk_slug';
        return $vars;
    }

    public function handle_shortlink_request() {
        $slug = get_query_var( 'lnk_slug' );
        if ( empty( $slug ) ) {
            return;
        }

        global $wpdb;

        $link = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_links} WHERE slug = %s LIMIT 1",
                $slug
            )
        );

        if ( ! $link || ! $link->is_active ) {
            // Let WP handle 404
            status_header( 404 );
            nocache_headers();
            get_template_part( 404 );
            exit;
        }

        // Update stats before redirect/UI
        $this->increment_stats( $link->id );

        if ( $link->mode === 'backend' ) {
            // HTTP-level redirect (302 for safety)
            $target = $link->target_url;
            if ( ! empty( $target ) ) {
                // Ensure no output has been sent
                if ( ! headers_sent() ) {
                    wp_redirect( esc_url_raw( $target ), 302 );
                    exit;
                } else {
                    // Fallback: use meta refresh if headers already sent
                    echo '<meta http-equiv="refresh" content="0;url=' . esc_attr( esc_url_raw( $target ) ) . '">';
                    echo '<script>window.location.href="' . esc_js( esc_url_raw( $target ) ) . '";</script>';
                    exit;
                }
            }
        }

        // UI mode: show countdown page using theme header/footer
        $this->render_ui_page( $link );
        exit;
    }

    private function increment_stats( $link_id ) {
        global $wpdb;
        $today = current_time( 'Y-m-d' );

        // Try to update existing row
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table_stats}
                 SET clicks = clicks + 1
                 WHERE link_id = %d AND stat_date = %s",
                $link_id,
                $today
            )
        );

        if ( $updated === 0 ) {
            // Insert new row
            $wpdb->insert(
                $this->table_stats,
                array(
                    'link_id'   => $link_id,
                    'stat_date' => $today,
                    'clicks'    => 1,
                ),
                array( '%d', '%s', '%d' )
            );
        }
    }

    private function render_ui_page( $link ) {
        $ui_page_id = (int) get_option( self::OPTION_UI_PAGE, 0 );
        
        // If a custom page is configured, redirect to it with the link slug
        if ( $ui_page_id > 0 && get_post( $ui_page_id ) ) {
            $page_url = get_permalink( $ui_page_id );
            $redirect_url = add_query_arg( 'wp_sl_slug', $link->slug, $page_url );
            wp_redirect( $redirect_url );
            exit;
        }
        
        // Fallback to default template
        $seconds = (int) get_option( self::OPTION_COUNTDOWN, 10 );
        if ( $seconds <= 0 ) {
            $seconds = 10;
        }

        $title       = $link->title ?: __( 'Redirecting…', 'wp-short-links' );
        $description = $link->description;
        $target      = esc_url( $link->target_url );

        // Output minimal HTML with black & white centered styling
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html( $title ); ?></title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                html, body {
                    height: 100%;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                    background: #000000;
                    color: #ffffff;
                }
                .wp-sl-default-container {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    min-height: 100vh;
                    padding: 20px;
                    text-align: center;
                }
                .wp-sl-default-content {
                    max-width: 600px;
                    width: 100%;
                }
                .wp-sl-default-title {
                    font-size: 2.5em;
                    font-weight: 300;
                    margin-bottom: 30px;
                    color: #ffffff;
                    line-height: 1.2;
                }
                .wp-sl-default-description {
                    font-size: 1.1em;
                    line-height: 1.6;
                    margin-bottom: 40px;
                    color: #ffffff;
                    opacity: 0.9;
                }
                .wp-sl-default-countdown {
                    font-size: 1.5em;
                    margin-bottom: 40px;
                    color: #ffffff;
                }
                .wp-sl-default-countdown span {
                    font-weight: 600;
                    font-size: 1.2em;
                }
                .wp-sl-default-link {
                    margin-top: 20px;
                }
                .wp-sl-default-link a {
                    display: inline-block;
                    padding: 15px 40px;
                    background: #ffffff;
                    color: #000000;
                    text-decoration: none;
                    font-size: 1em;
                    font-weight: 500;
                    border: 2px solid #ffffff;
                    transition: all 0.3s ease;
                    text-transform: uppercase;
                    letter-spacing: 1px;
                }
                .wp-sl-default-link a:hover {
                    background: #000000;
                    color: #ffffff;
                    border-color: #ffffff;
                }
                @media (max-width: 600px) {
                    .wp-sl-default-title {
                        font-size: 2em;
                    }
                    .wp-sl-default-description {
                        font-size: 1em;
                    }
                    .wp-sl-default-countdown {
                        font-size: 1.2em;
                    }
                }
            </style>
        </head>
        <body>
            <div class="wp-sl-default-container">
                <div class="wp-sl-default-content">
                    <h1 class="wp-sl-default-title"><?php echo esc_html( $title ); ?></h1>
                    
                    <?php if ( $description ) : ?>
                        <div class="wp-sl-default-description">
                            <?php echo wp_kses_post( wpautop( $description ) ); ?>
                        </div>
                    <?php endif; ?>

                    <div class="wp-sl-default-countdown">
                        <?php
                        printf(
                            esc_html__( 'You will be redirected in %s seconds…', 'wp-short-links' ),
                            '<span id="wp-sl-countdown">' . esc_html( $seconds ) . '</span>'
                        );
                        ?>
                    </div>

                    <div class="wp-sl-default-link">
                        <a id="wp-sl-direct-link" href="<?php echo esc_url( $target ); ?>">
                            <?php esc_html_e( 'Skip waiting and go now', 'wp-short-links' ); ?>
                        </a>
                    </div>
                </div>
            </div>

            <script>
                (function() {
                    var seconds = <?php echo (int) $seconds; ?>;
                    var span    = document.getElementById('wp-sl-countdown');
                    var link    = document.getElementById('wp-sl-direct-link');

                    function tick() {
                        seconds--;
                        if (span) {
                            span.textContent = seconds;
                        }
                        if (seconds <= 0) {
                            if (link) {
                                window.location.href = link.href;
                            }
                        } else {
                            window.setTimeout(tick, 1000);
                        }
                    }
                    window.setTimeout(tick, 1000);
                })();
            </script>
        </body>
        </html>
        <?php
        exit;
    }

    /* -------------------------------------------------------------------------
     * Admin UI
     * ---------------------------------------------------------------------- */

    public function register_admin_menu() {
        // Check if icon exists, use dashicons if not
        $icon_url = plugins_url( 'assets/images/icon2.png', __FILE__ );
        $icon_path = plugin_dir_path( __FILE__ ) . 'assets/images/icon2.png';
        
        // Use dashicons if custom icon doesn't exist
        if ( ! file_exists( $icon_path ) ) {
            $icon_url = 'dashicons-admin-links';
        }
        
        add_menu_page(
            __( 'LINO: Links IN / Links Out', 'wp-short-links' ),
            __( 'LINO', 'wp-short-links' ),
            'manage_options',
            'wp-short-links',
            array( $this, 'render_admin_page' ),
            $icon_url,
            58
        );
    }

    public function admin_menu_icon_styles() {
        ?>
        <style>
            /* Fix admin menu icon alignment for LINO plugin */
            #adminmenu #toplevel_page_wp-short-links .wp-menu-image {
                width: 36px !important;
                height: 33px !important;
                line-height: 33px !important;
                text-align: center !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                padding: 0 !important;
                margin: 0 !important;
                float: left !important;
            }
            
            #adminmenu #toplevel_page_wp-short-links .wp-menu-image img {
                width: 20px !important;
                height: 20px !important;
                padding: 0 !important;
                margin: 0 auto !important;
                display: block !important;
                vertical-align: middle !important;
            }
            
            /* Ensure proper alignment when menu item is hovered or active */
            #adminmenu #toplevel_page_wp-short-links:hover .wp-menu-image,
            #adminmenu #toplevel_page_wp-short-links.current .wp-menu-image,
            #adminmenu #toplevel_page_wp-short-links.wp-has-current-submenu .wp-menu-image {
                width: 36px !important;
                height: 33px !important;
                line-height: 33px !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
            }
            
            #adminmenu #toplevel_page_wp-short-links:hover .wp-menu-image img,
            #adminmenu #toplevel_page_wp-short-links.current .wp-menu-image img,
            #adminmenu #toplevel_page_wp-short-links.wp-has-current-submenu .wp-menu-image img {
                width: 20px !important;
                height: 20px !important;
                margin: 0 auto !important;
            }
        </style>
        <?php
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have permission to access this page.', 'wp-short-links' ) );
        }

        global $wpdb;
        
        // Ensure tables exist (safety check)
        if ( ! $this->tables_exist() ) {
            $this->create_tables();
        }

        $editing = false;
        $link    = null;

        if ( isset( $_GET['action'], $_GET['id'] ) && $_GET['action'] === 'edit' ) {
            $editing = true;
            $id      = (int) $_GET['id'];
            $link    = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$this->table_links} WHERE id = %d LIMIT 1",
                    $id
                )
            );
        }

        // Fetch all links with basic stats
        $links = $wpdb->get_results(
            "SELECT l.*,
                COALESCE(SUM(s.clicks),0) AS total_clicks,
                MAX(s.stat_date) AS last_click_date
             FROM {$this->table_links} l
             LEFT JOIN {$this->table_stats} s ON l.id = s.link_id
             GROUP BY l.id
             ORDER BY l.created_at DESC"
        );

        $countdown = (int) get_option( self::OPTION_COUNTDOWN, 10 );

        // Show import results if available
        $imported = isset( $_GET['imported'] ) ? (int) $_GET['imported'] : 0;
        $updated = isset( $_GET['updated'] ) ? (int) $_GET['updated'] : 0;
        $skipped = isset( $_GET['skipped'] ) ? (int) $_GET['skipped'] : 0;

        // Get current tab
        $current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'links';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'LINO: Links IN / Links Out', 'wp-short-links' ); ?></h1>
            
            <p class="description" style="margin-top: 10px; margin-bottom: 15px; max-width: 800px;">
                <?php esc_html_e( 'LINO (Links IN / Links Out) is a simple and powerful short link management plugin for WordPress. Create Bitly-style short links with custom slugs, track click statistics, and manage redirects with both backend HTTP redirects and customizable UI countdown pages. Perfect for affiliate marketing, link tracking, and creating memorable short URLs for your content.', 'wp-short-links' ); ?>
            </p>
            
            <p style="margin-top: 10px; margin-bottom: 20px; color: #666; font-size: 13px;">
                <?php
                echo esc_html__( 'Created by ', 'wp-short-links' );
                ?>
                <a href="https://portfolio.organizer.solutions" target="_blank" rel="noopener noreferrer" style="text-decoration: none; color: #2271b1;">
                    <?php esc_html_e( 'Enrico Murru', 'wp-short-links' ); ?>
                </a>
            </p>

            <?php if ( $imported > 0 || $updated > 0 || $skipped > 0 ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php
                        $messages = array();
                        if ( $imported > 0 ) {
                            $messages[] = sprintf( _n( '%d link imported', '%d links imported', $imported, 'wp-short-links' ), $imported );
                        }
                        if ( $updated > 0 ) {
                            $messages[] = sprintf( _n( '%d link updated', '%d links updated', $updated, 'wp-short-links' ), $updated );
                        }
                        if ( $skipped > 0 ) {
                            $messages[] = sprintf( _n( '%d link skipped', '%d links skipped', $skipped, 'wp-short-links' ), $skipped );
                        }
                        echo esc_html( implode( ', ', $messages ) . '.' );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper wp-clearfix" style="margin-bottom: 20px;">
                <a href="<?php echo esc_url( add_query_arg( array( 'page' => 'wp-short-links', 'tab' => 'links' ), admin_url( 'admin.php' ) ) ); ?>" class="nav-tab <?php echo $current_tab === 'links' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Links', 'wp-short-links' ); ?>
                </a>
                <a href="<?php echo esc_url( add_query_arg( array( 'page' => 'wp-short-links', 'tab' => 'settings' ), admin_url( 'admin.php' ) ) ); ?>" class="nav-tab <?php echo $current_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Settings', 'wp-short-links' ); ?>
                </a>
            </nav>

            <?php if ( $current_tab === 'links' ) : ?>
                <style>
                    .wp-sl-form-two-columns {
                        display: grid;
                        grid-template-columns: 1fr 1fr;
                        gap: 20px;
                        margin-bottom: 30px;
                    }
                    .wp-sl-form-field {
                        margin-bottom: 0;
                    }
                    .wp-sl-form-field-full {
                        grid-column: 1 / -1;
                    }
                    @media (max-width: 1200px) {
                        .wp-sl-form-two-columns {
                            grid-template-columns: 1fr;
                        }
                    }
                    .wp-sl-links-section {
                        margin-top: 30px;
                    }
                </style>
                
                <!-- Add/Edit Link Form -->
                <div class="wp-sl-form-section">
                    <h2><?php echo $editing ? esc_html__( 'Edit Link', 'wp-short-links' ) : esc_html__( 'Add New Link', 'wp-short-links' ); ?></h2>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'wp_sl_save_link', 'wp_sl_nonce' ); ?>
                        <input type="hidden" name="action" value="wp_sl_save_link">
                        <?php if ( $editing && $link ) : ?>
                            <input type="hidden" name="id" value="<?php echo (int) $link->id; ?>">
                        <?php endif; ?>

                        <div class="wp-sl-form-two-columns">
                            <div class="wp-sl-form-field">
                                <label for="wp_sl_title"><strong><?php esc_html_e( 'Title (optional)', 'wp-short-links' ); ?></strong></label>
                                <input name="title" type="text" id="wp_sl_title" value="<?php echo esc_attr( $link ? $link->title : '' ); ?>" class="regular-text" style="width: 100%;">
                            </div>

                            <div class="wp-sl-form-field">
                                <label for="wp_sl_slug"><strong><?php esc_html_e( 'Slug', 'wp-short-links' ); ?></strong></label>
                                <input name="slug" type="text" id="wp_sl_slug" value="<?php echo esc_attr( $link ? $link->slug : '' ); ?>" class="regular-text" maxlength="50" style="width: 100%;">
                                <p class="description" style="margin-top: 5px;">
                                    <?php esc_html_e( 'Optional. Leave empty to generate a random 6-character slug.', 'wp-short-links' ); ?>
                                </p>
                            </div>

                            <div class="wp-sl-form-field wp-sl-form-field-full">
                                <label for="wp_sl_target_url"><strong><?php esc_html_e( 'Full URL (target)', 'wp-short-links' ); ?></strong></label>
                                <input name="target_url" type="url" id="wp_sl_target_url" value="<?php echo esc_attr( $link ? $link->target_url : '' ); ?>" class="regular-text" required style="width: 100%;">
                                <p class="description" style="margin-top: 5px;">
                                    <?php esc_html_e( 'The URL users will be redirected to.', 'wp-short-links' ); ?>
                                </p>
                            </div>

                            <div class="wp-sl-form-field">
                                <label><strong><?php esc_html_e( 'Mode', 'wp-short-links' ); ?></strong></label>
                                <?php $mode = $link ? $link->mode : 'backend'; ?>
                                <fieldset style="margin-top: 5px;">
                                    <label style="display: block; margin-bottom: 8px;">
                                        <input type="radio" name="mode" value="backend" <?php checked( $mode, 'backend' ); ?>>
                                        <?php esc_html_e( 'Backend (HTTP redirect, no UI)', 'wp-short-links' ); ?>
                                    </label>
                                    <label style="display: block;">
                                        <input type="radio" name="mode" value="ui" <?php checked( $mode, 'ui' ); ?>>
                                        <?php esc_html_e( 'UI mode (countdown page, then redirect)', 'wp-short-links' ); ?>
                                    </label>
                                </fieldset>
                            </div>

                            <div class="wp-sl-form-field">
                                <label><strong><?php esc_html_e( 'Active', 'wp-short-links' ); ?></strong></label>
                                <?php $is_active = $link ? (int) $link->is_active : 1; ?>
                                <label style="display: block; margin-top: 8px;">
                                    <input type="checkbox" name="is_active" value="1" <?php checked( $is_active, 1 ); ?>>
                                    <?php esc_html_e( 'Link is active and can be used', 'wp-short-links' ); ?>
                                </label>
                            </div>

                            <div class="wp-sl-form-field wp-sl-form-field-full">
                                <label for="wp_sl_description"><strong><?php esc_html_e( 'Description (optional)', 'wp-short-links' ); ?></strong></label>
                                <textarea name="description" id="wp_sl_description" rows="3" class="large-text" style="width: 100%;"><?php echo esc_textarea( $link ? $link->description : '' ); ?></textarea>
                            </div>
                        </div>

                        <?php submit_button( $editing ? __( 'Update Link', 'wp-short-links' ) : __( 'Create Link', 'wp-short-links' ) ); ?>
                    </form>
                </div>

                <!-- All Links Table (Full Width) -->
                <div class="wp-sl-links-section">
                    <h2><?php esc_html_e( 'All Links', 'wp-short-links' ); ?></h2>

            <div style="margin-bottom: 20px;">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin-right: 10px;">
                    <?php wp_nonce_field( 'wp_sl_export_links', 'wp_sl_export_nonce' ); ?>
                    <input type="hidden" name="action" value="wp_sl_export_links">
                    <?php submit_button( __( 'Export CSV', 'wp-short-links' ), 'secondary', 'export', false ); ?>
                </form>

                <button type="button" class="button" onclick="document.getElementById('wp-sl-import-form').style.display = document.getElementById('wp-sl-import-form').style.display === 'none' ? 'block' : 'none';">
                    <?php esc_html_e( 'Import CSV', 'wp-short-links' ); ?>
                </button>
            </div>

            <!-- Bulk Actions -->
            <div id="wp-sl-bulk-actions" style="margin-bottom: 20px; display: none;">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wp-sl-bulk-action-form">
                    <?php wp_nonce_field( 'wp_sl_bulk_action', 'wp_sl_bulk_nonce' ); ?>
                    <input type="hidden" name="action" id="wp-sl-bulk-action-type" value="">
                    <input type="hidden" name="link_ids" id="wp-sl-bulk-link-ids" value="">
                    <button type="button" class="button button-primary" id="wp-sl-bulk-delete-btn" style="display: none;" onclick="wpSlSubmitBulkAction('wp_sl_bulk_delete');">
                        <?php esc_html_e( 'Bulk Delete', 'wp-short-links' ); ?>
                    </button>
                    <button type="button" class="button button-primary" id="wp-sl-bulk-enable-btn" style="display: none;" onclick="wpSlSubmitBulkAction('wp_sl_bulk_enable');">
                        <?php esc_html_e( 'Bulk Enable', 'wp-short-links' ); ?>
                    </button>
                    <button type="button" class="button button-primary" id="wp-sl-bulk-disable-btn" style="display: none;" onclick="wpSlSubmitBulkAction('wp_sl_bulk_disable');">
                        <?php esc_html_e( 'Bulk Disable', 'wp-short-links' ); ?>
                    </button>
                    <button type="button" class="button" onclick="wpSlClearSelection();" style="margin-left: 10px;">
                        <?php esc_html_e( 'Clear Selection', 'wp-short-links' ); ?>
                    </button>
                </form>
            </div>

            <div id="wp-sl-import-form" style="display:none; margin-bottom: 20px; padding: 15px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h3><?php esc_html_e( 'Import Links from CSV', 'wp-short-links' ); ?></h3>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'wp_sl_import_links', 'wp_sl_import_nonce' ); ?>
                    <input type="hidden" name="action" value="wp_sl_import_links">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="wp_sl_csv_file"><?php esc_html_e( 'CSV File', 'wp-short-links' ); ?></label>
                            </th>
                            <td>
                                <input type="file" name="csv_file" id="wp_sl_csv_file" accept=".csv" required>
                                <p class="description">
                                    <?php esc_html_e( 'CSV format: slug, target_url, title, description, mode (backend/ui), is_active (1/0). Header row is optional.', 'wp-short-links' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="wp_sl_import_mode"><?php esc_html_e( 'Import Mode', 'wp-short-links' ); ?></label>
                            </th>
                            <td>
                                <fieldset>
                                    <label>
                                        <input type="radio" name="import_mode" value="skip" checked>
                                        <?php esc_html_e( 'Skip duplicates (keep existing)', 'wp-short-links' ); ?>
                                    </label><br>
                                    <label>
                                        <input type="radio" name="import_mode" value="update">
                                        <?php esc_html_e( 'Update duplicates (overwrite existing)', 'wp-short-links' ); ?>
                                    </label>
                                </fieldset>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( __( 'Import Links', 'wp-short-links' ) ); ?>
                </form>
            </div>

            <!-- Search Filter -->
            <div style="margin-bottom: 15px;">
                <label for="wp-sl-search-input" style="display: block; margin-bottom: 5px; font-weight: 600;">
                    <?php esc_html_e( 'Search Links', 'wp-short-links' ); ?>
                </label>
                <input type="text" id="wp-sl-search-input" placeholder="<?php esc_attr_e( 'Search by title, slug, URL, mode...', 'wp-short-links' ); ?>" style="width: 300px; max-width: 100%; padding: 5px;" onkeyup="wpSlFilterTable(this.value);">
                <button type="button" class="button" onclick="document.getElementById('wp-sl-search-input').value=''; wpSlFilterTable('');" style="margin-left: 5px;">
                    <?php esc_html_e( 'Clear', 'wp-short-links' ); ?>
                </button>
                <span id="wp-sl-search-results" style="margin-left: 10px; color: #666; font-style: italic;"></span>
            </div>

            <table class="wp-list-table widefat fixed striped" id="wp-sl-links-table">
                <thead>
                    <tr>
                        <td class="check-column">
                            <input type="checkbox" id="wp-sl-select-all" onclick="wpSlToggleAll(this);">
                        </td>
                        <th class="sortable" data-sort="title" onclick="wpSlSortTable(0, 'title');" style="cursor: pointer; user-select: none;">
                            <?php esc_html_e( 'Title', 'wp-short-links' ); ?>
                            <span class="sort-indicator"></span>
                        </th>
                        <th class="sortable" data-sort="slug" onclick="wpSlSortTable(1, 'slug');" style="cursor: pointer; user-select: none;">
                            <?php esc_html_e( 'Slug', 'wp-short-links' ); ?>
                            <span class="sort-indicator"></span>
                        </th>
                        <th class="sortable" data-sort="short_url" onclick="wpSlSortTable(2, 'short_url');" style="cursor: pointer; user-select: none;">
                            <?php esc_html_e( 'Short URL', 'wp-short-links' ); ?>
                            <span class="sort-indicator"></span>
                        </th>
                        <th class="sortable" data-sort="target_url" onclick="wpSlSortTable(3, 'target_url');" style="cursor: pointer; user-select: none;">
                            <?php esc_html_e( 'Target URL', 'wp-short-links' ); ?>
                            <span class="sort-indicator"></span>
                        </th>
                        <th class="sortable" data-sort="mode" onclick="wpSlSortTable(4, 'mode');" style="cursor: pointer; user-select: none;">
                            <?php esc_html_e( 'Mode', 'wp-short-links' ); ?>
                            <span class="sort-indicator"></span>
                        </th>
                        <th class="sortable" data-sort="active" onclick="wpSlSortTable(5, 'active');" style="cursor: pointer; user-select: none;">
                            <?php esc_html_e( 'Active', 'wp-short-links' ); ?>
                            <span class="sort-indicator"></span>
                        </th>
                        <th class="sortable" data-sort="clicks" onclick="wpSlSortTable(6, 'clicks');" style="cursor: pointer; user-select: none;">
                            <?php esc_html_e( 'Total Clicks', 'wp-short-links' ); ?>
                            <span class="sort-indicator"></span>
                        </th>
                        <th class="sortable" data-sort="last_click" onclick="wpSlSortTable(7, 'last_click');" style="cursor: pointer; user-select: none;">
                            <?php esc_html_e( 'Last Click Date', 'wp-short-links' ); ?>
                            <span class="sort-indicator"></span>
                        </th>
                        <th class="sortable" data-sort="created_date" onclick="wpSlSortTable(8, 'created_date');" style="cursor: pointer; user-select: none;">
                            <?php esc_html_e( 'Created Date', 'wp-short-links' ); ?>
                            <span class="sort-indicator"></span>
                        </th>
                        <th><?php esc_html_e( 'Actions', 'wp-short-links' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( $links ) : ?>
                        <?php foreach ( $links as $l ) : ?>
                            <?php
                            $short_url = home_url( '/go/' . $l->slug );
                            $title_sort = strtolower( trim( $l->title ? $l->title : '' ) );
                            $slug_sort = strtolower( trim( $l->slug ) );
                            $short_url_sort = strtolower( trim( $short_url ) );
                            $target_url_sort = strtolower( trim( $l->target_url ) );
                            $mode_sort = $l->mode === 'ui' ? 'ui' : 'backend';
                            $active_sort = $l->is_active ? '1' : '0';
                            $clicks_sort = (int) $l->total_clicks;
                            $last_click_sort = $l->last_click_date ? strtotime( $l->last_click_date ) : 0;
                            $created_date_sort = $l->created_at ? strtotime( $l->created_at ) : 0;
                            $created_date_display = $l->created_at ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $l->created_at ) ) : '—';
                            ?>
                            <tr>
                                <th scope="row" class="check-column">
                                    <input type="checkbox" name="link_ids[]" value="<?php echo (int) $l->id; ?>" class="wp-sl-link-checkbox" onchange="wpSlUpdateBulkActions();">
                                </th>
                                <td data-sort-value="<?php echo esc_attr( $title_sort ); ?>"><?php echo esc_html( $l->title ); ?></td>
                                <td data-sort-value="<?php echo esc_attr( $slug_sort ); ?>"><?php echo esc_html( $l->slug ); ?></td>
                                <td data-sort-value="<?php echo esc_attr( $short_url_sort ); ?>">
                                    <a href="<?php echo esc_url( $short_url ); ?>" target="_blank" rel="noopener">
                                        <?php echo esc_html( $short_url ); ?>
                                    </a>
                                </td>
                                <td class="column-primary" data-sort-value="<?php echo esc_attr( $target_url_sort ); ?>">
                                    <a href="<?php echo esc_url( $l->target_url ); ?>" target="_blank" rel="noopener">
                                        <?php echo esc_html( $l->target_url ); ?>
                                    </a>
                                </td>
                                <td data-sort-value="<?php echo esc_attr( $mode_sort ); ?>"><?php echo $l->mode === 'ui' ? esc_html__( 'UI', 'wp-short-links' ) : esc_html__( 'Backend', 'wp-short-links' ); ?></td>
                                <td data-sort-value="<?php echo esc_attr( $active_sort ); ?>"><?php echo $l->is_active ? esc_html__( 'Yes', 'wp-short-links' ) : esc_html__( 'No', 'wp-short-links' ); ?></td>
                                <td data-sort-value="<?php echo esc_attr( $clicks_sort ); ?>"><?php echo (int) $l->total_clicks; ?></td>
                                <td data-sort-value="<?php echo esc_attr( $last_click_sort ); ?>"><?php echo $l->last_click_date ? esc_html( $l->last_click_date ) : '—'; ?></td>
                                <td data-sort-value="<?php echo esc_attr( $created_date_sort ); ?>"><?php echo esc_html( $created_date_display ); ?></td>
                                <td>
                                    <?php
                                    $edit_url = add_query_arg(
                                        array(
                                            'page'   => 'wp-short-links',
                                            'tab'    => 'links',
                                            'action' => 'edit',
                                            'id'     => (int) $l->id,
                                        ),
                                        admin_url( 'admin.php' )
                                    );
                                    ?>
                                    <a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'wp-short-links' ); ?></a> |

                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                        <?php wp_nonce_field( 'wp_sl_toggle_link', 'wp_sl_toggle_nonce' ); ?>
                                        <input type="hidden" name="action" value="wp_sl_toggle_link">
                                        <input type="hidden" name="id" value="<?php echo (int) $l->id; ?>">
                                        <button type="submit" class="button-link">
                                            <?php echo $l->is_active ? esc_html__( 'Disable', 'wp-short-links' ) : esc_html__( 'Enable', 'wp-short-links' ); ?>
                                        </button>
                                    </form> |

                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete this link?', 'wp-short-links' ) ); ?>');">
                                        <?php wp_nonce_field( 'wp_sl_delete_link', 'wp_sl_delete_nonce' ); ?>
                                        <input type="hidden" name="action" value="wp_sl_delete_link">
                                        <input type="hidden" name="id" value="<?php echo (int) $l->id; ?>">
                                        <button type="submit" class="button-link delete-link">
                                            <?php esc_html_e( 'Delete', 'wp-short-links' ); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="11"><?php esc_html_e( 'No links found.', 'wp-short-links' ); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <script>
                function wpSlToggleAll(checkbox) {
                    // Only toggle visible checkboxes (respects search filter)
                    var table = document.getElementById('wp-sl-links-table');
                    var rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
                    
                    for (var i = 0; i < rows.length; i++) {
                        var row = rows[i];
                        // Skip hidden rows and the "No links found" row
                        if (row.style.display === 'none' || (row.cells.length === 1 && row.cells[0].colSpan > 1)) {
                            continue;
                        }
                        var rowCheckbox = row.querySelector('.wp-sl-link-checkbox');
                        if (rowCheckbox) {
                            rowCheckbox.checked = checkbox.checked;
                        }
                    }
                    wpSlUpdateBulkActions();
                }

                function wpSlUpdateBulkActions() {
                    var table = document.getElementById('wp-sl-links-table');
                    var rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
                    var bulkActionsDiv = document.getElementById('wp-sl-bulk-actions');
                    var selectAllCheckbox = document.getElementById('wp-sl-select-all');
                    
                    // Count visible checkboxes and checked ones
                    var visibleCheckboxes = [];
                    var checkedCheckboxes = [];
                    
                    for (var i = 0; i < rows.length; i++) {
                        var row = rows[i];
                        // Skip hidden rows and the "No links found" row
                        if (row.style.display === 'none' || (row.cells.length === 1 && row.cells[0].colSpan > 1)) {
                            continue;
                        }
                        var checkbox = row.querySelector('.wp-sl-link-checkbox');
                        if (checkbox) {
                            visibleCheckboxes.push(checkbox);
                            if (checkbox.checked) {
                                checkedCheckboxes.push(checkbox);
                            }
                        }
                    }
                    
                    if (checkedCheckboxes.length > 0) {
                        bulkActionsDiv.style.display = 'block';
                        
                        // Update select all checkbox state (only for visible checkboxes)
                        var allVisibleChecked = checkedCheckboxes.length === visibleCheckboxes.length && visibleCheckboxes.length > 0;
                        selectAllCheckbox.checked = allVisibleChecked;
                        selectAllCheckbox.indeterminate = !allVisibleChecked && checkedCheckboxes.length > 0;
                        
                        // Collect selected IDs
                        var ids = [];
                        checkedCheckboxes.forEach(function(cb) {
                            ids.push(cb.value);
                        });
                        document.getElementById('wp-sl-bulk-link-ids').value = ids.join(',');
                        
                        // Show all action buttons
                        document.getElementById('wp-sl-bulk-delete-btn').style.display = 'inline-block';
                        document.getElementById('wp-sl-bulk-enable-btn').style.display = 'inline-block';
                        document.getElementById('wp-sl-bulk-disable-btn').style.display = 'inline-block';
                    } else {
                        bulkActionsDiv.style.display = 'none';
                        selectAllCheckbox.checked = false;
                        selectAllCheckbox.indeterminate = false;
                    }
                }

                function wpSlClearSelection() {
                    var checkboxes = document.querySelectorAll('.wp-sl-link-checkbox');
                    checkboxes.forEach(function(cb) {
                        cb.checked = false;
                    });
                    document.getElementById('wp-sl-select-all').checked = false;
                    document.getElementById('wp-sl-select-all').indeterminate = false;
                    wpSlUpdateBulkActions();
                }

                function wpSlSubmitBulkAction(actionType) {
                    var linkIds = document.getElementById('wp-sl-bulk-link-ids').value;
                    var count = linkIds ? linkIds.split(',').length : 0;
                    
                    if (!linkIds || count === 0) {
                        alert('<?php echo esc_js( __( 'Please select at least one link.', 'wp-short-links' ) ); ?>');
                        return false;
                    }
                    
                    var message = '';
                    if (actionType === 'wp_sl_bulk_delete') {
                        message = '<?php echo esc_js( sprintf( __( 'Are you sure you want to delete %d link(s)? This action cannot be undone.', 'wp-short-links' ), '%d' ) ); ?>';
                        message = message.replace('%d', count);
                    } else if (actionType === 'wp_sl_bulk_enable') {
                        message = '<?php echo esc_js( sprintf( __( 'Are you sure you want to enable %d link(s)?', 'wp-short-links' ), '%d' ) ); ?>';
                        message = message.replace('%d', count);
                    } else if (actionType === 'wp_sl_bulk_disable') {
                        message = '<?php echo esc_js( sprintf( __( 'Are you sure you want to disable %d link(s)?', 'wp-short-links' ), '%d' ) ); ?>';
                        message = message.replace('%d', count);
                    }
                    
                    if (confirm(message)) {
                        document.getElementById('wp-sl-bulk-action-type').value = actionType;
                        document.getElementById('wp-sl-bulk-action-form').submit();
                    }
                }

                function wpSlFilterTable(searchText) {
                    var table = document.getElementById('wp-sl-links-table');
                    var rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
                    var searchLower = searchText.toLowerCase().trim();
                    var visibleCount = 0;
                    var totalCount = 0;
                    
                    for (var i = 0; i < rows.length; i++) {
                        var row = rows[i];
                        // Skip the "No links found" row
                        if (row.cells.length === 1 && row.cells[0].colSpan > 1) {
                            continue;
                        }
                        
                        totalCount++;
                        var rowText = '';
                        
                        // Collect text from all cells (skip checkbox and actions columns for search)
                        for (var j = 1; j < row.cells.length - 1; j++) {
                            var cell = row.cells[j];
                            // Skip the checkbox column (index 0) and actions column (last)
                            if (cell) {
                                rowText += ' ' + cell.textContent.toLowerCase();
                            }
                        }
                        
                        // Also check links in cells
                        var links = row.getElementsByTagName('a');
                        for (var k = 0; k < links.length; k++) {
                            rowText += ' ' + links[k].textContent.toLowerCase();
                            rowText += ' ' + (links[k].href || '').toLowerCase();
                        }
                        
                        if (searchLower === '' || rowText.indexOf(searchLower) !== -1) {
                            row.style.display = '';
                            visibleCount++;
                        } else {
                            row.style.display = 'none';
                        }
                    }
                    
                    // Update search results message
                    var resultsSpan = document.getElementById('wp-sl-search-results');
                    if (searchText.trim() === '') {
                        resultsSpan.textContent = '';
                    } else {
                        if (visibleCount === totalCount) {
                            resultsSpan.textContent = '';
                        } else {
                            resultsSpan.textContent = visibleCount + ' / ' + totalCount + ' <?php echo esc_js( __( 'links shown', 'wp-short-links' ) ); ?>';
                        }
                    }
                    
                    // Update bulk actions visibility if needed
                    wpSlUpdateBulkActions();
                }

                // Table sorting
                var wpSlCurrentSort = { column: null, direction: 'asc' };

                function wpSlSortTable(columnIndex, sortType) {
                    var table = document.getElementById('wp-sl-links-table');
                    var tbody = table.getElementsByTagName('tbody')[0];
                    var allRows = Array.from(tbody.getElementsByTagName('tr'));
                    
                    // Separate data rows from the "No links found" row
                    var dataRows = allRows.filter(function(row) {
                        return !(row.cells.length === 1 && row.cells[0].colSpan > 1);
                    });
                    
                    if (dataRows.length === 0) return;
                    
                    // Store visibility state on each row before sorting
                    dataRows.forEach(function(row) {
                        row._originalDisplay = row.style.display || '';
                    });
                    
                    // Determine sort direction
                    var isAscending = true;
                    if (wpSlCurrentSort.column === columnIndex) {
                        isAscending = wpSlCurrentSort.direction === 'desc';
                    }
                    wpSlCurrentSort.column = columnIndex;
                    wpSlCurrentSort.direction = isAscending ? 'asc' : 'desc';
                    
                    // Get the actual cell index (accounting for checkbox column)
                    var cellIndex = columnIndex + 1;
                    
                    // Sort rows
                    dataRows.sort(function(a, b) {
                        var aCell = a.cells[cellIndex];
                        var bCell = b.cells[cellIndex];
                        
                        if (!aCell || !bCell) return 0;
                        
                        var aValue = aCell.getAttribute('data-sort-value') || aCell.textContent.trim();
                        var bValue = bCell.getAttribute('data-sort-value') || bCell.textContent.trim();
                        
                        // Handle numeric sorting for clicks and dates
                        if (sortType === 'clicks' || sortType === 'last_click' || sortType === 'created_date') {
                            aValue = parseFloat(aValue) || 0;
                            bValue = parseFloat(bValue) || 0;
                            return isAscending ? aValue - bValue : bValue - aValue;
                        }
                        
                        // Handle text sorting
                        aValue = String(aValue).toLowerCase();
                        bValue = String(bValue).toLowerCase();
                        
                        if (aValue < bValue) {
                            return isAscending ? -1 : 1;
                        }
                        if (aValue > bValue) {
                            return isAscending ? 1 : -1;
                        }
                        return 0;
                    });
                    
                    // Re-append sorted rows and restore visibility
                    dataRows.forEach(function(row) {
                        if (row._originalDisplay !== undefined) {
                            row.style.display = row._originalDisplay;
                            delete row._originalDisplay;
                        }
                        tbody.appendChild(row);
                    });
                    
                    // Update sort indicators
                    wpSlUpdateSortIndicators(columnIndex);
                }

                function wpSlUpdateSortIndicators(activeColumnIndex) {
                    var headers = document.querySelectorAll('#wp-sl-links-table thead th.sortable');
                    headers.forEach(function(header, index) {
                        var indicator = header.querySelector('.sort-indicator');
                        if (indicator) {
                            if (index === activeColumnIndex) {
                                if (wpSlCurrentSort.direction === 'asc') {
                                    indicator.innerHTML = ' ↑';
                                    indicator.style.color = '#2271b1';
                                } else {
                                    indicator.innerHTML = ' ↓';
                                    indicator.style.color = '#2271b1';
                                }
                            } else {
                                indicator.innerHTML = ' ⇅';
                                indicator.style.color = '#999';
                            }
                        }
                    });
                }
            </script>
            <style>
                .sort-indicator {
                    font-size: 0.9em;
                    margin-left: 5px;
                    color: #999;
                    font-weight: normal;
                }
                th.sortable:hover {
                    background-color: #f0f0f1;
                }
                th.sortable:hover .sort-indicator {
                    color: #2271b1;
                }
            </style>
                </div>
            <?php elseif ( $current_tab === 'settings' ) : ?>
                <h2><?php esc_html_e( 'Settings', 'wp-short-links' ); ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'wp_sl_save_settings', 'wp_sl_settings_nonce' ); ?>
                    <input type="hidden" name="action" value="wp_sl_save_settings">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="wp_sl_countdown"><?php esc_html_e( 'UI countdown seconds', 'wp-short-links' ); ?></label></th>
                            <td>
                                <input name="countdown" type="number" id="wp_sl_countdown" min="1" value="<?php echo esc_attr( $countdown ); ?>" class="small-text">
                                <p class="description"><?php esc_html_e( 'Default delay (in seconds) before redirect in UI mode.', 'wp-short-links' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wp_sl_ui_page"><?php esc_html_e( 'Custom UI Page', 'wp-short-links' ); ?></label></th>
                            <td>
                                <?php
                                $ui_page_id = (int) get_option( self::OPTION_UI_PAGE, 0 );
                                wp_dropdown_pages( array(
                                    'name'             => 'ui_page_id',
                                    'id'               => 'wp_sl_ui_page',
                                    'selected'         => $ui_page_id,
                                    'show_option_none' => __( 'Use default template', 'wp-short-links' ),
                                    'option_none_value' => '0',
                                ) );
                                ?>
                                <p class="description">
                                    <?php esc_html_e( 'Select a page to use as the custom redirect page for UI mode. Leave empty to use the default template.', 'wp-short-links' ); ?>
                                    <br>
                                    <strong><?php esc_html_e( 'Tip:', 'wp-short-links' ); ?></strong>
                                    <?php esc_html_e( 'Add the shortcode [wp_sl_redirect] in your page content to display the redirect UI. You can customize the page design and add your own content around it.', 'wp-short-links' ); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( __( 'Save Settings', 'wp-short-links' ) ); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /* -------------------------------------------------------------------------
     * Admin form handlers
     * ---------------------------------------------------------------------- */

    public function handle_save_link() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized', 'wp-short-links' ) );
        }
        check_admin_referer( 'wp_sl_save_link', 'wp_sl_nonce' );

        global $wpdb;

        $id          = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        $title       = isset( $_POST['title'] ) ? sanitize_text_field( $_POST['title'] ) : '';
        $target_url  = isset( $_POST['target_url'] ) ? esc_url_raw( trim( $_POST['target_url'] ) ) : '';
        $slug_input  = isset( $_POST['slug'] ) ? trim( $_POST['slug'] ) : '';
        $mode        = isset( $_POST['mode'] ) && $_POST['mode'] === 'ui' ? 'ui' : 'backend';
        $description = isset( $_POST['description'] ) ? sanitize_textarea_field( $_POST['description'] ) : '';
        $is_active   = isset( $_POST['is_active'] ) ? 1 : 0;

        if ( empty( $target_url ) ) {
            wp_die( __( 'Target URL is required.', 'wp-short-links' ) );
        }

        // Prepare slug
        if ( $slug_input === '' ) {
            $slug = $this->generate_unique_slug( 6 );
        } else {
            $slug = $this->sanitize_slug( $slug_input );
            if ( empty( $slug ) ) {
                wp_die( __( 'Slug contains invalid characters. Allowed: a-z, A-Z, 0-9, _.', 'wp-short-links' ) );
            }
        }

        // Check uniqueness
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->table_links} WHERE slug = %s AND id != %d",
                $slug,
                $id
            )
        );

        if ( $existing ) {
            wp_die( __( 'Slug already in use. Please choose another.', 'wp-short-links' ) );
        }

        $now  = current_time( 'mysql' );
        $user = get_current_user_id();

        if ( $id > 0 ) {
            $wpdb->update(
                $this->table_links,
                array(
                    'slug'        => $slug,
                    'target_url'  => $target_url,
                    'title'       => $title,
                    'description' => $description,
                    'mode'        => $mode,
                    'is_active'   => $is_active,
                    'updated_at'  => $now,
                ),
                array( 'id' => $id ),
                array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' ),
                array( '%d' )
            );
        } else {
            $wpdb->insert(
                $this->table_links,
                array(
                    'slug'        => $slug,
                    'target_url'  => $target_url,
                    'title'       => $title,
                    'description' => $description,
                    'mode'        => $mode,
                    'is_active'   => $is_active,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                    'created_by'  => $user,
                ),
                array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d' )
            );
        }

        $redirect_url = add_query_arg(
            array(
                'page' => 'wp-short-links',
                'tab'  => 'links',
            ),
            admin_url( 'admin.php' )
        );
        
        if ( ! headers_sent() ) {
            wp_safe_redirect( $redirect_url );
            exit;
        } else {
            // Fallback if headers already sent
            echo '<script>window.location.href="' . esc_js( $redirect_url ) . '";</script>';
            exit;
        }
    }

    public function handle_delete_link() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized', 'wp-short-links' ) );
        }
        check_admin_referer( 'wp_sl_delete_link', 'wp_sl_delete_nonce' );

        global $wpdb;
        $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

        if ( $id > 0 ) {
            $wpdb->delete( $this->table_links, array( 'id' => $id ), array( '%d' ) );
            // Optionally delete stats as well:
            $wpdb->delete( $this->table_stats, array( 'link_id' => $id ), array( '%d' ) );
        }

        $redirect_url = add_query_arg(
            array(
                'page' => 'wp-short-links',
                'tab'  => 'links',
            ),
            admin_url( 'admin.php' )
        );
        
        if ( ! headers_sent() ) {
            wp_safe_redirect( $redirect_url );
            exit;
        } else {
            // Fallback if headers already sent
            echo '<script>window.location.href="' . esc_js( $redirect_url ) . '";</script>';
            exit;
        }
    }

    public function handle_toggle_link() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized', 'wp-short-links' ) );
        }
        check_admin_referer( 'wp_sl_toggle_link', 'wp_sl_toggle_nonce' );

        global $wpdb;
        $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

        if ( $id > 0 ) {
            $link = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT is_active FROM {$this->table_links} WHERE id = %d LIMIT 1",
                    $id
                )
            );
            if ( $link ) {
                $new_status = $link->is_active ? 0 : 1;
                $wpdb->update(
                    $this->table_links,
                    array(
                        'is_active'  => $new_status,
                        'updated_at' => current_time( 'mysql' ),
                    ),
                    array( 'id' => $id ),
                    array( '%d', '%s' ),
                    array( '%d' )
                );
            }
        }

        $redirect_url = add_query_arg(
            array(
                'page' => 'wp-short-links',
                'tab'  => 'links',
            ),
            admin_url( 'admin.php' )
        );
        
        if ( ! headers_sent() ) {
            wp_safe_redirect( $redirect_url );
            exit;
        } else {
            // Fallback if headers already sent
            echo '<script>window.location.href="' . esc_js( $redirect_url ) . '";</script>';
            exit;
        }
    }

    public function handle_save_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized', 'wp-short-links' ) );
        }
        check_admin_referer( 'wp_sl_save_settings', 'wp_sl_settings_nonce' );

        $countdown = isset( $_POST['countdown'] ) ? (int) $_POST['countdown'] : 10;
        if ( $countdown <= 0 ) {
            $countdown = 10;
        }
        update_option( self::OPTION_COUNTDOWN, $countdown );

        $ui_page_id = isset( $_POST['ui_page_id'] ) ? (int) $_POST['ui_page_id'] : 0;
        update_option( self::OPTION_UI_PAGE, $ui_page_id );

        $redirect_url = add_query_arg(
            array(
                'page' => 'wp-short-links',
                'tab'  => 'settings',
            ),
            admin_url( 'admin.php' )
        );
        
        if ( ! headers_sent() ) {
            wp_safe_redirect( $redirect_url );
            exit;
        } else {
            // Fallback if headers already sent
            echo '<script>window.location.href="' . esc_js( $redirect_url ) . '";</script>';
            exit;
        }
    }

    public function handle_export_links() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized', 'wp-short-links' ) );
        }
        check_admin_referer( 'wp_sl_export_links', 'wp_sl_export_nonce' );

        global $wpdb;

        // Fetch all links
        $links = $wpdb->get_results(
            "SELECT slug, target_url, title, description, mode, is_active, created_at, updated_at
             FROM {$this->table_links}
             ORDER BY created_at DESC"
        );

        // Set headers for CSV download
        $filename = 'wp-short-links-export-' . date( 'Y-m-d-His' ) . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        // Open output stream
        $output = fopen( 'php://output', 'w' );

        // Add BOM for UTF-8 (helps Excel recognize encoding)
        fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

        // Write header row
        fputcsv( $output, array(
            'slug',
            'target_url',
            'title',
            'description',
            'mode',
            'is_active',
            'created_at',
            'updated_at'
        ) );

        // Write data rows
        foreach ( $links as $link ) {
            fputcsv( $output, array(
                $link->slug,
                $link->target_url,
                $link->title ? $link->title : '',
                $link->description ? $link->description : '',
                $link->mode,
                $link->is_active,
                $link->created_at,
                $link->updated_at
            ) );
        }

        fclose( $output );
        exit;
    }

    public function handle_import_links() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized', 'wp-short-links' ) );
        }
        check_admin_referer( 'wp_sl_import_links', 'wp_sl_import_nonce' );

        if ( ! isset( $_FILES['csv_file'] ) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK ) {
            wp_die( __( 'Error uploading file.', 'wp-short-links' ) );
        }

        $file = $_FILES['csv_file'];
        $import_mode = isset( $_POST['import_mode'] ) && $_POST['import_mode'] === 'update' ? 'update' : 'skip';

        // Validate file type
        $file_ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( $file_ext !== 'csv' ) {
            wp_die( __( 'Invalid file type. Please upload a CSV file.', 'wp-short-links' ) );
        }

        // Open and read CSV file
        $handle = fopen( $file['tmp_name'], 'r' );
        if ( $handle === false ) {
            wp_die( __( 'Error reading file.', 'wp-short-links' ) );
        }

        // Skip BOM if present
        $bom = fread( $handle, 3 );
        if ( $bom !== chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) ) {
            rewind( $handle );
        }

        global $wpdb;
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = array();

        // Read header row (if present)
        $header = fgetcsv( $handle );
        if ( $header === false ) {
            fclose( $handle );
            wp_die( __( 'Empty or invalid CSV file.', 'wp-short-links' ) );
        }

        // Normalize header (remove BOM, trim, lowercase)
        $header = array_map( function( $h ) {
            return trim( strtolower( $h ) );
        }, $header );

        // Check if first row is header or data
        $has_header = false;
        $expected_columns = array( 'slug', 'target_url', 'title', 'description', 'mode', 'is_active', 'created_at', 'updated_at' );
        $header_match = array_intersect( $header, $expected_columns );
        if ( count( $header_match ) >= 3 ) {
            $has_header = true;
        } else {
            // First row is data, rewind
            rewind( $handle );
            // Skip BOM again if present
            $bom = fread( $handle, 3 );
            if ( $bom !== chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) ) {
                rewind( $handle );
            }
        }

        $row_num = $has_header ? 1 : 0;

        // Process rows
        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            $row_num++;

            // Skip empty rows
            if ( empty( array_filter( $row ) ) ) {
                continue;
            }

            // Map columns (handle both with and without header)
            if ( $has_header ) {
                $data = array();
                foreach ( $expected_columns as $col ) {
                    $idx = array_search( $col, $header );
                    $data[ $col ] = $idx !== false && isset( $row[ $idx ] ) ? trim( $row[ $idx ] ) : '';
                }
            } else {
                // Assume order: slug, target_url, title, description, mode, is_active, created_at, updated_at
                $data = array(
                    'slug'        => isset( $row[0] ) ? trim( $row[0] ) : '',
                    'target_url'  => isset( $row[1] ) ? trim( $row[1] ) : '',
                    'title'       => isset( $row[2] ) ? trim( $row[2] ) : '',
                    'description' => isset( $row[3] ) ? trim( $row[3] ) : '',
                    'mode'        => isset( $row[4] ) ? trim( $row[4] ) : 'backend',
                    'is_active'   => isset( $row[5] ) ? trim( $row[5] ) : '1',
                    'created_at'  => isset( $row[6] ) ? trim( $row[6] ) : '',
                    'updated_at'  => isset( $row[7] ) ? trim( $row[7] ) : '',
                );
            }

            // Validate required fields
            if ( empty( $data['slug'] ) || empty( $data['target_url'] ) ) {
                $errors[] = sprintf( __( 'Row %d: Missing slug or target_url', 'wp-short-links' ), $row_num );
                $skipped++;
                continue;
            }

            // Sanitize and validate
            $slug = $this->sanitize_slug( $data['slug'] );
            if ( empty( $slug ) ) {
                $errors[] = sprintf( __( 'Row %d: Invalid slug format', 'wp-short-links' ), $row_num );
                $skipped++;
                continue;
            }

            $target_url = esc_url_raw( $data['target_url'] );
            if ( empty( $target_url ) ) {
                $errors[] = sprintf( __( 'Row %d: Invalid target URL', 'wp-short-links' ), $row_num );
                $skipped++;
                continue;
            }

            $title = sanitize_text_field( $data['title'] );
            $description = sanitize_textarea_field( $data['description'] );
            $mode = ( isset( $data['mode'] ) && $data['mode'] === 'ui' ) ? 'ui' : 'backend';
            $is_active = ( isset( $data['is_active'] ) && ( $data['is_active'] === '1' || $data['is_active'] === 1 || strtolower( $data['is_active'] ) === 'yes' || strtolower( $data['is_active'] ) === 'true' ) ) ? 1 : 0;

            // Check if slug exists
            $existing = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id FROM {$this->table_links} WHERE slug = %s LIMIT 1",
                    $slug
                )
            );

            $now = current_time( 'mysql' );
            $user = get_current_user_id();

            if ( $existing ) {
                if ( $import_mode === 'update' ) {
                    // Update existing
                    $wpdb->update(
                        $this->table_links,
                        array(
                            'target_url'  => $target_url,
                            'title'       => $title,
                            'description' => $description,
                            'mode'        => $mode,
                            'is_active'   => $is_active,
                            'updated_at'  => $now,
                        ),
                        array( 'id' => $existing->id ),
                        array( '%s', '%s', '%s', '%s', '%d', '%s' ),
                        array( '%d' )
                    );
                    $updated++;
                } else {
                    // Skip duplicate
                    $skipped++;
                }
            } else {
                // Insert new
                $created_at = ! empty( $data['created_at'] ) ? $data['created_at'] : $now;
                $updated_at = ! empty( $data['updated_at'] ) ? $data['updated_at'] : $now;

                $wpdb->insert(
                    $this->table_links,
                    array(
                        'slug'        => $slug,
                        'target_url'  => $target_url,
                        'title'       => $title,
                        'description' => $description,
                        'mode'        => $mode,
                        'is_active'   => $is_active,
                        'created_at'  => $created_at,
                        'updated_at'  => $updated_at,
                        'created_by'  => $user,
                    ),
                    array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d' )
                );
                $imported++;
            }
        }

        fclose( $handle );

        // Build redirect URL with results
        $redirect_url = add_query_arg(
            array(
                'page'     => 'wp-short-links',
                'tab'      => 'links',
                'imported' => $imported,
                'updated'  => $updated,
                'skipped'  => $skipped,
            ),
            admin_url( 'admin.php' )
        );

        if ( ! headers_sent() ) {
            wp_safe_redirect( $redirect_url );
            exit;
        } else {
            echo '<script>window.location.href="' . esc_js( $redirect_url ) . '";</script>';
            exit;
        }
    }

    public function handle_bulk_delete() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized', 'wp-short-links' ) );
        }
        check_admin_referer( 'wp_sl_bulk_action', 'wp_sl_bulk_nonce' );

        global $wpdb;
        $link_ids = isset( $_POST['link_ids'] ) ? sanitize_text_field( $_POST['link_ids'] ) : '';

        if ( empty( $link_ids ) ) {
            wp_die( __( 'No links selected.', 'wp-short-links' ) );
        }

        // Convert comma-separated string to array and sanitize
        $ids = array_map( 'intval', explode( ',', $link_ids ) );
        $ids = array_filter( $ids ); // Remove empty values

        if ( empty( $ids ) ) {
            wp_die( __( 'Invalid link IDs.', 'wp-short-links' ) );
        }

        // Delete links and their stats
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $query = $wpdb->prepare(
            "DELETE FROM {$this->table_links} WHERE id IN ($placeholders)",
            ...$ids
        );
        $wpdb->query( $query );

        // Delete associated stats
        $query_stats = $wpdb->prepare(
            "DELETE FROM {$this->table_stats} WHERE link_id IN ($placeholders)",
            ...$ids
        );
        $wpdb->query( $query_stats );

        $redirect_url = add_query_arg(
            array(
                'page' => 'wp-short-links',
                'tab'  => 'links',
            ),
            admin_url( 'admin.php' )
        );
        
        if ( ! headers_sent() ) {
            wp_safe_redirect( $redirect_url );
            exit;
        } else {
            echo '<script>window.location.href="' . esc_js( $redirect_url ) . '";</script>';
            exit;
        }
    }

    public function handle_bulk_enable() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized', 'wp-short-links' ) );
        }
        check_admin_referer( 'wp_sl_bulk_action', 'wp_sl_bulk_nonce' );

        global $wpdb;
        $link_ids = isset( $_POST['link_ids'] ) ? sanitize_text_field( $_POST['link_ids'] ) : '';

        if ( empty( $link_ids ) ) {
            wp_die( __( 'No links selected.', 'wp-short-links' ) );
        }

        // Convert comma-separated string to array and sanitize
        $ids = array_map( 'intval', explode( ',', $link_ids ) );
        $ids = array_filter( $ids ); // Remove empty values

        if ( empty( $ids ) ) {
            wp_die( __( 'Invalid link IDs.', 'wp-short-links' ) );
        }

        // Enable links
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $now = current_time( 'mysql' );
        
        // Update each link individually to set updated_at
        foreach ( $ids as $id ) {
            $wpdb->update(
                $this->table_links,
                array(
                    'is_active'  => 1,
                    'updated_at' => $now,
                ),
                array( 'id' => $id ),
                array( '%d', '%s' ),
                array( '%d' )
            );
        }

        $redirect_url = add_query_arg(
            array(
                'page' => 'wp-short-links',
                'tab'  => 'links',
            ),
            admin_url( 'admin.php' )
        );
        
        if ( ! headers_sent() ) {
            wp_safe_redirect( $redirect_url );
            exit;
        } else {
            echo '<script>window.location.href="' . esc_js( $redirect_url ) . '";</script>';
            exit;
        }
    }

    public function handle_bulk_disable() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized', 'wp-short-links' ) );
        }
        check_admin_referer( 'wp_sl_bulk_action', 'wp_sl_bulk_nonce' );

        global $wpdb;
        $link_ids = isset( $_POST['link_ids'] ) ? sanitize_text_field( $_POST['link_ids'] ) : '';

        if ( empty( $link_ids ) ) {
            wp_die( __( 'No links selected.', 'wp-short-links' ) );
        }

        // Convert comma-separated string to array and sanitize
        $ids = array_map( 'intval', explode( ',', $link_ids ) );
        $ids = array_filter( $ids ); // Remove empty values

        if ( empty( $ids ) ) {
            wp_die( __( 'Invalid link IDs.', 'wp-short-links' ) );
        }

        // Disable links
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $now = current_time( 'mysql' );
        
        // Update each link individually to set updated_at
        foreach ( $ids as $id ) {
            $wpdb->update(
                $this->table_links,
                array(
                    'is_active'  => 0,
                    'updated_at' => $now,
                ),
                array( 'id' => $id ),
                array( '%d', '%s' ),
                array( '%d' )
            );
        }

        $redirect_url = add_query_arg(
            array(
                'page' => 'wp-short-links',
                'tab'  => 'links',
            ),
            admin_url( 'admin.php' )
        );
        
        if ( ! headers_sent() ) {
            wp_safe_redirect( $redirect_url );
            exit;
        } else {
            echo '<script>window.location.href="' . esc_js( $redirect_url ) . '";</script>';
            exit;
        }
    }

    /* -------------------------------------------------------------------------
     * Shortcode for Custom UI Page
     * ---------------------------------------------------------------------- */

    public function shortcode_redirect_ui( $atts ) {
        global $wpdb;
        
        // Get slug from query parameter
        $slug = isset( $_GET['wp_sl_slug'] ) ? sanitize_text_field( $_GET['wp_sl_slug'] ) : '';
        
        if ( empty( $slug ) ) {
            return '<p>' . esc_html__( 'No redirect link specified.', 'wp-short-links' ) . '</p>';
        }

        // Fetch link data
        $link = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_links} WHERE slug = %s AND is_active = 1 LIMIT 1",
                $slug
            )
        );

        if ( ! $link ) {
            return '<p>' . esc_html__( 'Link not found or inactive.', 'wp-short-links' ) . '</p>';
        }

        // Update stats
        $this->increment_stats( $link->id );

        $seconds = (int) get_option( self::OPTION_COUNTDOWN, 10 );
        if ( $seconds <= 0 ) {
            $seconds = 10;
        }

        $title       = $link->title ?: __( 'Redirecting…', 'wp-short-links' );
        $description = $link->description;
        $target      = esc_url( $link->target_url );

        ob_start();
        ?>
        <div class="wp-sl-redirect-container">
            <div class="wp-sl-redirect-content">
                <?php if ( $title ) : ?>
                    <h2 class="wp-sl-redirect-title"><?php echo esc_html( $title ); ?></h2>
                <?php endif; ?>

                <?php if ( $description ) : ?>
                    <div class="wp-sl-redirect-description">
                        <?php echo wp_kses_post( wpautop( $description ) ); ?>
                    </div>
                <?php endif; ?>

                <div class="wp-sl-redirect-countdown">
                    <p>
                        <?php
                        printf(
                            esc_html__( 'You will be redirected in %s seconds…', 'wp-short-links' ),
                            '<span id="wp-sl-countdown">' . esc_html( $seconds ) . '</span>'
                        );
                        ?>
                    </p>
                </div>

                <div class="wp-sl-redirect-link">
                    <p>
                        <a id="wp-sl-direct-link" href="<?php echo esc_url( $target ); ?>" class="wp-sl-direct-link-button">
                            <?php esc_html_e( 'Skip waiting and go now', 'wp-short-links' ); ?>
                        </a>
                    </p>
                </div>
            </div>
        </div>

        <script>
            (function() {
                var seconds = <?php echo (int) $seconds; ?>;
                var span    = document.getElementById('wp-sl-countdown');
                var link    = document.getElementById('wp-sl-direct-link');

                function tick() {
                    seconds--;
                    if (span) {
                        span.textContent = seconds;
                    }
                    if (seconds <= 0) {
                        if (link) {
                            window.location.href = link.href;
                        }
                    } else {
                        window.setTimeout(tick, 1000);
                    }
                }
                window.setTimeout(tick, 1000);
            })();
        </script>

        <style>
            .wp-sl-redirect-container {
                margin: 20px 0;
                padding: 20px;
            }
            .wp-sl-redirect-title {
                margin-top: 0;
            }
            .wp-sl-redirect-countdown {
                margin: 20px 0;
                font-size: 1.2em;
            }
            .wp-sl-direct-link-button {
                display: inline-block;
                padding: 10px 20px;
                background: #0073aa;
                color: #fff;
                text-decoration: none;
                border-radius: 3px;
            }
            .wp-sl-direct-link-button:hover {
                background: #005a87;
                color: #fff;
            }
        </style>
        <?php
        return ob_get_clean();
    }

    /* -------------------------------------------------------------------------
     * Helpers
     * ---------------------------------------------------------------------- */

    private function sanitize_slug( $slug ) {
        // Allow only a-z, A-Z, 0-9, _
        $slug = preg_replace( '/[^A-Za-z0-9_]/', '', $slug );
        if ( strlen( $slug ) > 50 ) {
            $slug = substr( $slug, 0, 50 );
        }
        return $slug;
    }

    private function generate_unique_slug( $length = 6 ) {
        global $wpdb;
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_';

        do {
            $slug = '';
            for ( $i = 0; $i < $length; $i++ ) {
                $slug .= $chars[ wp_rand( 0, strlen( $chars ) - 1 ) ];
            }

            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$this->table_links} WHERE slug = %s LIMIT 1",
                    $slug
                )
            );
        } while ( $exists );

        return $slug;
    }

    /* -------------------------------------------------------------------------
     * Gutenberg Block
     * ---------------------------------------------------------------------- */

    public function register_block() {
        // Check if block editor is available
        if ( ! function_exists( 'register_block_type' ) ) {
            return;
        }
        
        // Register JS for editor
        $script_path = plugins_url( 'assets/js/lnk-block.js', __FILE__ );
        $script_file = plugin_dir_path( __FILE__ ) . 'assets/js/lnk-block.js';
        
        // Only register if file exists
        if ( file_exists( $script_file ) ) {
            wp_register_script(
                'wp-sl-block',
                $script_path,
                array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-editor', 'wp-data', 'wp-api-fetch', 'wp-i18n' ),
                self::VERSION,
                true
            );
        }

        register_block_type( 'wp-sl/short-link', array(
            'editor_script'   => file_exists( $script_file ) ? 'wp-sl-block' : null,
            'render_callback' => array( $this, 'render_block' ),
            'attributes'      => array(
                'slug'  => array(
                    'type'    => 'string',
                    'default' => '',
                ),
                'label' => array(
                    'type'    => 'string',
                    'default' => '',
                ),
            ),
        ) );
    }

    public function localize_block_data() {
        if ( ! is_admin() ) {
            return;
        }

        // Only in block editor
        $screen = get_current_screen();
        if ( ! $screen || $screen->base !== 'post' ) {
            return;
        }

        global $wpdb;
        $links = $wpdb->get_results(
            "SELECT id, slug, title, target_url, is_active FROM {$this->table_links} WHERE is_active = 1 ORDER BY created_at DESC LIMIT 200"
        );

        $data = array();
        if ( $links ) {
            foreach ( $links as $l ) {
                $data[] = array(
                    'id'         => (int) $l->id,
                    'slug'       => $l->slug,
                    'title'      => $l->title,
                    'target_url' => $l->target_url,
                    'short_url'  => home_url( '/go/' . $l->slug ),
                );
            }
        }

        wp_localize_script(
            'wp-sl-block',
            'WPShortLinksBlockData',
            array(
                'links' => $data,
            )
        );
    }

    public function render_block( $attributes ) {
        $slug  = isset( $attributes['slug'] ) ? $attributes['slug'] : '';
        $label = isset( $attributes['label'] ) ? $attributes['label'] : '';

        if ( empty( $slug ) ) {
            return '';
        }

        $url   = esc_url( home_url( '/go/' . $slug ) );
        $label = $label !== '' ? $label : $url;

        return sprintf(
            '<a class="wp-sl-short-link" href="%s">%s</a>',
            $url,
            esc_html( $label )
        );
    }
}

// Register activation/deactivation hooks (must be outside class)
register_activation_hook( __FILE__, array( 'WP_Short_Links_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WP_Short_Links_Plugin', 'deactivate' ) );

// Initialize plugin (only if not in activation/uninstall context)
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) && ! defined( 'WP_CLI' ) ) {
    try {
        WP_Short_Links_Plugin::instance();
    } catch ( Exception $e ) {
        // Silently fail to prevent white screen - error will be logged if WP_DEBUG is on
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'WP Short Links Plugin Error: ' . $e->getMessage() );
        }
    }
}
