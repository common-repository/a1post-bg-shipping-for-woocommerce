<?php
/*
Plugin Name: A1POST.BG Shipping PlugIn WooCommerce
Plugin URI: https://a1post.bg
Description: A1POST.BG Shipping - Integrate A1Post courier to WooCommerce. To use the plugin you should have a valid credentials for A1Post if you still don't have an account, please register on https://a1post.bg. The plugin use API requests to create shipping label, print labels and manage shipments. By using the plugin you accept the TOS of A1Post https://a1post.bg/OU_npu.pdf
Version: 1.5
Author: A1POST.BG
Author URI: https://a1post.bg
*/

if (!defined('ABSPATH')) exit;
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
  function a1admin_warning($msg) {
    echo '<div class="notice notice-error"><p>'.esc_html($msg).'</p></div>';
  }

  add_action('manage_shop_order_posts_custom_column', 'a1post_order_column');
  add_action('admin_menu', 'a1post_admin_menu');
  add_action('add_meta_boxes', 'a1post_ship_order_boxf');
  add_action('admin_init', 'a1post_window');
  add_action('admin_init', 'a1post_window_print');
  add_action('admin_head', 'a1post_css');
  add_action('a1post_del_track_but', 'a1post_del_track');
  add_filter('manage_edit-shop_order_columns', 'a1post_new_order_column');
  add_filter('plugin_action_links_' . plugin_basename(__FILE__) , 'a1post_action_links');
  add_filter('bulk_actions-edit-shop_order', 'a1post_bulk_action', 20, 1);
  add_filter('handle_bulk_actions-edit-shop_order', 'a1post_bulk_action_handle', 10, 3);
  if (get_option('a1post_u') != '')	$user		= get_option('a1post_u');  else a1admin_warning('Моля, въведете потребителско име в "A1POST Настройки"');
  if (get_option('a1post_p') != '')	$pass		= get_option('a1post_p');  else a1admin_warning('Моля, въведете парола в "A1POST Настройки"');
  if (get_option('a1post_lbl') != '')	$lbl_type	= get_option('a1post_lbl');else a1admin_warning('Моля, въведете формат на етикета в "A1POST Настройки"');
  if (get_option('a1post_st') != '')	$ordar_state	= get_option('a1post_st'); else $ordar_state = 1;
  $args = ['headers' => ['Authorization: Basic ' => base64_encode("$user:$pass")]];

  function a1post_del_track($actions) {
    global $pagenow, $typenow;
    if ('shop_order' === $typenow && 'edit.php' === $pagenow && isset($_GET['import_courier']) && $_GET['import_courier'] === 'yes') {
      echo 'del';
    }
  }
  function a1post_bulk_action( $actions ) {
    $actions['a1post_bulk_print'] = 'A1POST Print';
    echo '<script>var e=document.getElementById("posts-filter");e.onsubmit=function(){if(document.getElementById("bulk-action-selector-top").value=="a1post_bulk_print")e.target="_blank";else e.target="";};</script>';
    return $actions;
  }
  
  function a1post_bulk_action_handle($redirect_to, $action, $post_ids) {
    if ($action !== 'a1post_bulk_print') return $redirect_to;
    return $redirect_to = add_query_arg(['a1post_batch' => implode(',', $post_ids)], $redirect_to);
  }
  
  function a1post_window_print() {
    global $args, $user, $pass, $lbl_type;
    if (isset($_GET['a1post_batch'])) {
      if (!is_user_logged_in()) exit;
      $batch = preg_replace('/\D/', '', (int) $_GET['a1post_batch']);
      $orders = explode(',', $batch);
      foreach ($orders as $order_id) {
        $order = new WC_Order($order_id);
        $trk = $order->get_meta('a1post_track', true, 'edit');
        if (!empty($trk)) $trks[] = $trk;
      }
      $trks = implode(',', $trks);
      $c = wp_remote_get("https://api.a1post.bg/print.php?track=$trks&lbl=$lbl_type", $args);
      $http_code = wp_remote_retrieve_response_code($c);
      if ($http_code == "401") die('Грешно потребителско име/парола!');
      $d = json_decode(wp_remote_retrieve_body($c));
      if (!empty($d->error)) die($d->error->message);
      if (!empty($d->lbl)) {
        header("Content-type:application/pdf");
        echo base64_decode($d->lbl);
      }
      exit;
    }
  }
  
  function a1post_window() {
    global $args, $user, $pass, $lbl_type, $ordar_state;
    if (isset($_GET['a1post'])) {
      $nonce = key_exists('_wpnonce', $_GET) ? $_GET['_wpnonce'] : '';
      if (!(wp_verify_nonce($nonce, 'A1P0st-bg')) || !(is_user_logged_in())) exit;
      $orders = explode(',', preg_replace('/\D/', '', (int) $_GET['o']));
      $total_wgh = 0;
      $notes = '';
      foreach ($orders as $order_id) {
        $order = new WC_Order($order_id);
        $items = $order->get_items();
        $total_qty = $total_weight = 0;
        foreach ($items as $item) {
          $p = $item->get_product();
          $p_wgh = floatval($p->get_weight());
          $qty = floatval($item->get_quantity());
          $total_qty += $qty;
          $total_wgh += floatval($p_wgh * $qty);
          $notes .= "{$item['name']}. ";
        }
        $id = $order->get_id();
        $shipping_country = get_post_meta($id, '_shipping_country', true);
        $shipping_state = get_post_meta($id, '_shipping_state', true);
        if (!empty($order->shipping_company)) {
          $addr1 = $order->shipping_company;
          $addr2 = $order->shipping_address_1;
          $addr3 = $order->shipping_address_2;
        }
        else {
          $addr1 = $order->shipping_address_1;
          $addr2 = $order->shipping_address_2;
          $addr3 = '';
        }
        $tel = empty($order->billing_phone) ? $order->shipping_phone : $order->billing_phone;
        $email = empty($order->billing_email) ? $order->shipping_email : $order->billing_email;
        $data = ['name' => "$order->shipping_first_name $order->shipping_last_name", 'addr1' => $addr1, 'addr2' => $addr2, 'addr3' => $addr3, 'city' => $order->shipping_city, 'state' => $shipping_state, 'zip' => $order->shipping_postcode, 'iso' => $shipping_country, 'tel' => $tel, 'email' => $email, 'notes' => 'WOO- ' . $notes, 'weight' => $total_wgh, 'lbl' => $lbl_type, 'serv' => sanitize_text_field($_GET['serv'])];
      }

      if (isset($_GET['print'])) $c = wp_remote_get("https://api.a1post.bg/print.php?track={$_GET['print']}&lbl=$lbl_type", $args);
      else {
        $args['timeout'] = 15;
        $args['body'] = $data;
        $c = wp_remote_post("https://api.a1post.bg/", $args);
      }
      $http_code = wp_remote_retrieve_response_code($c);
      if ($http_code == "401") die('Грешно потребителско име/парола!');
      $d = json_decode(wp_remote_retrieve_body($c));
      if (isset($d->lbl)) {
        if (!isset($_GET['print'])) {
          update_post_meta($order_id, 'a1post_track', (string)$d->track);
          if (!empty($ordar_state)) $order->update_status($ordar_state);
        }
      }
      die(wp_remote_retrieve_body($c));
    }
  }
  
  function a1post_new_order_column($columns) {
    $columns['a1post'] = 'A1POST';
    return $columns;
  }
  
  function a1post_admin_menu() {
    global $a1post_settings_page;
    $a1post_settings_page = add_submenu_page('woocommerce', 'A1POST Настройки', 'A1POST Настройки', 'manage_woocommerce', 'a1post_admin_settings', 'a1post_admin_settings_page');
  }
  function a1post_admin_settings_page() {
    if (!current_user_can('manage_woocommerce')) die("You are not authorized to view this page");
    $lbl_options = get_option('a1post_lbl');
    $st_options = get_option('a1post_st');
    if (isset($_POST['a1post_settings_submitted']) && $_POST['a1post_settings_submitted'] == 'submitted') {
      foreach ($_POST as $key => $value) {
        if (get_option($key) != $value) update_option($key, $value);
        else $status = add_option($key, $value, '', 'no');
      }
      echo '<div id="message" class="updated"><p><b>Промените са записани</b></p></div>';
    }
  
  ?>
<div class="wrap">
<h2>A1POST Настройки</h2>
<form method="post" action=""><input type="hidden" name="a1post_settings_submitted" value="submitted">
<h3>Попълнете всички полета</h3>
<table class="form-table">
<tr><th scope="row"><label for="a1post_u">Потребителско име</label></th><td><input type="text" id="a1post_u" name="a1post_u" value="<?php echo get_option('a1post_u'); ?>" /></td></tr>
<tr><th scope="row"><label for="a1post_p">Парола</label></th><td><input type="password" id="a1post_p" name="a1post_p" value="<?php echo get_option('a1post_p'); ?>" /></td></tr>
<tr><th scope="row">Формат на етикета</th><td>
<p><label for="a1post_lbl_a4">A4 PDF</label> <input type="radio" id="a1post_lbl_a4" name="a1post_lbl" value="pdf_a4" <?php checked('pdf_a4', $lbl_options); ?>></p>
<p><label for="a1post_lbl_a6">A6 PDF</label> <input type="radio" id="a1post_lbl_a6" name="a1post_lbl" value="pdf_a6" <?php checked('pdf_a6', $lbl_options); ?>></p>
<p><label for="a1post_lbl_bc">Barcode PDF</label> <input type="radio" id="a1post_lbl_bc" name="a1post_lbl" value="pdf_lbl_barcode" <?php checked('pdf_lbl_barcode', $lbl_options); ?>></p>
<p><label for="a1post_lbl_a4bc">A4 Barcode PDF</label> <input type="radio" id="a1post_lbl_a4bc" name="a1post_lbl" value="pdf_a4_barcode" <?php checked('pdf_a4_barcode', $lbl_options); ?>></p>
</td></tr>
<tr><th scope="row"><label for="a1post_sts">Маркирай поръчката като:<br>(след генериране на етикет)</label></th>
<td>
<p><select name="a1post_st"><option value=''></option>
<?php
  $statuses = wc_get_order_statuses();
  foreach ($statuses as $k => $v) {
    $s = str_replace('wc-', '', $k);
    if (strpos('cancelled refunded checkout-draft', $s) !== false) continue;
    echo "<option value='$s' ".selected($s, $st_options).">$v</option>";
  }
?>
</select></p>
</td></tr>
</table>
<?php
submit_button(); 
?>
</form>
<p><b>Може да използвате 'Custom Order Status Manager for WooCommerce' или друг plug-in, за да създадете статуси по ваш избор.</b></p>
</div>
  <?php
  }
  function a1post_order_column($column) {
    global $post;
    if ('a1post' === $column) {
      $order = wc_get_order($post->ID);
      $trk = $order->get_meta('a1post_track', true, 'edit');
      echo "<a href='https://a1post.bg/track/".esc_html($trk)."' target='_blank'>".esc_html($trk)."</a>";
    }
  }
  
  function a1post_ship_order_box() {
    global $post, $args;
    $order = wc_get_order($post->ID);
    $trk = $order->get_meta('a1post_track', true, 'edit');
    if (isset($_GET['a1post_del'])) {
      if (get_option('a1post_u') != '') $user = get_option('a1post_u'); else die('Моля, въведете потребителско име в "A1POST Настройки"');
      if (get_option('a1post_p') != '') $pass = get_option('a1post_p'); else die('Моля, въведете парола в "A1POST Настройки"');
      if ($_GET['a1post_del'] == $trk) {
        $args['method'] = 'DELETE';
        $args['timeout'] = 15;
        $args['body'] = "trk=$trk";
        $c = wp_remote_post("https://api.a1post.bg", $args);
        $http_code = wp_remote_retrieve_response_code($c);
        if ($http_code == "401") die('Грешно потребителско име/парола!');
        $d = json_decode(wp_remote_retrieve_body($c));
        if (isset($d->error)) die($d->error->message);
        update_post_meta($order->id, 'a1post_track', '');
        $order->update_status('processing');
        $trk = '';
      }
    }
    if (!empty($trk)) echo "<p><a href='https://a1post.bg/track/".esc_html($trk)."' target='_blank'>".esc_html($trk)."</a></p><p><a class='button button-primary' target='_blank' href='?a1post_batch={$order->id}'>Принтирай етикет</a></p><br><p>Анулирай текущата и генерирай нова товарителница:</p><p><a class='button button-primary' href='post.php?post={$post->ID}&action=edit&a1post_del=".esc_html($trk)."'>Нова товарителница</a></p>";
    else echo '
<p><button class="button button-primary" onclick="a1open(\'L\')">С проследяване</button></p>
<p><button class="button button-primary" onclick="a1open(\'R\')">С проследяване + подпис</button></p>
<p><button class="button button-primary" onclick="a1open(\'U\')">Без проследяване</button></p>
<p><button class="button button-primary" onclick="a1open(\'ups\')">UPS Express</button></p>
<p><button class="button button-primary" onclick="a1open(\'upsS\')">UPS Икономична</button></p>
<p><button class="button button-primary" onclick="a1open(\'dhl\')">DHL Express</button></p>
<script>function a1open(service){jQuery("#woocommerce-shipment-a1post").html("<div align=\'center\'><div class=\'a1post-loader\'></div><h2 style=\'color:darkred\'>Зарежда, моля изчакайте.</h2></div>");jQuery.get("?", {a1post: 1, o: "'.$order->id.'", serv: service, _wpnonce: "'.wp_create_nonce('A1P0st-bg').'"}, function(d) {d = JSON.parse(d); if (d.lbl) {jQuery("#order_data").append("<div><object id=\"a1post-pdf\" type=\"application/pdf\" data=\"data:application/pdf;base64,"+d.lbl+"\"></object></div>");jQuery("#woocommerce-shipment-a1post").html("<div class=\'inside\'><p><a href=\'https://a1post.bg/track/"+d.track+"\' target=\'_blank\'>"+d.track+"</a></p><p><a class=\'button button-primary\' target=\'_blank\' href=\'?a1post_batch='.$order->id.'\'>Принтирай етикет</a></p><br><p>Анулирай текущата и генерирай нова товарителница:</p><p><a class=\'button button-primary\' href=\'post.php?post='.$post->ID.'&action=edit&a1post_del="+d.track+"\'>Нова товарителница</a></p></div>");}else jQuery("#woocommerce-shipment-a1post").html("<h2 style=\'color:white;background:#ea3a3a\'>Error: "+d.error.message+"</h2>");});return false;}jQuery(document).on("keydown",function(e){if(e.which==13)return false;});</script>';
  }
  function a1post_css() {echo '<style type="text/css">object {width: 100%;min-height: 500px;margin: 15px 0;}.a1post-loader {height: 80px;}.a1post-loader:after {content: " ";display: block;width: 50px;height: 50px;margin: 8px;border-radius: 50%;border: 6px solid #e9aa31;border-color: #e9aa31 transparent #e9aa31 transparent;animation: a1post-loader 1.2s linear infinite;}@keyframes a1post-loader {0% {transform: rotate(0deg);}100% {transform: rotate(360deg);}}</style>';}
  function a1post_ship_order_boxf() {add_meta_box('woocommerce-shipment-a1post', 'A1POST Генерирай пратка', 'a1post_ship_order_box', 'shop_order', 'side', 'high');}
  function a1post_action_links($links) {return $links += ['<a href="' . admin_url('admin.php?page=a1post_admin_settings') . '">Настройки</a>', '<a href="https://a1post.bg" target="_blank">A1POST.BG</a>'];}
}
?>