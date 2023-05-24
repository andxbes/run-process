<?php
/*
  Plugin Name: Выполняем какие-то длительные задачи
  Plugin URI:
  Description:
  Version: 1
  Author: andxbes (Bescenniy A.A.)
  Author URI:
  License: GPLv2
 */


abstract class Admin_Page
{
    protected $name, $menu_slug;

    public function __construct($name, $menu_slug)
    {
        $that = $this;
        $this->name = $name;
        $this->menu_slug = $menu_slug;

        add_action('admin_menu', function () {
            add_submenu_page("tools.php", $this->name, $this->name, 'manage_options', $this->menu_slug, array($this, 'add_admin_page'));
        });
    }

    abstract function add_admin_page();
}


class Process_Page extends Admin_Page
{
    protected $need_remove_images = [];
    protected $import_data_option_name = "process_data";
    protected $ajax_part_size;
    protected $processpart_notification = array();


    public function __construct()
    {
        parent::__construct("Запуск длительного задания", "process");

        add_action('wp_ajax_sending_part', array(&$this, 'sending_part'));
        add_action('wp_ajax_process_is_finish', array(&$this, 'process_is_finish'));

        if (defined('PROCESS_PART_SIZE') && intval(PROCESS_PART_SIZE) > 0) {
            $this->ajax_part_size = intval(PROCESS_PART_SIZE);
        } else {
            $this->ajax_part_size = 1;
        }
    }

    public function add_admin_page()
    {
?>
        <div class="wrap">
            <h2><?= $this->name ?></h2>
            <div>
                <p>
                    Если возникают проблемы при импорте, а именно не хватает времени на выполнение запроса . Нужно
                    прописать константу в <b>wp-config.php</b></p>
                <pre>define('PROCESS_PART_SIZE', 2);</pre>
                <p>По уммолчанию = <?= $this->ajax_part_size ?> элементов за один запрос</p>
            </div>


            <?php
            //TODO формируем список задачь
            $products = new WP_Query(
                [
                    'post_type' => 'product',
                    'posts_per_page' => -1,
                    'post_status' => ['publish', 'draft', 'private'],
                    'fields' => 'ids'
                ]
            );

            // var_dump($products->get_posts() );

            if ($products->have_posts()) {
                $product_ids = $products->get_posts(); //array_column($products->get_posts(), 'ID');


                if (!empty($product_ids)) {
                    $this->print_progress_block($product_ids);
                }
            } ?>
        </div>
    <?php

    }

    protected function print_progress_block($product_ids)
    {
        wp_register_style('carriers-style-admin', plugins_url('css/style.css', __FILE__), '', '1', '');
        wp_enqueue_style("carriers-style-admin");

        wp_enqueue_script("import-processparts", plugins_url('js/script.js', __FILE__), array('jquery'), "", true);
        wp_localize_script("import-processparts", "rce_params", array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'ajax_nonce' => wp_create_nonce('resender-ce_settings_nonce'),
            "part_size" => $this->ajax_part_size
        ));
        wp_localize_script("import-processparts", "all_processparts", $product_ids);

