<?php
/**
 * Plugin Name: Shorts API
 * Description: Create a shorts page for videos.
 * Version: 2.2.21
 * Author: ER Soluções Web
 * Author URI: https://albreis.github.io/shorts-api/
 * Text Domain: shorts-api
 * License: GPLv2 or later
 */

if (!defined('ABSPATH'))
    exit;

/**
 * Plugin Activation: Register site with backend
 */
register_activation_hook(__FILE__, 'shorts_api_plugin_activate');

function shorts_api_plugin_activate()
{
    shorts_api_register_site_with_backend();
}

define('SHORTS_EXPRESS_API', 'https://shorts.albreis.com.br/api');

/**
 * Helper to get normalized domain (strips www. and http/s)
 */
function shorts_api_get_current_domain()
{
    $domain = str_replace(['http://', 'https://'], '', get_site_url());
    $domain = rtrim($domain, '/');
    $domain = preg_replace('/^www\./', '', $domain);
    return $domain;
}

/**
 * Auto-detect site logo from WordPress Custom Logo
 */
function shorts_api_get_auto_logo()
{
    $custom_logo_id = get_theme_mod('custom_logo');
    if ($custom_logo_id) {
        $logo_url = wp_get_attachment_image_url($custom_logo_id, 'full');
        if ($logo_url)
            return $logo_url;
    }
    return '';
}

/**
 * Auto-detect favicon from WordPress Site Icon
 */
function shorts_api_get_auto_favicon()
{
    $site_icon_id = get_option('site_icon');
    if ($site_icon_id) {
        $icon_url = wp_get_attachment_image_url($site_icon_id, 'full');
        if ($icon_url)
            return $icon_url;
    }
    return '';
}

/**
 * Get effective logo URL (manual override or auto-detected)
 */
function shorts_api_get_logo_url()
{
    return get_option('shorts_api_logo_url', shorts_api_get_auto_logo());
}

/**
 * Get effective favicon URL (manual override or auto-detected)
 */
function shorts_api_get_favicon_url()
{
    return get_option('shorts_api_favicon_url', shorts_api_get_auto_favicon());
}

/**
 * Get effective site title (manual override or blogname)
 */
function shorts_api_get_site_title()
{
    return get_option('shorts_api_site_title', '');
}

/**
 * Get effective sidebar title (manual override or site title)
 */
function shorts_api_get_sidebar_title()
{
    return get_option('shorts_api_sidebar_title', '');
}

/**
 * Register Site with Express Backend
 */
function shorts_api_register_site_with_backend()
{
    $domain = shorts_api_get_current_domain();
    $api_key = get_option('shorts_api_key');

    if (!$api_key) {
        $api_key = wp_generate_password(32, false);
        update_option('shorts_api_key', $api_key);
    }

    $payload = array(
        'domain' => $domain
    );

    $response = wp_remote_post(SHORTS_EXPRESS_API . '/register-site', array(
        'body' => json_encode($payload),
        'headers' => array(
            'Content-Type' => 'application/json',
            'X-API-Key' => $api_key
        ),
        'timeout' => 15,
    ));

    if (is_wp_error($response)) {
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code >= 200 && $code < 300) {
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['site']['apiKey'])) {
            update_option('shorts_api_key', $body['site']['apiKey']);
        }
        if (isset($body['site']['verificationToken'])) {
            update_option('shorts_api_verification_token', $body['site']['verificationToken']);
            update_option('shorts_api_is_verified', $body['site']['isVerified'] ? '1' : '0');
            update_option('shorts_api_dns_target', ($body['site']['dnsRecordTarget'] ?? ''));
        }
        update_option('shorts_api_registered', time());
        
        // After successful registration, sync configurations
        shorts_api_sync_configs();
        
        return true;
    }

    return false;
}

/**
 * Verify Site with Express Backend
 */
