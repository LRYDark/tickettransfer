<?php

define('PLUGIN_TICKETTRANSFER_VERSION', '1.1.0');
define('PLUGIN_TICKETTRANSFER_MIN_GLPI', '11.0.0');
define('PLUGIN_TICKETTRANSFER_MAX_GLPI', '11.0.99');

/**
 * Init hooks of the plugin.
 *
 * @return void
 */
function plugin_init_tickettransfer()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['tickettransfer'] = true;
    $PLUGIN_HOOKS['change_profile']['tickettransfer'] = [PluginTickettransferProfile::class, 'initProfile'];

    $plugin = new Plugin();
    if ($plugin->isInstalled('tickettransfer') && $plugin->isActivated('tickettransfer')) {
        Plugin::registerClass(
            PluginTickettransferProfile::class,
            ['addtabon' => Profile::class],
        );

        Plugin::registerClass(
            PluginTickettransferConfig::class,
            ['addtabon' => 'Config'],
        );

        Plugin::registerClass(
            PluginTickettransferPreference::class,
            ['addtabon' => Preference::class],
        );

        $PLUGIN_HOOKS['config_page']['tickettransfer'] = '../../front/config.form.php?forcetab=' . urlencode('PluginTickettransferConfig$1');
        $PLUGIN_HOOKS['timeline_actions']['tickettransfer'] = 'plugin_tickettransfer_timeline_actions';
    }
}

/**
 * Plugin metadata.
 *
 * @return array
 */
function plugin_version_tickettransfer()
{
    return [
        'name'         => __('Transfert de ticket', 'tickettransfer'),
        'version'      => PLUGIN_TICKETTRANSFER_VERSION,
        'author'       => 'REINERT Joris',
        'license'      => 'GPLv3',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_TICKETTRANSFER_MIN_GLPI,
                'max' => PLUGIN_TICKETTRANSFER_MAX_GLPI,
            ],
        ],
    ];
}

/**
 * Check prerequisites at installation time.
 *
 * @return bool
 */
function plugin_tickettransfer_check_prerequisites()
{
    if (version_compare(GLPI_VERSION, PLUGIN_TICKETTRANSFER_MIN_GLPI, '<')) {
        return false;
    }
    if (version_compare(GLPI_VERSION, PLUGIN_TICKETTRANSFER_MAX_GLPI, '>=')) {
        return false;
    }

    return true;
}

/**
 * Configuration check callback.
 *
 * @param boolean $verbose
 *
 * @return boolean
 */
function plugin_tickettransfer_check_config($verbose = false)
{
    return true;
}
