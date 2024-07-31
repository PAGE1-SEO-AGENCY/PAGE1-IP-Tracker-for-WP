<?php
/**
 * Plugin Name: PAGE1 IP Tracker WP
 * Description: Ghi lại các địa chỉ IP truy cập website và cung cấp cảnh báo IP truy cập thường xuyên.
 * Version: 1.1
 * Author: PAGE1 SEO Agency
 * Author URI: https://page1.vn
 */

// Hook vào action 'init' để ghi lại địa chỉ IP và thông tin liên quan
add_action('init', 'page1_ip_tracker_log_ip');
add_action('admin_post_page1_clear_logs', 'page1_ip_tracker_clear_logs');

function page1_ip_tracker_log_ip() {
    if (!is_admin()) {
        global $wpdb;

        // Kiểm tra và lấy IP thật khi dùng Cloudflare
        $ip_address = $_SERVER['REMOTE_ADDR'];
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip_address = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'];
        }

        // Sử dụng API tra cứu thông tin IP
        $ip_data = page1_ip_tracker_get_ip_info($ip_address);
        $country = $ip_data['country'] ?? 'Unknown';
        $device = $ip_data['device'] ?? 'Unknown';
        $browser = $ip_data['browser'] ?? 'Unknown';

        $current_time = current_time('mysql');
        $url = esc_url($_SERVER['REQUEST_URI']);
        $table_name = $wpdb->prefix . 'page1_ip_logs';
        
        $wpdb->insert(
            $table_name,
            array(
                'ip_address' => $ip_address,
                'visit_time' => $current_time,
                'url' => $url,
                'country' => $country,
                'device' => $device,
                'browser' => $browser
            )
        );
    }
}