    ?>
        <p>Всего задачь <strong id="resce-allEmails"><?= count($product_ids) ?></strong></p>
        <div class="progress-wrap progress" data-progress-percent="0">
            <div class="progress-bar progress"></div>
        </div>
        <button id="run_resending_email" class="button button-primary">Начать процесс</button>
        <button id="resending_pause" class="button button-primary">Пауза</button>
        <div><br>
            <pre id="resce-error"></pre>
        </div>
<?php
    }

    public function process_is_finish()
    {
        check_ajax_referer('resender-ce_settings_nonce', 'security');
        update_option($this->import_data_option_name, null);
        wp_send_json_success(array('finish' => "Обновление успешно закончено"));
    }

    public function sending_part()
    {
        check_ajax_referer('resender-ce_settings_nonce', 'security');

        if (empty($_POST['process_part_ids']) && !is_array($_POST['process_part_ids'])) {
            wp_send_json_error("Пустой запрос");
        }
        $processpart_ids = array_map('intval', $_POST['process_part_ids']);
        $results = array();

        try {
            foreach ($processpart_ids as $processpart_ID) {
                $start = microtime(true);
                try {

                    $title = $this->processing_data($processpart_ID);
                    if (!empty($title)) {
                        $results["process = " . $processpart_ID] = esc_html($title) . ' - (' . round(microtime(true) - $start, 4) . ' сек.)';
                    }
                } catch (Exception $ex) {
                    $results["process = " . $processpart_ID] = $ex->getMessage();
                }
            }

            wp_send_json_success($results);
        } catch (Exception $ex) {
            wp_send_json_error($ex->getTrace());
        }

        wp_die();
    }

    public function processing_data($id)
    {
        $title = get_the_title($id);
        //TODO

        $pdf_ids = [];
        $_post = get_post($id);

        $content = urldecode($_post->post_content);

        $product_custom_tabs = get_post_meta($id, 'product_custom_tabs', true);

        $content .= $product_custom_tabs;
        //TODO 

        $matches = [];

        //Есть 2 типа ссылок ,одна обычная , другая с шорткода , но нам нужен только .pdf строка 
        if (preg_match_all('%="(url:)?([^"]+\.pdf)[^"]*"%im', $content, $matches)) {
            if (!empty($matches[2]) && is_array($matches[2])) {
                // error_log(print_r($matches[2], true));

                $title . '-' . count($matches[2]) . ' .pdf';
                foreach ($matches[2] as $remote_file) {
                    $atach_id = $this->rudr_upload_file_by_url($remote_file);
                    if ($atach_id) {
                        $pdf_ids[] = $atach_id;
                    } else {
						$title .= " - upload false ({$remote_file}) ";
                        // error_log($id . ' ' . $title . ' - error upload ' . $remote_file);
                    }
                }
                //Пишем в Acf repeater поле documentation
                // error_log($id . ' ' . $title . ' uploaded ' . print_r($pdf_ids, true));
                if (!empty($pdf_ids)) {
                    //Указываем количество
                    update_post_meta($id, 'documentation', count($pdf_ids));
                    //И далее сами поля заполняем
                    foreach ($pdf_ids as $key => $file_id) {
                        update_post_meta($id, 'documentation_' . $key . '_file', $file_id);
                    }
                }
            }
        } elseif (strpos($content, '.pdf') !== false) {

            $title .= "- Был pdf, но в регулярку не попал";
            //error_log('content ' . $content);
        }
        //---------------------
        return $title;
    }


    protected  function rudr_upload_file_by_url($image_url)
    {

        // it allows us to use download_url() and wp_handle_sideload() functions
        require_once(ABSPATH . 'wp-admin/includes/file.php');

        // download to temp dir
        $temp_file = download_url($image_url);

        if (is_wp_error($temp_file)) {
            return false;
        }

        // move the temp file into the uploads directory
        $file = array(
            'name'     => basename($image_url),
            'type'     => mime_content_type($temp_file),
            'tmp_name' => $temp_file,
            'size'     => filesize($temp_file),
        );
        $sideload = wp_handle_sideload(
            $file,
            array(
                'test_form'   => false // no needs to check 'action' parameter
            )
        );

        if (!empty($sideload['error'])) {
            // you may return error message if you want
            return false;
        }

        // it is time to add our uploaded image into WordPress media library
        $attachment_id = wp_insert_attachment(
            array(
                'guid'           => $sideload['url'],
                'post_mime_type' => $sideload['type'],
                'post_title'     => basename($sideload['file']),
                'post_content'   => '',
                'post_status'    => 'inherit',
            ),
            $sideload['file']
        );

        if (is_wp_error($attachment_id) || !$attachment_id) {
            return false;
        }

        // update medatata, regenerate image sizes
        // require_once(ABSPATH . 'wp-admin/includes/image.php');

        // wp_update_attachment_metadata(
        //     $attachment_id,
        //     wp_generate_attachment_metadata($attachment_id, $sideload['file'])
        // );

        return $attachment_id;
    }

    public function __destruct()
    {
    }
}

$Process_Page = new Process_Page();
