<?php

/**
 * Plugin Name:      Orchestra Email
 * Plugin URI:       https://github.com/TheRacoonOfDeath/orchestra-email
 * Description:      Emails to members, sections and project participants for the Orchestra plugin suite.
 * Version:          0.3.0
 * Author:           Julian Nickerl
 * License:          MIT
 * Text Domain:      orchestra-email
 * Domain Path:      /languages
 * Requires at least: 6.5
 * Requires PHP:     8.3
 * Requires Plugins: orchestra-core
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

Orchestra\Email\Plugin::boot(__FILE__);
