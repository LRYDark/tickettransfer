<?php

class PluginTickettransferConfig extends CommonDBTM
{
    public static $rightname = PluginTickettransferProfile::RIGHTNAME;
    private static $_instance = null;

    public static function getTypeName($nb = 0)
    {
        return __('Configuration du transfert de ticket', 'tickettransfer');
    }

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_tickettransfer_configs';
    }

    public static function getDefaultConfig(): array
    {
        return [
            'id'                        => 1,
            'change_status_on_transfer' => 0,
            'transfer_status'           => 4,
            'allow_pref_status'         => 1,
            'allow_modal_status'        => 1,
            'notificationtemplates_id'  => 0,
        ];
    }

    public static function getConfig(): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $config = $DB->request([
            'SELECT' => '*',
            'FROM'   => self::getTable(),
            'LIMIT'  => 1,
        ])->current();

        if (!$config) {
            $config = self::getDefaultConfig();
        }

        return $config;
    }

    public static function getInstance()
    {
        if (!isset(self::$_instance)) {
            self::$_instance = new self();
            if (!self::$_instance->getFromDB(1)) {
                self::$_instance->getEmpty();
            }
        }
        return self::$_instance;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        // Plugin configuration is reserved to super-admins (core config right).
        if ($item->getType() === 'Config') {
            if (Session::haveRight('config', UPDATE)) {
                return self::createTabEntry(__('Transfert de ticket', 'tickettransfer'), 0, null, 'ti ti-user-share');
            }
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item->getType() === 'Config') {
            if (Session::haveRight('config', UPDATE)) {
                self::showConfigPage();
            } else {
                Html::displayRightError();
            }
        }
        return true;
    }

    public static function showConfigPage(): void
    {
        $GLOBALS['PLUGIN_TICKETTRANSFER_FROM_TAB'] = true;
        include __DIR__ . '/../front/config.form.php';
        unset($GLOBALS['PLUGIN_TICKETTRANSFER_FROM_TAB']);
    }

    public static function updateConfig(array $input): bool
    {
        $config = self::getConfig();
        $cfgItem = new self();
        $input['id'] = $config['id'] ?? 1;

        $input['change_status_on_transfer'] = !empty($input['change_status_on_transfer']) ? 1 : 0;
        $transferStatus = isset($input['transfer_status']) ? (int) $input['transfer_status'] : 4;
        if (!array_key_exists($transferStatus, Ticket::getAllStatusArray(false))) {
            $transferStatus = 4;
        }
        $input['transfer_status'] = $transferStatus;
        $input['allow_pref_status'] = !empty($input['allow_pref_status']) ? 1 : 0;
        $input['allow_modal_status'] = !empty($input['allow_modal_status']) ? 1 : 0;
        $input['notificationtemplates_id'] = isset($input['notificationtemplates_id'])
            ? (int) $input['notificationtemplates_id']
            : 0;

        if ($cfgItem->getFromDB($input['id'])) {
            return (bool) $cfgItem->update($input);
        }

        return (bool) $cfgItem->add($input + self::getDefaultConfig());
    }
}