function shorts_api_verify_site_with_backend()
{
    $domain = shorts_api_get_current_domain();
    $api_key = get_option('shorts_api_key');

    $response = wp_remote_post(SHORTS_EXPRESS_API . '/verify-site', array(
        'body' => json_encode(array('domain' => $domain)),
        'headers' => array(
            'Content-Type' => 'application/json',
            'X-API-Key' => $api_key
        ),
        'timeout' => 15,
    ));

    if (is_wp_error($response)) {
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code >= 200 && $code < 300) {
        update_option('shorts_api_is_verified', '1');
        return true;
    }

    return false;
}

/**
 * Synchronization Logic
 */
function shorts_api_sync_post($post_id)
{
    if (wp_is_post_revision($post_id))
        return;

    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'post')
        return;

    // Send to Express API
    $video_attachments = get_children(array(
        'post_parent' => $post_id,
        'post_type' => 'attachment',
        'post_mime_type' => 'video',
    ));

    $video_urls = array();
    foreach ($video_attachments as $video) {
        $video_urls[] = wp_get_attachment_url($video->ID);
    }

    // fallback: scan content if no attachments found
    if (empty($video_urls)) {
        $content = $post->post_content;
        // Match <source src="..."> or direct <video src="..."> or even <a> hrefs to videos
        preg_match_all('/(?:src|href)=["\']([^"\']+\.(?:mp4|webm|ogg|m4v|mov)(?:\?[^"\']*)?)["\']/i', $content, $matches);
        if (!empty($matches[1])) {
            $video_urls = array_values(array_unique($matches[1]));
        }
    }

    if (empty($video_urls) || $post->post_status !== 'publish') {
        shorts_api_delete_post($post_id);
        return;
    }

    $categories = get_the_category($post_id);
    $cat_data = array_map(function ($c) {
        return array('id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug); }, $categories);

    $tags = get_the_tags($post_id);
    $tag_data = $tags ? array_map(function ($t) {
        return array('id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug); }, $tags) : array();

    $data = array(
        'shortId' => (string) $post_id,
        'domain' => shorts_api_get_current_domain(),
        'title' => $post->post_title,
        'excerpt' => get_the_excerpt($post_id),
        'status' => $post->post_status,
        'categories' => $cat_data,
        'tags' => $tag_data,
        'post_url' => get_permalink($post_id),
        'video_urls' => $video_urls,
        'featured_image' => get_the_post_thumbnail_url($post_id, 'full') ?: '',
        'post_date' => (get_post_datetime($post, 'gmt') ? get_post_datetime($post, 'gmt')->format('c') : gmdate('c', strtotime($post->post_date_gmt))),
        'author_id' => $post->post_author,
    );

    $api_key = get_option('shorts_api_key');

    // Remote Sync to Express API
    $response = wp_remote_post(SHORTS_EXPRESS_API . '/sync', array(
        'body' => json_encode($data),
        'headers' => array(
            'Content-Type' => 'application/json',
            'X-API-Key' => $api_key
        ),
        'timeout' => 15,
        'blocking' => true,
        'sslverify' => false,
    ));

    if (is_wp_error($response)) {
        error_log('Shorts API Sync Error: ' . $response->get_error_message());
    } else {
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $body = wp_remote_retrieve_body($response);
            error_log('Shorts API Sync HTTP Error: ' . $code . ' - ' . $body);
        }
    }
}

/**
 * Sync Configs Logic
 */
function shorts_api_sync_configs()
{
    $domain = shorts_api_get_current_domain();

    $cat_order = get_option('shorts_api_category_order', array());
    if (!is_array($cat_order)) {
        $cat_order = array_filter(array_map('absint', explode(',', (string) $cat_order)));
    }

    $detailed_cats = array();
    foreach ($cat_order as $cat_id) {
        $term = get_term($cat_id, 'category');
        if ($term && !is_wp_error($term)) {
            $detailed_cats[] = array(
                'id' => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug
            );
        }
    }

    $data = array(
        'domain' => $domain,
        'logo_url' => shorts_api_get_logo_url(),
        'category_order' => $detailed_cats,
        'site_title' => shorts_api_get_site_title(),
        'sidebar_title' => shorts_api_get_sidebar_title(),
        'favicon_url' => shorts_api_get_favicon_url(),
        'ga_id' => get_option('shorts_api_ga_id', defined('SHORTS_API_GAID') ? SHORTS_API_GAID : ''),
        'sidebar_bg_color' => get_option('shorts_api_sidebar_bg_color', ''),
    );

    $api_key = get_option('shorts_api_key');

    $response = wp_remote_post(SHORTS_EXPRESS_API . '/sync-configs', array(
        'body' => json_encode($data),
        'headers' => array(
            'Content-Type' => 'application/json',
            'X-API-Key' => $api_key
        ),
        'timeout' => 15,
        'blocking' => true,
        'sslverify' => false,
    ));

    if (is_wp_error($response)) {
        error_log('Shorts API Config Sync Error: ' . $response->get_error_message());
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        $body = wp_remote_retrieve_body($response);
        error_log('Shorts API Config Sync HTTP Error: ' . $code . ' - ' . $body);
        return false;
    }

    return true;
}

function shorts_api_delete_post($post_id)
{
    $api_key = get_option('shorts_api_key');
    wp_remote_request(SHORTS_EXPRESS_API . '/' . $post_id . '?domain=' . shorts_api_get_current_domain(), array(
        'method' => 'DELETE',
        'headers' => array('X-API-Key' => $api_key),
        'blocking' => false,
    ));
}

/**
 * Hooks for Real-time Sync
 */
add_action('wp_after_insert_post', 'shorts_api_sync_post', 10, 1);
add_action('delete_post', 'shorts_api_delete_post');

/**
 * Settings Page
 */
add_action('admin_menu', function () {
    add_menu_page(
        'Shorts API Settings',
        'Shorts API',
        'manage_options',
        'shorts-api-settings',
        'shorts_api_settings_page_html',
        'dashicons-video-alt3'
    );
});

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_shorts-api-settings')
        return;

    wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');
    wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array('jquery'));
    wp_enqueue_script('jquery-ui-sortable');
    wp_enqueue_style('wp-color-picker');
    wp_enqueue_script('wp-color-picker');
    wp_enqueue_media();

    add_action('admin_footer', function () {
        ?>
        <script>
            jQuery(document).ready(function ($) {
                // Initialize Sortable
                $("#shorts-cat-list").sortable({
                    placeholder: "ui-state-highlight",
                    update: function (event, ui) {
                        updateCategoryIds();
                    }
                });

                // Search categories using Select2 as the search box
                $('.shorts-cat-search').select2({
                    placeholder: 'Buscar categoria...',
                    width: '300px'
                });

                // Add Category
                $('#add-shorts-cat').on('click', function (e) {
                    e.preventDefault();
                    var data = $('.shorts-cat-search').select2('data')[0];
                    if (!data) return;

                    if ($('#shorts-cat-list li[data-id="' + data.id + '"]').length > 0) {
                        alert('Esta categoria já está na lista.');
                        return;
                    }

                    var li = $('<li class="ui-state-default" data-id="' + data.id + '">' +
                        '<span class="dashicons dashicons-menu"></span>' +
                        '<span class="cat-text">' + data.text + '</span>' +
                        '<button type="button" class="remove-cat text-danger"><span class="dashicons dashicons-no-alt"></span></button>' +
                        '</li>');

                    $('#shorts-cat-list').append(li);
                    updateCategoryIds();
                });

                // Add All Categories
                $('#add-all-shorts-cat').on('click', function (e) {
                    e.preventDefault();
                    if (!confirm('Deseja adicionar todas as categorias disponíveis?')) return;

                    $('.shorts-cat-search option').each(function () {
                        var id = $(this).val();
                        var text = $(this).text();
                        if (!id) return;

                        if ($('#shorts-cat-list li[data-id="' + id + '"]').length === 0) {
                            var li = $('<li class="ui-state-default" data-id="' + id + '">' +
                                '<span class="dashicons dashicons-menu"></span>' +
                                '<span class="cat-text">' + text + '</span>' +
                                '<button type="button" class="remove-cat text-danger"><span class="dashicons dashicons-no-alt"></span></button>' +
                                '</li>');
                            $('#shorts-cat-list').append(li);
                        }
                    });
                    updateCategoryIds();
                });

                // Remove Category
                $(document).on('click', '.remove-cat', function () {
                    $(this).closest('li').remove();
                    updateCategoryIds();
                });

                function updateCategoryIds() {
                    var ids = [];
                    $('#shorts-cat-list li').each(function () {
                        ids.push($(this).data('id'));
                    });
                    $('#shorts_api_category_order_input').val(JSON.stringify(ids));
                }

                // Media Uploader
                $('.shorts-select-image').on('click', function (e) {
                    e.preventDefault();
                    var button = $(this);
                    var input = button.prev('input');
                    var uploader = wp.media({
                        title: 'Selecionar Imagem',
                        button: { text: 'Usar esta imagem' },
                        multiple: false
                    }).on('select', function () {
                        var attachment = uploader.state().get('selection').first().toJSON();
                        input.val(attachment.url);
                    }).open();
                });
            });

            function copyAndOpenGSC() {
                const email = 'firebase-adminsdk-fbsvc@shorts-api-2026.iam.gserviceaccount.com';
                navigator.clipboard.writeText(email).then(() => {
                    alert('E-mail da conta de serviço copiado para o clipboard!\n\nAgora adicione este e-mail como PROPRIETÁRIO (Owner) no Google Search Console que será aberto na lista de usuários.');
                    const domain = '<?php echo esc_js(shorts_api_get_current_domain()); ?>';
                    const gscUrl = 'https://search.google.com/search-console/users?resource_id=https://shorts.' + domain + '/';
                    window.open(gscUrl, '_blank');
                }).catch(err => {
                    alert('Erro ao copiar e-mail: ' + err);
                });
            }
        </script>
        <style>
            #shorts-cat-list {
                list-style: none;
                margin: 20px 0;
                padding: 0;
                max-width: 400px;
            }

            #shorts-cat-list .cat-text {
                width: 100%;
            }

            #shorts-cat-list li {
                background: #fff;
                border: 1px solid #c3c4c7;
                padding: 10px;
                margin-bottom: 5px;
                display: flex;
                align-items: center;
                cursor: move;
                border-radius: 4px;
                justify-content: space-between;
            }

            #shorts-cat-list li .dashicons-menu {
                color: #8c8f94;
                margin-right: 10px;
            }

            #shorts-cat-list li .remove-cat {
                background: none;
                border: none;
                cursor: pointer;
                color: #d63638;
                padding: 0;
            }

            #shorts-cat-list li .remove-cat:hover {
                color: #b32d2e;
            }

            .ui-state-highlight {
                height: 40px;
                background: #f0f0f1;
                border: 1px dashed #c3c4c7;
                margin-bottom: 5px;
            }

            .cat-controls {
                display: flex;
                gap: 10px;
                align-items: center;
                margin-top: 10px;
            }
        </style>
        <?php
    });
});

