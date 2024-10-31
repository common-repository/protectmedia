<?php
/*
  Plugin Name: ProtectMedia
  Plugin URI: https://github.com/ateliee/wp-protect-media
  Description: auth login access uploads file directory
  Version: 1.1.0
  Author: ateliee
  Author URI: https://ateliee.com
  License: MIT
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

require(__DIR__.'/src/include.php');
add_action('init', 'ProtectMedia::init');
