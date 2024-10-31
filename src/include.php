<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class ProtectMedia
{
    const PLUGIN_ID         = 'protect-media';
    const PLUGIN_SETTING_SLAG = 'protect-media-config';
    const PLUGIN_SETTING_TRANSIENT_MESSAGE = self::PLUGIN_SETTING_SLAG.'-message';
    const PLUGIN_SETTING_TRANSIENT_ERROR = self::PLUGIN_SETTING_SLAG.'-error';
    const CREDENTIAL_ACTION   = self::PLUGIN_ID . '-nonce-action';
    const CREDENTIAL_NAME     = self::PLUGIN_ID . '-nonce-key';

    const PLUGIN_DB_PREFIX  = self::PLUGIN_ID . '_';
    const PLUGIN_DB_SETTING_PATH  = self::PLUGIN_DB_PREFIX . 'path';
    const PLUGIN_DB_SETTING_BLOCK  = self::PLUGIN_DB_PREFIX . 'block';

    const HTACCESS_PATH = WP_CONTENT_DIR.'/.htaccess';

    const TEXTDOMAIN = 'protect-media';

    static function init()
    {
        return new self();
    }

    function __construct()
    {
        load_plugin_textdomain(
                self::TEXTDOMAIN,
                false,
            basename( realpath(__DIR__.'/..')).'/languages'
        );
        if (is_admin() && is_user_logged_in()) {
            add_action('admin_menu', [$this, 'set_plugin_menu']);
            add_action('admin_init', [$this, 'save_config']);
        }
        add_action('wp', [$this, 'elegance_referal_init']);

        add_action('protect_media_error', [$this, 'do_error']);
    }

    function elegance_referal_init()
    {
        // protect dir check
        $protect_dirs = self::get_protect_dirs();
        if($protect_dirs === false || empty($protect_dirs)){
            return;
        }
        $file = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        $path = realpath(ABSPATH.$file);
        $check = false;
        foreach($protect_dirs as $protect_dir){
            if(strpos($path, $protect_dir) === 0){
                $check = true;
                break;
            }
        }
        if(!$check){
            return;
        }
        $this->exec_file($path);
    }

    function set_plugin_menu(){
        add_submenu_page(
            'upload.php',
            __('Protect Media Setting', self::TEXTDOMAIN),
            __('Protect Media', self::TEXTDOMAIN),
            'manage_options',
            self::PLUGIN_SETTING_SLAG,
            [$this, 'show_config_form']
        );
    }

    /**
     * @return bool
     */
    protected function is_allow_override(){
        return ini_get('allow_override');
    }

    /**
     * 設定画面
     */
    public function show_config_form() {
        $path = get_option(self::PLUGIN_DB_SETTING_PATH);
        $is_block = get_option(self::PLUGIN_DB_SETTING_BLOCK);
        $is_error = get_transient(self::PLUGIN_SETTING_TRANSIENT_ERROR);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Protect Media Setting', self::TEXTDOMAIN); ?></h1>
            <p><?php esc_html_e('Make login required for file access of the set path.', self::TEXTDOMAIN); ?></p>
<?php if(!$this->is_allow_override()){ ?>
    <div class="notice"><p><?php esc_html_e('Check if AllowOverride in php.ini is enabled.', self::TEXTDOMAIN); ?></p></div>
<?php } ?>
<?php if(!file_exists(self::HTACCESS_PATH)){ ?>
    <div class="notice notice-warning"><p><?php echo sprintf(esc_html__('%s does not exist. Save and update your settings.', self::TEXTDOMAIN), self::HTACCESS_PATH); ?></p></div>
<?php } else if(!is_writable(self::HTACCESS_PATH)){ ?>
    <?php if($is_error){ ?>
        <div class="notice notice-alt notice-error"><p><?php echo sprintf(esc_html__('You don\'t have write permission to .htaccess. Please describe the following in %s.', self::TEXTDOMAIN), self::HTACCESS_PATH); ?></p></div>
        <div class="notice notice-error">
        <pre><?php echo esc_html($this->get_htaccess_str(explode("\n", $path))); ?></pre>
        </div>
    <?php }else{ ?>
        <div class="notice notice-alt notice-error"><p><?php esc_html_e('You don\'t have write permission to .htaccess.', self::TEXTDOMAIN); ?></p></div>
    <?php } ?>
<?php } ?>
<?php if(!$path){ ?>
    <div class="notice notice-alt notice-error"><p><?php esc_html_e('Protection is not enabled because the path is invalid.', self::TEXTDOMAIN); ?></p></div>
<?php } ?>
<?php if($message = get_transient(self::PLUGIN_SETTING_TRANSIENT_MESSAGE)){ ?>
    <div id="message" class="notice notice-success"><p><?php echo esc_html($message); ?></p></div>
<?php } ?>
            <form action="" method='post' id="my-submenu-form">
                <?php wp_nonce_field(self::CREDENTIAL_ACTION, self::CREDENTIAL_NAME) ?>
                <table class="table">
                <tr>
                    <td><label for="title"><?php esc_html_e('Path setting', self::TEXTDOMAIN); ?></label></td>
                    <td>
                        <div><textarea name="path" cols="100" rows="4"><?php echo esc_html($path); ?></textarea></div>
                        <div class="hint">※ <?php esc_html_e('Please specify the path from uploads', self::TEXTDOMAIN); ?></div>
                        <div class="hint">※ <?php esc_html_e('Specify one path per line', self::TEXTDOMAIN); ?></div>
                    </td>
                </tr>
                <tr>
                    <td><label for="title"><?php esc_html_e('Deny all access', self::TEXTDOMAIN); ?></label></td>
                    <td><input type="checkbox" name="block" value="1" <?php if($is_block){ ?> checked="checked"<?php } ?> /></td>
                </tr>
                </table>
                <p><input type="submit" value="<?php esc_html_e('Save'); ?>" class="button button-primary button-large"></p>
            </form>
        </div>
        <?php
    }

    public function save_config()
    {
        if (isset($_POST[self::CREDENTIAL_NAME]) && $_POST[self::CREDENTIAL_NAME]) {
            if (check_admin_referer(self::CREDENTIAL_ACTION, self::CREDENTIAL_NAME)) {

                $path = isset($_POST['path']) ? sanitize_textarea_field($_POST['path']) : "";
                $is_block = isset($_POST['block']) ? sanitize_text_field($_POST['block']) : 0;

                if($this->update_path($path)){
                    update_option(self::PLUGIN_DB_SETTING_BLOCK, $is_block);
                    $completed_text = __('The settings have been saved.', self::TEXTDOMAIN);
                    set_transient(self::PLUGIN_SETTING_TRANSIENT_MESSAGE, $completed_text, 5);
                }else{
                    set_transient(self::PLUGIN_SETTING_TRANSIENT_ERROR, 1, 5);
                }
                // 設定画面にリダイレクト
                wp_safe_redirect(menu_page_url(self::PLUGIN_SETTING_SLAG, false));
                exit;
            }
        }
    }

    /**
     * 保護先のパス
     *
     * @param string|null $path
     * @return array|false
     */
    public static function get_protect_dirs(){
        $path = get_option(self::PLUGIN_DB_SETTING_PATH);
        if(!$path){
            return false;
        }
        $paths = explode("\n", $path);
        if(empty($path)){
            return false;
        }
        $arr = [];
        foreach($paths as $p){
            $arr[] = self::get_upload_dir(trim($p));
        }
        return $arr;
    }

    /**
     * @param string $path
     * @return string
     */
    protected static function get_upload_dir($path){
        return wp_upload_dir()['basedir'].DIRECTORY_SEPARATOR.$path;
    }

    /**
     * @return bool
     */
    public static function is_block(){
        return get_option(self::PLUGIN_DB_SETTING_BLOCK) ? true : false;
    }

    /**
     * @param array $paths
     * @return string
     */
    protected function get_htaccess_str($paths){
        if(count($paths) <= 0){
            return '';
        }
        $endpoint = home_url('/', 'relative');
        $wp_path = rtrim(ABSPATH, DIRECTORY_SEPARATOR);
        $htaccess = 'RewriteEngine On'."\n".'RewriteBase /'."\n";
        $n = 0;
        $cnt = count($paths);
        foreach($paths as $path){
            $path = trim($path);
            if(!$path){
                continue;
            }
            $uploads_dir = self::get_upload_dir($path);
            if(strpos($uploads_dir, $wp_path) === 0){
                $uploads_dir = substr($uploads_dir, strlen($wp_path));
            }
            $htaccess .= 'RewriteCond %{REQUEST_URI} ^'.$uploads_dir;
            if(($n + 1) < $cnt){
                $htaccess .= ' [OR]';
            }
            $htaccess .= "\n";
            $n ++;
        }
        $htaccess .= 'RewriteRule ^(.*)$ '.$endpoint.' [L]'."\n";
        return $htaccess;
    }
    /**
     * パスの保存
     *
     * @param string $paths
     * @return bool
     */
    protected function update_path($paths){
        update_option(self::PLUGIN_DB_SETTING_PATH, $paths);

        $str = '';
        if(file_exists(self::HTACCESS_PATH)){
            $str = @file_get_contents(self::HTACCESS_PATH);
            if($str === false){
                $completed_text = __('.htaccess could not be read.', self::TEXTDOMAIN);
                set_transient(self::PLUGIN_SETTING_SLAG, $completed_text, 5);
                return false;
            }
        }
        $plugin_id = self::PLUGIN_ID;
        $body_str = $this->get_htaccess_str(explode("\n", $paths));
        $begin_tag = '# BEGIN '.$plugin_id;
        $end_tag = '# END '.$plugin_id;
        $hint_tag = '# '.__('Any changes to the directive between these markers will be overwritten.', self::TEXTDOMAIN);
        if(preg_match('/^(.*'.preg_quote($begin_tag).'\n)(.*\n)('.preg_quote($end_tag).'.*)$/s', $str, $matchs)){
            $str = $matchs[1].$hint_tag."\n".$body_str."\n".$matchs[3];
        }else{
            $str .= $begin_tag."\n".$hint_tag."\n".$body_str."\n".$end_tag;
        }
        if(!$str){
        }
        if(@file_put_contents(self::HTACCESS_PATH, $str) === false){
            $completed_text = __('Failed to write to .htaccess.', self::TEXTDOMAIN);
            set_transient(self::PLUGIN_SETTING_SLAG, $completed_text, 5);
            return false;
        }
        return true;
    }

    /**
     * @param int $error_code
     */
    public function do_error($error_code){
        $message = 'Unknown Error';
        switch ($error_code){
            case 401:   $message = 'Invalid Auth';    break;
            case 404:   $message = 'File Not Found';    break;
            case 500:   $message = 'Access Blocked';    break;
        }
        http_response_code($error_code);
        echo $message;
    }

    /**
     * @param string $path
     */
    public function exec_file($path){
        if(!$path){
            do_action('protect_media_error', 500);
            exit;
        }
        // is blocked
        if(ProtectMedia::is_block()){
            do_action('protect_media_error', 500);
            exit;
        }
        // required auth login
        if(!is_user_logged_in()){
            do_action('protect_media_error', 401);
            exit;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if(!$extension){
            do_action('protect_media_error', 500);
            exit;
        }
        if(!file_exists($path)){
            do_action('protect_media_error', 404);
            exit;
        }

        if($extension === 'jpg' || $extension === 'jpeg') {
            header('Content-Type: image/jpeg');
        }else if($extension === 'gif'){
            header('Content-Type: image/gif');
        }else if($extension === 'png'){
            header('Content-Type: image/png');
        }else{
            do_action('protect_media_error', 404);
            exit;
        }
        http_response_code(200);
        header("X-Robots-Tag: noindex, nofollow");
        readfile($path);
        exit;
    }
}