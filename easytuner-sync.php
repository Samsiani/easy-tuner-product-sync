<?php
/**
 * Plugin Name: EasyTuner Product Sync (V1.5 - Final Image Fix)
 * Description: Fixes "A valid URL was not provided" by using manual download.
 * Version: 1.5
 */

if (!defined('ABSPATH')) exit;

function et_get_api_token() {
    $url = 'https://easytuner.net:8090/User/Login';
    $response = wp_remote_post($url, array(
        'sslverify' => false,
        'body'      => array('Email' => 'easytuner01', 'Password' => 'easytuner01'),
    ));
    if (is_wp_error($response)) return false;
    $body = json_decode(wp_remote_retrieve_body($response), true);
    return isset($body['token']) ? $body['token'] : false;
}

// გაუმჯობესებული ფუნქცია - ხელით გადმოწერა
function et_download_and_set_image($url, $post_id) {
    if (empty($url)) return "Empty URL";

    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    // 1. ფაილის წამოღება
    $response = wp_remote_get($url, array(
        'sslverify' => false,
        'timeout'   => 30
    ));

    if (is_wp_error($response)) {
        return "მოთხოვნის შეცდომა: " . $response->get_error_message();
    }

    $image_contents = wp_remote_retrieve_body($response);
    $response_code   = wp_remote_retrieve_response_code($response);

    if ($response_code !== 200 || empty($image_contents)) {
        return "ვერ გადმოიწერა. კოდი: $response_code";
    }

    // 2. დროებითი ფაილის შექმნა
    $filename = basename($url);
    $upload = wp_upload_bits($filename, null, $image_contents);

    if ($upload['error']) {
        return "ფაილის შენახვის შეცდომა: " . $upload['error'];
    }

    // 3. მედია ბიბლიოთეკაში რეგისტრაცია
    $file_path = $upload['file'];
    $file_name = basename($file_path);
    $file_type = wp_check_filetype($file_name, null);

    $attachment = array(
        'post_mime_type' => $file_type['type'],
        'post_title'     => sanitize_file_name($file_name),
        'post_content'   => '',
        'post_status'    => 'inherit'
    );

    $attach_id = wp_insert_attachment($attachment, $file_path, $post_id);
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
    wp_update_attachment_metadata($attach_id, $attach_data);

    set_post_thumbnail($post_id, $attach_id);
    return "წარმატებულია! ID: " . $attach_id;
}

function et_process_single_item($item, $inventory_name) {
    $sku = $item['id'];
    $product_id = wc_get_product_id_by_sku($sku);

    if ($product_id) {
        $product = wc_get_product($product_id);
    } else {
        $product = new WC_Product_Simple();
        $product->set_sku($sku);
    }

    $product->set_name($item['name']);
    $product->set_regular_price($item['sellingPrice']);
    $product->set_stock_quantity((int)$item['stock']);
    $product->set_manage_stock(true);
    $product->set_status('publish');

    $category = get_term_by('name', $inventory_name, 'product_cat');
    $cat_id = $category ? $category->term_id : wp_insert_term($inventory_name, 'product_cat')['term_id'];
    $product->set_category_ids(array($cat_id));

    $saved_id = $product->save();

    $image_log = "No Image URL";
    if (!empty($item['photoIds']) && isset($item['photoIds'][0])) {
        $image_log = et_download_and_set_image($item['photoIds'][0], $saved_id);
    }

    return array('id' => $saved_id, 'log' => $image_log);
}

add_action('admin_menu', function() {
    add_menu_page('EasyTuner Sync', 'EasyTuner Sync', 'manage_options', 'et-sync', 'et_sync_page');
});

function et_sync_page() {
    ?>
    <div class="wrap">
        <h1>EasyTuner Sync (V1.5)</h1>
        <form method="post">
            <input type="submit" name="test_sync" class="button button-primary" value="1 პროდუქტის ტესტირება">
            <input type="submit" name="full_sync" class="button button-secondary" value="სრული სინქრონიზაცია">
        </form>
        <?php
        if (isset($_POST['test_sync']) || isset($_POST['full_sync'])) {
            $token = et_get_api_token();
            if (!$token) { echo "ტოკენის შეცდომა."; return; }

            $url = 'https://easytuner.net:8090/Data/GetAllInventories';
            $response = wp_remote_get($url, array('sslverify' => false, 'headers' => array('Authorization' => 'Bearer ' . $token)));
            $data = json_decode(wp_remote_retrieve_body($response), true);

            if (isset($_POST['test_sync'])) {
                $res = et_process_single_item($data[0]['items'][0], $data[0]['name']);
                echo "<div class='updated notice'><p>სტატუსი: <strong>" . $res['log'] . "</strong></p>
                <p><a href='".get_edit_post_link($res['id'])."' target='_blank'>პროდუქტის ნახვა</a></p></div>";
            }
            
            if (isset($_POST['full_sync'])) {
                $count = 0;
                foreach ($data as $inv) {
                    foreach ($inv['items'] as $item) {
                        et_process_single_item($item, $inv['name']);
                        $count++;
                    }
                }
                echo "<div class='updated notice'><p>განახლდა $count პროდუქტი.</p></div>";
            }
        }
        ?>
    </div>
    <?php
}