// Hàm lấy thông tin IP từ dịch vụ API (ví dụ API IPinfo hoặc dịch vụ tương tự)
function page1_ip_tracker_get_ip_info($ip_address) {
    // Bạn có thể thay thế URL và Key của dịch vụ bạn sử dụng
    $api_url = 'https://ipinfo.io/'.$ip_address.'/json';
    $response = wp_remote_get($api_url);

    if (is_wp_error($response)) {
        return [];
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    // Xử lý thiết bị và trình duyệt
    $user_agent = $_SERVER['HTTP_USER_AGENT'];
    $device = wp_is_mobile() ? 'Mobile' : 'Desktop';
    $browser = '';

    if (strpos($user_agent, 'Firefox') !== false) {
        $browser = 'Firefox';
    } elseif (strpos($user_agent, 'Chrome') !== false) {
        $browser = 'Chrome';
    } elseif (strpos($user_agent, 'Safari') !== false) {
        $browser = 'Safari';
    } elseif (strpos($user_agent, 'MSIE') !== false || strpos($user_agent, 'Trident') !== false) {
        $browser = 'Internet Explorer';
    } else {
        $browser = 'Unknown';
    }

    return [
        'country' => $data['country'] ?? 'Unknown',
        'device' => $device,
        'browser' => $browser
    ];
}

// Hàm xử lý xóa toàn bộ log
function page1_ip_tracker_clear_logs() {
    global $wpdb;
    if (isset($_POST['clear_logs']) && check_admin_referer('page1_clear_logs_nonce')) {
        $table_name = $wpdb->prefix . 'page1_ip_logs';
        $wpdb->query("TRUNCATE TABLE $table_name");
        echo '<div class="updated"><p>All logs have been cleared.</p></div>';
    }
}

// Tạo bảng lưu trữ IP logs khi kích hoạt plugin
register_activation_hook(__FILE__, 'page1_ip_tracker_create_table');

function page1_ip_tracker_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'page1_ip_logs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        ip_address varchar(100) NOT NULL,
        visit_time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        url varchar(255) DEFAULT '' NOT NULL,
        country varchar(100) DEFAULT 'Unknown',
        device varchar(100) DEFAULT 'Unknown',
        browser varchar(100) DEFAULT 'Unknown',
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Tạo Dashboard để xem danh sách IP truy cập
add_action('admin_menu', 'page1_ip_tracker_admin_menu');

function page1_ip_tracker_admin_menu() {
    add_menu_page(
        'PAGE1 IP Tracker', 
        'PAGE1 IP Tracker', 
        'manage_options', 
        'page1-ip-tracker', 
        'page1_ip_tracker_admin_page', 
        'dashicons-visibility', 
        100
    );
}

// Thêm CSS cho Dashboard
add_action('admin_enqueue_scripts', 'page1_ip_tracker_enqueue_styles');

function page1_ip_tracker_enqueue_styles($hook) {
    if ($hook != 'toplevel_page_page1-ip-tracker') {
        return;
    }
    wp_enqueue_style('page1-ip-tracker-styles', plugins_url('style.css', __FILE__));
}

function page1_ip_tracker_admin_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'page1_ip_logs';
    $per_page = 100; // Số IP mỗi trang
    $current_page = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
    $offset = ($current_page - 1) * $per_page;

    // Xử lý lọc
    $ip_filter = isset($_GET['ip_filter']) ? sanitize_text_field($_GET['ip_filter']) : '';
    $visit_count_filter = isset($_GET['visit_count_filter']) ? intval($_GET['visit_count_filter']) : '';
    $date_filter = isset($_GET['date_filter']) ? sanitize_text_field($_GET['date_filter']) : '';

    // Tạo câu lệnh SQL với các điều kiện lọc
    $where = [];
    if ($ip_filter) {
        $where[] = $wpdb->prepare("ip_address LIKE %s", '%' . $wpdb->esc_like($ip_filter) . '%');
    }
    if ($visit_count_filter) {
        $where[] = "COUNT(*) >= $visit_count_filter";
    }
    if ($date_filter) {
        $where[] = $wpdb->prepare("visit_time >= %s", $date_filter);
    }

    $where_clause = '';
    if ($where) {
        $where_clause = 'WHERE ' . implode(' AND ', $where);
    }

    $results = $wpdb->get_results("
        SELECT ip_address, country, device, browser, GROUP_CONCAT(url SEPARATOR '<br>') as urls, GROUP_CONCAT(visit_time SEPARATOR '<br>') as visit_times, COUNT(*) as visit_count
        FROM $table_name
        $where_clause
        GROUP BY ip_address
        ORDER BY MAX(visit_time) DESC
        LIMIT $offset, $per_page
    ");

    $total_results = $wpdb->get_var("
        SELECT COUNT(DISTINCT ip_address)
        FROM $table_name
        $where_clause
    ");
    $total_pages = ceil($total_results / $per_page);

    echo '<div class="page1-ip-tracker">';
    echo '<header class="page1-ip-tracker-header">';
    echo '<img src="https://page1.vn/wp-content/uploads/2023/01/logo-trang-ngang-600px.png" alt="Page1 Logo" class="page1-ip-tracker-logo">';
    echo '<h1>IP Tracker Logs</h1>';
    echo '</header>';
    echo '<div class="page1-ip-tracker-content">';
    
    // Form lọc
    echo '<form method="GET" action="">';
    echo '<input type="hidden" name="page" value="page1-ip-tracker">';
    echo '<p><label for="ip_filter">IP Address: </label>';
    echo '<input type="text" id="ip_filter" name="ip_filter" value="' . esc_attr($ip_filter) . '"></p>';
    echo '<p><label for="visit_count_filter">Minimum Visits: </label>';
    echo '<input type="number" id="visit_count_filter" name="visit_count_filter" value="' . esc_attr($visit_count_filter) . '"></p>';
    echo '<p><label for="date_filter">Date From: </label>';
    echo '<input type="date" id="date_filter" name="date_filter" value="' . esc_attr($date_filter) . '"></p>';
    echo '<p><input type="submit" value="Filter"></p>';
    echo '</form>';

    // Bảng dữ liệu
    echo '<table class="wp-list-table widefat fixed striped">';
    // Thêm nút "Clear Log" vào đầu bảng thông tin
    echo '<form method="POST" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="page1_clear_logs">';
    wp_nonce_field('page1_clear_logs_nonce');
    echo '<p><input type="submit" name="clear_logs" class="button button-primary" value="Clear Logs"></p>';
    echo '</form>';

    echo '<thead><tr><th>IP Address</th><th>Country</th><th>Device</th><th>Browser</th><th>URLs</th><th>Visit Times</th><th>Number of Visits</th></tr></thead><tbody>';

    foreach ($results as $row) {
        echo '<tr>';
        echo '<td>' . esc_html($row->ip_address) . '</td>';
        echo '<td>' . esc_html($row->country) . '</td>';
        echo '<td>' . esc_html($row->device) . '</td>';
        echo '<td>' . esc_html($row->browser) . '</td>';
        echo '<td>' . wp_kses_post($row->urls) . '</td>';
        echo '<td>' . wp_kses_post($row->visit_times) . '</td>';
        echo '<td>' . esc_html($row->visit_count) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';

    // Phân trang
    echo '<div class="pagination">';
    echo '<span class="pagination-prev">';
    if ($current_page > 1) {
        echo '<a href="?page=page1-ip-tracker&paged=' . ($current_page - 1) . '&ip_filter=' . esc_attr($ip_filter) . '&visit_count_filter=' . esc_attr($visit_count_filter) . '&date_filter=' . esc_attr($date_filter) . '">&laquo; Previous</a>';
    }
    echo '</span>';
    echo '<span class="pagination-next">';
    if ($current_page < $total_pages) {
        echo '<a href="?page=page1-ip-tracker&paged=' . ($current_page + 1) . '&ip_filter=' . esc_attr($ip_filter) . '&visit_count_filter=' . esc_attr($visit_count_filter) . '&date_filter=' . esc_attr($date_filter) . '">Next &raquo;</a>';
    }
    echo '</span>';
    echo '</div>';

    echo '</div></div>';
}
?>
