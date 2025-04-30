<?php
/*
Plugin Name: Gelişmiş Link Analiz Aracı
Description: Web sitenizdeki linkleri detaylı olarak body bölümünde analiz eder.
Version: 1.6
Author: Has Web Tasarım
Author URI: https://haswebtasarim.com
Plugin URI: https://haswebtasarim.com/wordpress-link-analiz-eklentisi
*/

class GelismisLinkAnalyzer {
    private $home_url;
    private $additional_internal_domains;

    public function __construct() {
        $this->home_url = get_home_url();
        
        $this->additional_internal_domains = array(
            'haswebtasarim.com',
            'www.haswebtasarim.com'
        );

        add_action('admin_menu', array($this, 'add_plugin_menu'));
        add_action('wp_ajax_analyze_links', array($this, 'analyze_links'));
        add_action('admin_init', array($this, 'check_and_run_scheduled_scan'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    public function enqueue_admin_scripts() {
        wp_enqueue_script('jquery');
        wp_enqueue_script('link-analyzer-script', plugin_dir_url(__FILE__) . 'link-analyzer.js', array('jquery'), '1.0', true);
        wp_localize_script('link-analyzer-script', 'linkAnalyzerAjax', array(
            'ajax_url' => admin_url('admin-ajax.php')
        ));
    }

    public function add_plugin_menu() {
        add_menu_page(
            'Link Analiz Aracı', 
            'Link Analizi', 
            'manage_options', 
            'gelismis-link-analyzer', 
            array($this, 'render_plugin_page'),
            'dashicons-admin-links',
            30
        );

        add_submenu_page(
            'gelismis-link-analyzer', 
            'Link Analiz Detayları', 
            'Detaylı Analiz', 
            'manage_options', 
            'gelismis-link-analyzer'
        );
    }

    public function render_plugin_page() {
        $last_scan = get_option('link_analyzer_last_scan', 'Henüz tarama yapılmadı');
        $scan_results = get_option('link_analyzer_scan_results', array());
        
        $formatted_last_scan = $last_scan ? 
            date('d.m.Y H:i:s', $last_scan) : 
            'Henüz tarama yapılmadı';
        ?>
        <div class="wrap link-analyzer-container">
            <h1>Gelişmiş Link Analiz Aracı</h1>
            <div class="card" style="max-width:100%;">
                <div class="card-body">
                    <h2>Geliştirici: <a href="https://haswebtasarim.com" target="_blank">Has Web Tasarım</a></h2>
                    <p>Web sitenizin link yapısını detaylı olarak analiz eden profesyonel bir araç.</p>
                </div>
            </div>
            <p>Son Tarama Zamanı: <span id="last-scan-time"><?php echo $formatted_last_scan; ?></span></p>
            <button id="start-link-scan" class="button button-primary">
                Taramayı Yeniden Başlat
            </button>

            <div id="link-results">
                <?php 
                if (!empty($scan_results)) {
                    echo $this->generate_link_table($scan_results);
                }
                ?>
            </div>
        </div>
        <?php
    }

    public function analyze_links() {
        // Güvenlik kontrolü
        check_ajax_referer('link_analyzer_nonce', 'security');

        $posts_and_pages = get_posts(array(
            'posts_per_page' => -1,
            'post_type' => array('post', 'page')
        ));

        $link_report = $this->process_link_analysis($posts_and_pages);
        
        // Sonuçları kaydet
        update_option('link_analyzer_scan_results', $link_report);
        update_option('link_analyzer_last_scan', time());

        // Tabloyu yazdır
        $table_html = $this->generate_link_table($link_report);
        
        wp_send_json_success(array(
            'table_html' => $table_html,
            'last_scan_time' => date('d.m.Y H:i:s', time())
        ));
    }

    // Diğer metodlar önceki kodla aynı kalacak

    private function process_link_analysis($posts_and_pages) {
        $link_report = array();

        foreach ($posts_and_pages as $post) {
            $doc = new DOMDocument();
            @$doc->loadHTML(mb_convert_encoding($post->post_content, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            
            $body_content = $doc->getElementsByTagName('body')->item(0);
            $content = $body_content ? $doc->saveHTML($body_content) : $post->post_content;
            
            $link_data = $this->extract_links($content);

            $link_report[] = array(
                'title' => $post->post_title,
                'post_id' => $post->ID,
                'edit_link' => admin_url('post.php?post=' . $post->ID . '&action=edit'),
                'view_link' => get_permalink($post->ID),
                'internal_links' => $link_data['internal'],
                'external_links' => $link_data['external'],
                'anchor_texts' => $link_data['anchor_texts']
            );
        }

        return $link_report;
    }

    private function is_internal_link($url) {
        $parsed_url = parse_url($url);
        
        if (empty($parsed_url['host']) || 
            strpos($url, '/') === 0 || 
            strpos($url, './') === 0) {
            return true;
        }

        $home_domain = parse_url($this->home_url, PHP_URL_HOST);
        
        $is_internal = (
            $parsed_url['host'] === $home_domain || 
            in_array($parsed_url['host'], $this->additional_internal_domains)
        );

        return $is_internal;
    }

    private function extract_links($content) {
        $links = array(
            'internal' => 0,
            'external' => 0,
            'total' => 0,
            'anchor_texts' => array()
        );

        preg_match_all('/<a\s+(?:[^>]*?\s+)?href="(?!tel:)([^"]*)"[^>]*>([^<]+)<\/a>/', $content, $matches);
        
        if (!empty($matches[1])) {
            foreach ($matches[1] as $index => $link) {
                if (empty($link) || 
                    strpos($link, 'javascript:') === 0) {
                    continue;
                }

                $anchor_text = $matches[2][$index];
                $links['total']++;

                $full_url = $this->normalize_url($link);

                if ($this->is_internal_link($full_url)) {
                    $links['internal']++;
                } else {
                    $links['external']++;
                    $links['anchor_texts'][] = $anchor_text;
                }
            }
        }

        return $links;
    }

    private function normalize_url($url) {
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        } elseif (strpos($url, '/') === 0) {
            $url = $this->home_url . $url;
        } elseif (strpos($url, './') === 0) {
            $url = $this->home_url . substr($url, 1);
        }

        return $url;
    }

    public function check_and_run_scheduled_scan() {
        $last_scan = get_option('link_analyzer_last_scan');
        $scan_interval = 7 * 24 * 60 * 60; // 1 hafta

        if (!$last_scan || (time() - $last_scan > $scan_interval)) {
            $this->run_scheduled_scan();
        }
    }

    public function run_scheduled_scan() {
        $posts_and_pages = get_posts(array(
            'posts_per_page' => -1,
            'post_type' => array('post', 'page')
        ));

        $link_report = $this->process_link_analysis($posts_and_pages);
        update_option('link_analyzer_scan_results', $link_report);
        update_option('link_analyzer_last_scan', time());
    }

    private function generate_link_table($link_report) {
        $filtered_report = array_filter($link_report, function($item) {
            return $item['internal_links'] <= 3;
        });

        $table_html = '
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>İşlemler</th>
                    <th>Sayfa Başlığı</th>
                    <th>İç Link Sayısı</th>
                    <th>Dış Link Sayısı</th>
                    <th>Anchor Textler</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($filtered_report as $report) {
            $table_html .= sprintf(
                '<tr>
                    <td>
                        <a href="%s" class="button button-small" target="_blank">Düzenle</a>
                        <a href="%s" class="button button-small" target="_blank">Görüntüle</a>
                    </td>
                    <td>%s</td>
                    <td>%d</td>
                    <td>%d</td>
                    <td>%s</td>
                </tr>',
                esc_url($report['edit_link']),
                esc_url($report['view_link']),
                esc_html($report['title']),
                $report['internal_links'],
                $report['external_links'],
                implode(', ', array_map('esc_html', $report['anchor_texts']))
            );
        }

        $table_html .= '</tbody></table>';

        return $table_html;
    }
}

$gelismis_link_analyzer = new GelismisLinkAnalyzer();