add_action('admin_init', function () {
    register_setting('shorts_api_settings', 'shorts_api_logo_url');
    register_setting('shorts_api_settings', 'shorts_api_category_order', array(
        'sanitize_callback' => function ($input) {
            if (is_string($input)) {
                $decoded = json_decode($input, true);
                return is_array($decoded) ? array_map('absint', $decoded) : array();
            }
            return is_array($input) ? array_map('absint', $input) : array();
        }
    ));
    register_setting('shorts_api_settings', 'shorts_api_site_title');
    register_setting('shorts_api_settings', 'shorts_api_sidebar_title');
    register_setting('shorts_api_settings', 'shorts_api_favicon_url');
    register_setting('shorts_api_settings', 'shorts_api_ga_id');
    register_setting('shorts_api_settings', 'shorts_api_sidebar_bg_color', array(
        'sanitize_callback' => 'sanitize_hex_color'
    ));

    // Sync configs every time settings are saved (detected by redirect param)
    if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
        // Only sync if we're on the shorts settings page
        if (
            isset($_GET['page']) && $_GET['page'] === 'shorts-api-settings'
            || (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'shorts-api-settings') !== false)
        ) {
            shorts_api_sync_configs();
        }
    }

    add_settings_section(
        'shorts_api_main_section',
        'Configurações Principais',
        null,
        'shorts-api-settings'
    );

    add_settings_field(
        'shorts_api_logo_url',
        'URL da Logo',
        function () {
            $auto = shorts_api_get_auto_logo();
            $value = get_option('shorts_api_logo_url', $auto);
            echo '<input type="text" name="shorts_api_logo_url" id="shorts_api_logo_url" value="' . esc_attr($value) . '" class="regular-text">';
            echo ' <button type="button" class="button button-secondary shorts-select-image">Selecionar Imagem</button>';
            echo '<p class="description">Deixe vazio para usar automaticamente a logo cadastrada no tema.' . ($auto ? ' <br>Detectado: <code>' . esc_html($auto) . '</code>' : ' <em>(Nenhuma logo detectada no tema)</em>') . '</p>';
        },
        'shorts-api-settings',
        'shorts_api_main_section'
    );

    add_settings_field(
        'shorts_api_category_order',
        'Seleção de Categorias',
        function () {
            $saved_value = get_option('shorts_api_category_order', array());
            if (!is_array($saved_value)) {
                $saved_value = array_filter(array_map('absint', explode(',', (string) $saved_value)));
            }

            $all_categories = get_categories(array('hide_empty' => 0));
            $cat_map = array();
            foreach ($all_categories as $cat) {
                $cat_map[$cat->term_id] = $cat;
            }

            echo '<div class="cat-controls">';
            echo '<select class="shorts-cat-search">';
            echo '<option value=""></option>';
            foreach ($all_categories as $cat) {
                echo '<option value="' . $cat->term_id . '">' . esc_html($cat->name) . '</option>';
            }
            echo '</select>';
            echo '<button type="button" id="add-shorts-cat" class="button button-secondary">Adicionar +</button>';
            echo '<button type="button" id="add-all-shorts-cat" class="button button-link" style="margin-left: 10px;">Adicionar Todas</button>';
            echo '</div>';

            echo '<ul id="shorts-cat-list">';
            foreach ($saved_value as $cat_id) {
                if (isset($cat_map[$cat_id])) {
                    echo '<li class="ui-state-default" data-id="' . $cat_id . '">';
                    echo '<span class="dashicons dashicons-menu"></span>';
                    echo '<span class="cat-text">' . esc_html($cat_map[$cat_id]->name) . '</span>';
                    echo '<button type="button" class="remove-cat"><span class="dashicons dashicons-no-alt"></span></button>';
                    echo '</li>';
                }
            }
            echo '</ul>';

            echo '<input type="hidden" name="shorts_api_category_order" id="shorts_api_category_order_input" value="' . esc_attr(json_encode($saved_value)) . '">';
            echo '<p class="description">Busque uma categoria, adicione-a e arraste para reordenar como quiser.</p>';
        },
        'shorts-api-settings',
        'shorts_api_main_section'
    );

    add_settings_field(
        'shorts_api_site_title',
        'Título do Site',
        function () {
            $value = get_option('shorts_api_site_title', '');
            echo '<input type="text" name="shorts_api_site_title" value="' . esc_attr($value) . '" class="regular-text" placeholder="' . esc_attr(get_bloginfo('name')) . '">';
            echo '<p class="description">T&iacute;tulo SEO do site (usado no &lt;title&gt;). Padr&atilde;o: <code>' . esc_html(get_bloginfo('name')) . '</code></p>';
        },
        'shorts-api-settings',
        'shorts_api_main_section'
    );

    add_settings_field(
        'shorts_api_sidebar_title',
        'Texto da Sidebar',
        function () {
            $value = get_option('shorts_api_sidebar_title', '');
            echo '<input type="text" name="shorts_api_sidebar_title" value="' . esc_attr($value) . '" class="regular-text">';
            echo '<p class="description">Texto exibido na lateral do app e cabe&ccedil;alho m&oacute;vel.</p>';
        },
        'shorts-api-settings',
        'shorts_api_main_section'
    );

    add_settings_field(
        'shorts_api_favicon_url',
        'URL do Favicon',
        function () {
            $value = get_option('shorts_api_favicon_url', '');
            $auto = shorts_api_get_auto_favicon();
            echo '<input type="text" name="shorts_api_favicon_url" id="shorts_api_favicon_url" value="' . esc_attr($value) . '" class="regular-text">';
            echo ' <button type="button" class="button button-secondary shorts-select-image">Selecionar Imagem</button>';
            echo '<p class="description">Deixe vazio para usar o favicon cadastrado no site.' . ($auto ? ' <br>Detectado: <code>' . esc_html($auto) . '</code>' : ' <em>(Nenhum favicon detectado)</em>') . '</p>';
        },
        'shorts-api-settings',
        'shorts_api_main_section'
    );

    add_settings_field(
        'shorts_api_ga_id',
        'Google Analytics ID (GA4)',
        function () {
            $value = get_option('shorts_api_ga_id', defined('SHORTS_API_GAID') ? SHORTS_API_GAID : '');
            echo '<input type="text" name="shorts_api_ga_id" value="' . esc_attr($value) . '" class="regular-text" placeholder="G-XXXXXXXXXX">';
            echo '<p class="description">Insira o ID de medição do GA4 para habilitar o rastreamento no frontend.</p>';
        },
        'shorts-api-settings',
        'shorts_api_main_section'
    );

    add_settings_field(
        'shorts_api_sidebar_bg_color',
        'Cor de fundo da Sidebar',
        function () {
            $value = get_option('shorts_api_sidebar_bg_color', '');
            echo '<input type="text" name="shorts_api_sidebar_bg_color" value="' . esc_attr($value) . '" class="shorts-color-picker" data-default-color="#18181b">';
            echo '<p class="description">Escolha a cor de fundo da barra lateral. Deixe vazio para usar o padr&atilde;o escuro.</p>';
            echo '<script>jQuery(document).ready(function($){$(".shorts-color-picker").wpColorPicker();});</script>';
        },
        'shorts-api-settings',
        'shorts_api_main_section'
    );

    // Auto-register site if not done
    if (!get_option('shorts_api_registered')) {
        shorts_api_register_site_with_backend();
    }

    // Handle manual sync button
    if (isset($_POST['shorts_api_sync_domain']) && check_admin_referer('shorts_api_sync_domain_action')) {
        if (shorts_api_register_site_with_backend()) {
            add_settings_error('shorts_api_messages', 'shorts_api_message', 'Site publicado com sucesso! Agora siga as instruções de verificação abaixo.', 'updated');
        } else {
            add_settings_error('shorts_api_messages', 'shorts_api_message', 'Erro ao publicar site.', 'error');
        }
    }

    // Handle verification button
    if (isset($_POST['shorts_api_verify_domain']) && check_admin_referer('shorts_api_verify_domain_action')) {
        if (shorts_api_verify_site_with_backend()) {
            add_settings_error('shorts_api_messages', 'shorts_api_message', 'Domínio verificado com sucesso! 🎉', 'updated');
        } else {
            add_settings_error('shorts_api_messages', 'shorts_api_message', 'Erro na verificação. Certifique-se de que o registro TXT foi adicionado corretamente e aguarde a propagação.', 'error');
        }
    }
});

