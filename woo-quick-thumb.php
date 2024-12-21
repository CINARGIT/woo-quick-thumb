<?php
/**
 * Plugin Name: WooCommerce Quick Thumbnail Editor
 * Plugin URI: https://github.com/renathasanov/woo-quick-thumb
 * Description: Быстрое редактирование миниатюр товаров WooCommerce прямо из списка товаров
 * Version: 1.0.0
 * Author: Хасанов Ренат
 * Author URI: mailto:prog@cinar.ru
 * Text Domain: woo-quick-thumb
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 3.0
 * WC tested up to: 8.5
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Проверяем наличие WooCommerce
function wqt_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'wqt_missing_wc_notice');
        return false;
    }
    return true;
}

// Уведомление об отсутствии WooCommerce
function wqt_missing_wc_notice() {
    ?>
    <div class="error">
        <p><?php _e('WooCommerce Quick Thumbnail Editor требует установленный и активированный WooCommerce!', 'woo-quick-thumb'); ?></p>
    </div>
    <?php
}

// Инициализация плагина
function wqt_init() {
    if (!wqt_check_woocommerce()) {
        return;
    }
    
    // Добавляем JavaScript в админку
    add_action('admin_footer', 'wqt_add_quick_thumbnail_edit_scripts');
    
    // Добавляем подсказку при наведении на миниатюру
    add_action('manage_product_posts_custom_column', 'wqt_add_thumbnail_tooltip', 10, 2);
    
    // Регистрируем AJAX обработчик
    add_action('wp_ajax_update_product_thumbnail', 'wqt_handle_update_product_thumbnail');
}
add_action('plugins_loaded', 'wqt_init');

// Добавляем JavaScript в админку
function wqt_add_quick_thumbnail_edit_scripts() {
    $screen = get_current_screen();
    if ($screen->id !== 'edit-product') return;
    
    // Создаем nonce для безопасности
    $nonce = wp_create_nonce('quick_thumb_edit_nonce');
    
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        $(document).on('click', '.column-thumb img', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            var $img = $(this);
            var $link = $img.closest('a');
            var postId = $img.closest('tr').attr('id').replace('post-', '');
            
            // Сохраняем оригинальные атрибуты
            var originalSrc = $img.attr('src');
            var originalSrcset = $img.attr('srcset');
            var originalSizes = $img.attr('sizes');
            
            var frame = wp.media({
                title: 'Выберите новую миниатюру',
                multiple: false,
                library: {
                    type: 'image'
                }
            });

            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'update_product_thumbnail',
                        post_id: postId,
                        thumbnail_id: attachment.id,
                        nonce: '<?php echo $nonce; ?>'
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            $img
                                .attr('src', response.data.src)
                                .attr('srcset', response.data.srcset || '')
                                .attr('sizes', response.data.sizes || 'auto')
                                .attr('width', response.data.width)
                                .attr('height', response.data.height);
                            
                            $img.on('error', function() {
                                $(this)
                                    .attr('src', originalSrc)
                                    .attr('srcset', originalSrcset)
                                    .attr('sizes', originalSizes)
                                    .off('error');
                            });
                        }
                    },
                    error: function() {
                        $img
                            .attr('src', originalSrc)
                            .attr('srcset', originalSrcset)
                            .attr('sizes', originalSizes);
                    }
                });
            });

            frame.open();
        });
    });
    </script>
    <?php
}

// Обработчик AJAX запроса
function wqt_handle_update_product_thumbnail() {
    if (!check_ajax_referer('quick_thumb_edit_nonce', 'nonce', false)) {
        wp_send_json_error('Неверный nonce');
        return;
    }
    
    if (!current_user_can('edit_products')) {
        wp_send_json_error('Недостаточно прав');
        return;
    }
    
    $post_id = intval($_POST['post_id']);
    $thumbnail_id = intval($_POST['thumbnail_id']);
    
    if (!$post_id || !$thumbnail_id) {
        wp_send_json_error('Неверные параметры');
        return;
    }
    
    $result = set_post_thumbnail($post_id, $thumbnail_id);
    
    if ($result) {
        $image_data = wp_get_attachment_image_src($thumbnail_id, 'thumbnail', true);
        $image_srcset = wp_get_attachment_image_srcset($thumbnail_id, 'thumbnail');
        $image_sizes = wp_get_attachment_image_sizes($thumbnail_id, 'thumbnail');
        
        if ($image_data && is_array($image_data)) {
            wp_send_json_success(array(
                'src' => $image_data[0],
                'width' => $image_data[1],
                'height' => $image_data[2],
                'srcset' => $image_srcset,
                'sizes' => $image_sizes
            ));
        } else {
            wp_send_json_error('Не удалось получить данные изображения');
        }
    } else {
        wp_send_json_error('Ошибка обновления миниатюры');
    }
}

// Добавляем подсказку при наведении на миниатюру
function wqt_add_thumbnail_tooltip($column, $post_id) {
    if ($column === 'thumb') {
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('#post-<?php echo $post_id; ?> .column-thumb img')
                .attr('title', 'Нажмите для изменения миниатюры')
                .css('cursor', 'pointer');
        });
        </script>
        <?php
    }
}

// Активация плагина
register_activation_hook(__FILE__, 'wqt_activate');
function wqt_activate() {
    // Проверяем наличие WooCommerce при активации
    if (!wqt_check_woocommerce()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('Этот плагин требует установленный и активированный WooCommerce.');
    }
}