jQuery(document).ready(function($) {
    $('#start-link-scan').on('click', function() {
        // Butonu devre dışı bırak
        $(this).prop('disabled', true).text('Taranıyor...');

        // AJAX isteği
        $.ajax({
            url: linkAnalyzerAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'analyze_links',
                security: '<?php echo wp_create_nonce("link_analyzer_nonce"); ?>'
            },
            success: function(response) {
                if (response.success) {
                    // Tabloyu güncelle
                    $('#link-results').html(response.data.table_html);
                    
                    // Son tarama zamanını güncelle
                    $('#last-scan-time').text(response.data.last_scan_time);
                    
                    // Butonu geri etkinleştir
                    $('#start-link-scan')
                        .prop('disabled', false)
                        .text('Taramayı Yeniden Başlat');
                } else {
                    alert('Tarama sırasında bir hata oluştu.');
                    
                    // Butonu geri etkinleştir
                    $('#start-link-scan')
                        .prop('disabled', false)
                        .text('Taramayı Yeniden Başlat');
                }
            },
            error: function() {
                alert('Sunucuya bağlanırken bir hata oluştu.');
                
                // Butonu geri etkinleştir
                $('#start-link-scan')
                    .prop('disabled', false)
                    .text('Taramayı Yeniden Başlat');
            }
        });
    });
});