function shorts_api_settings_page_html()
{
    $token = get_option('shorts_api_verification_token', '');
    $dns_target = get_option('shorts_api_dns_target', '');
    $is_verified = get_option('shorts_api_is_verified', '0') === '1';
    ?>
    <div class="wrap">
        <h1>Configurações do Shorts API</h1>
        <?php settings_errors('shorts_api_messages'); ?>
        <form action="options.php" method="post">
            <?php
            settings_fields('shorts_api_settings');
            do_settings_sections('shorts-api-settings');
            submit_button();
            ?>
        </form>

        <hr>
        <h2>Publicação e Verificação de Site</h2>

        <?php if (!$token): ?>
            <p>Seu site ainda não foi publicado no backend. Clique no botão abaixo para gerar seus dados de verificação.</p>
            <form method="post">
                <?php wp_nonce_field('shorts_api_sync_domain_action'); ?>
                <input type="hidden" name="shorts_api_sync_domain" value="1">
                <?php submit_button('Publicar Site', 'primary'); ?>
            </form>
        <?php else: ?>
            <div class="card"
                style="max-width: 600px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h3 style="margin-top: 0;">Status de Verificação:
                    <?php if ($is_verified): ?>
                        <span style="color: #46b450;">✅ Verificado</span>
                    <?php else: ?>
                        <span style="color: #dc3232;">❌ Não Verificado</span>
                    <?php endif; ?>
                </h3>

                <p>Configure os registros DNS abaixo no seu provedor (Cloudflare, Registro.br, etc.) para garantir que seu site
                    funcione corretamente:</p>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Nome (Host)</th>
                            <th>Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>TXT</code></td>
                            <td style="color: #646970;"><code>shorts</code></td>
                            <td><code style="word-break: break-all;"><?php echo esc_html($token); ?></code></td>
                        </tr>
                        <tr>
                            <td><code>A/CNAME</code></td>
                            <td style="color: #646970;"><code>shorts</code></td>
                            <td><code><?php echo esc_html($dns_target); ?></code></td>
                        </tr>
                    </tbody>
                </table>

                <?php if (!$is_verified): ?>
                    <p class="description">Após adicionar os registros acima, clique no botão abaixo para ativar seu site.</p>

                    <form method="post" style="margin-top: 20px;">
                        <?php wp_nonce_field('shorts_api_verify_domain_action'); ?>
                        <input type="hidden" name="shorts_api_verify_domain" value="1">
                        <?php submit_button('Verificar Agora', 'secondary'); ?>
                    </form>
                <?php else: ?>
                    <p style="margin-top: 20px; color: #46b450; font-weight: bold;">✅ Site publicado e verificado com sucesso.</p>
                    
                    <div style="background: #f0f7f1; border-left: 4px solid #46b450; padding: 15px; margin: 20px 0;">
                        <strong>Sitemap de Vídeos:</strong><br>
                        Seu sitemap já está disponível e otimizado para o Google em:<br>
                        <code>https://shorts.<?php echo esc_html(shorts_api_get_current_domain()); ?>/api/sitemap.xml</code>
                        <p class="description">Submeta esta URL no Google Search Console para acelerar a indexação dos seus vídeos.</p>
                        
                        <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #c3e6cb;">
                            <strong>Indexação Automática (Google API):</strong><br>
                            <p class="description">Para que novos vídeos sejam indexados instantaneamente sem fila, adicione nossa conta de serviço como <b>Proprietário</b> no Search Console.</p>
                            <button type="button" class="button button-primary" onclick="copyAndOpenGSC()">Configurar Indexação Automática</button>
                        </div>
                    </div>

                    <form method="post">
                        <?php wp_nonce_field('shorts_api_sync_domain_action'); ?>
                        <input type="hidden" name="shorts_api_sync_domain" value="1">
                        <?php submit_button('Atualizar Dados de Publicação', 'small'); ?>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * WP-CLI Command
 */

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('shorts sync', function ($args, $assoc_args) {
        $amount = isset($assoc_args['amount']) ? (int)$assoc_args['amount'] : 50;
        
        // Use wp_count_posts to get the total without loading all IDs first
        $count_data = wp_count_posts('post');
        $total = (int)$count_data->publish;

        if ($total === 0) {
            WP_CLI::error("Nenhum post publicado encontrado.");
            return;
        }

        WP_CLI::log("Iniciando sincronização de $total posts em lotes de $amount...");
        $progress = \WP_CLI\Utils\make_progress_bar('Sincronizando', $total);

        $pages = ceil($total / $amount);
        $synced = 0;

        for ($page = 1; $page <= $pages; $page++) {
            $post_ids = get_posts(array(
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => $amount,
                'paged' => $page,
                'fields' => 'ids',
                'orderby' => 'ID',
                'order' => 'DESC',
            ));

            if (empty($post_ids)) break;

            foreach ($post_ids as $id) {
                shorts_api_sync_post($id);
                WP_CLI::log("{$synced} de {$total} posts sincronizados...");
                $progress->tick();
                $synced++;
            }
            
            // Optional: clear memory/cache if needed
            // stop_the_insanity(); // Common WP-CLI helper for long-running scripts
        }

        $progress->finish();
        WP_CLI::success("Sincronização concluída! $synced posts sincronizados.");
    });

    WP_CLI::add_command('shorts sync-domain', function ($args, $assoc_args) {
        WP_CLI::log("Iniciando sincronização do domínio...");
        if (shorts_api_register_site_with_backend()) {
            WP_CLI::success("Domínio sincronizado com sucesso e configurado no Traefik!");
        } else {
            WP_CLI::error("Erro ao sincronizar domínio com o backend.");
        }
    });

    WP_CLI::add_command('shorts sync-configs', function ($args, $assoc_args) {
        WP_CLI::log("Iniciando sincronização de configurações...");
        if (shorts_api_sync_configs()) {
            WP_CLI::success("Configurações sincronizadas com sucesso!");
        } else {
            WP_CLI::error("Erro ao sincronizar configurações.");
        }
    });
}

