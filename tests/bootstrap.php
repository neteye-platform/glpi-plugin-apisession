<?php

$current_plugin_folder = basename(realpath(__DIR__ . '/../'));

require __DIR__ . '/../../../tests/bootstrap.php';

if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require dirname(__DIR__) . '/vendor/autoload.php';
}

if (!Plugin::isPluginActive($current_plugin_folder)) {
    throw new RuntimeException(
        sprintf('Plugin %s is not active in the test database', $current_plugin_folder)
    );
}
