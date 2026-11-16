<?php
define('PLUGIN_APISESSION_VERSION', '1.0.1');
define('PLUGIN_APISESSION_MIN_GLPI_VERSION', '11.0.0');
define('PLUGIN_APISESSION_MAX_GLPI_VERSION', '11.1.0');

function plugin_version_apisession()
{
    return array('name' => "API Session",
        'version' => PLUGIN_APISESSION_VERSION,
        'author' => 'NetEye R&D Team',
        'license' => 'MIT/Apache-2.0',
        'homepage' => 'https://neteye-blog.com',
        'requirements' => array(
            'glpi' => array(
                'min' => PLUGIN_APISESSION_MIN_GLPI_VERSION,
                'max' => PLUGIN_APISESSION_MAX_GLPI_VERSION,
            ),
        ));
}

function plugin_apisession_check_prerequisites()
{
    if (
        version_compare(GLPI_VERSION, PLUGIN_APISESSION_MIN_GLPI_VERSION, '<')
        || version_compare(GLPI_VERSION, PLUGIN_APISESSION_MAX_GLPI_VERSION, '>=')
    ) {
        echo "This plugin requires GLPI >= " . PLUGIN_APISESSION_MIN_GLPI_VERSION
            . " and < " . PLUGIN_APISESSION_MAX_GLPI_VERSION;
        return false;
    }
    return true;
}

function plugin_apisession_check_config($verbose = false)
{
    return true;
}