/**
 * Custom Update System
 */
add_filter('pre_set_site_transient_update_plugins', 'shorts_api_check_for_update');

function shorts_api_check_for_update($transient)
{
    if (empty($transient->checked)) {
        return $transient;
    }

    $plugin_slug = plugin_basename(__FILE__);
    $current_version = $transient->checked[$plugin_slug] ?? '0.0.0';

    // Fetch update.json from GitHub (raw content)
    $url = 'https://raw.githubusercontent.com/albreis/shorts-api/main/update.json';
    $response = wp_remote_get($url, array('timeout' => 10));

    if (is_wp_error($response)) {
        return $transient;
    }

    $data = json_decode(wp_remote_retrieve_body($response));
    if (!$data || !isset($data->version)) {
        return $transient;
    }

    if (version_compare($current_version, $data->version, '<')) {
        $obj = new stdClass();
        $obj->slug = 'shorts-api';
        $obj->plugin = $plugin_slug;
        $obj->new_version = $data->version;
        $obj->url = 'https://github.com/albreis/shorts-api';
        $obj->package = $data->download_url;
        $obj->tested = '6.4'; // Update as needed
        
        $transient->response[$plugin_slug] = $obj;
    }

    return $transient;
}

// Show plugin info in the "View details" modal
add_filter('plugins_api', 'shorts_api_plugin_info', 20, 3);
function shorts_api_plugin_info($res, $action, $args)
{
    if ($action !== 'plugin_information') return $res;
    if ($args->slug !== 'shorts-api') return $res;

    $url = 'https://raw.githubusercontent.com/albreis/shorts-api/main/update.json';
    $response = wp_remote_get($url);
    if (is_wp_error($response)) return $res;

    $data = json_decode(wp_remote_retrieve_body($response));
    
    $res = new stdClass();
    $res->name = 'Shorts API';
    $res->slug = 'shorts-api';
    $res->version = $data->version ?? '2.2.21';
    $res->author = '<a href="https://albreis.github.io/shorts-api/">ER Soluções Web</a>';
    $res->homepage = 'https://github.com/albreis/shorts-api';
    $res->download_link = $data->download_url ?? '';
    $res->sections = array(
        'description' => 'Create a shorts page for videos.',
        'changelog' => 'Veja o histórico de releases no GitHub.'
    );

    return $res;
}
