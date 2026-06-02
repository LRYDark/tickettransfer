<?php

class PluginTickettransferPreference extends CommonDBTM
{
    public static $rightname = PluginTickettransferProfile::RIGHTNAME;
    public $table = 'glpi_plugin_tickettransfer_preferences';

    private const DEFAULT_ASSIGN = 1;
    private const DEFAULT_REPLACE = 1;
    private const DEFAULT_CHANGE_STATUS = 0;
    private const DEFAULT_STATUS = 4; // Ticket::WAITING (en attente)

    public static function getTypeName($nb = 0)
    {
        return _n('Transfert de ticket', 'Transferts de ticket', $nb, 'tickettransfer');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof Preference)) {
            return '';
        }
        if (!Session::haveRight(PluginTickettransferProfile::RIGHTNAME, PluginTickettransferProfile::RIGHT_PREFERENCES)) {
            return '';
        }
        return self::createTabEntry(__('Transfert de ticket', 'tickettransfer'), 0, null, 'ti ti-user-share');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof Preference)) {
            return false;
        }
        if (!Session::haveRight(PluginTickettransferProfile::RIGHTNAME, PluginTickettransferProfile::RIGHT_PREFERENCES)) {
            return false;
        }

        self::showPreferencesForm(Session::getLoginUserID());
        return true;
    }

    public static function getUserPreferences(int $userId): array
    {
        $defaults = [
            'assign_on_transfer'        => self::DEFAULT_ASSIGN,
            'replace_on_transfer'       => self::DEFAULT_REPLACE,
            'change_status_on_transfer' => self::DEFAULT_CHANGE_STATUS,
            'transfer_status'           => self::DEFAULT_STATUS,
        ];
        if ($userId <= 0) {
            return $defaults;
        }

        $pref = new self();
        if ($pref->getFromDBByCrit(['users_id' => $userId])) {
            $defaults['assign_on_transfer'] = (int) ($pref->fields['assign_on_transfer'] ?? self::DEFAULT_ASSIGN);
            $defaults['replace_on_transfer'] = (int) ($pref->fields['replace_on_transfer'] ?? self::DEFAULT_REPLACE);
            $defaults['change_status_on_transfer'] = (int) ($pref->fields['change_status_on_transfer'] ?? self::DEFAULT_CHANGE_STATUS);
            $defaults['transfer_status'] = (int) ($pref->fields['transfer_status'] ?? self::DEFAULT_STATUS);
        }

        return $defaults;
    }

    /**
     * Resolve the effective "change status on transfer" decision for a user,
     * combining the global plugin config (and its authorizations) with the
     * user preference.
     *
     * - The user preference is only taken into account when the admin allows
     *   it (allow_pref_status). Otherwise it is ignored entirely.
     * - The status is changed when the global config OR the (allowed) user
     *   preference enables it. The applied status is the user's choice when the
     *   preference is enabled/allowed, otherwise the global config value.
     * - allow_modal tells whether the per-transfer dropdown may be shown in the
     *   modal.
     *
     * @return array{change: bool, status: int, allow_modal: bool, allow_pref: bool}
     */
    public static function getEffectiveStatusSetting(int $userId): array
    {
        $config = PluginTickettransferConfig::getConfig();
        $configEnabled = (int) ($config['change_status_on_transfer'] ?? 0) === 1;
        $configStatus = (int) ($config['transfer_status'] ?? self::DEFAULT_STATUS);
        $allowPref = !isset($config['allow_pref_status']) || (int) $config['allow_pref_status'] === 1;
        $allowModal = !isset($config['allow_modal_status']) || (int) $config['allow_modal_status'] === 1;

        $prefs = self::getUserPreferences($userId);
        $prefEnabled = $allowPref && (int) $prefs['change_status_on_transfer'] === 1;
        $prefStatus = (int) $prefs['transfer_status'];

        $change = $prefEnabled || $configEnabled;
        $status = $prefEnabled ? $prefStatus : $configStatus;
        if (!array_key_exists($status, Ticket::getAllStatusArray(false))) {
            $status = self::DEFAULT_STATUS;
        }

        return [
            'change'      => $change,
            'status'      => $status,
            'allow_modal' => $allowModal,
            'allow_pref'  => $allowPref,
        ];
    }

    public static function saveUserPreferences(int $userId, array $input): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $assign = !empty($input['assign_on_transfer']) ? 1 : 0;
        $replace = !empty($input['replace_on_transfer']) ? 1 : 0;
        $changeStatus = !empty($input['change_status_on_transfer']) ? 1 : 0;
        $status = isset($input['transfer_status']) ? (int) $input['transfer_status'] : self::DEFAULT_STATUS;
        if (!array_key_exists($status, Ticket::getAllStatusArray(false))) {
            $status = self::DEFAULT_STATUS;
        }

        // When the admin disabled the preference override, the status fields are
        // hidden in the form: keep the stored values instead of resetting them.
        $config = PluginTickettransferConfig::getConfig();
        $allowPref = !isset($config['allow_pref_status']) || (int) $config['allow_pref_status'] === 1;

        $pref = new self();
        if ($pref->getFromDBByCrit(['users_id' => $userId])) {
            if (!$allowPref) {
                $changeStatus = (int) ($pref->fields['change_status_on_transfer'] ?? self::DEFAULT_CHANGE_STATUS);
                $status = (int) ($pref->fields['transfer_status'] ?? self::DEFAULT_STATUS);
            }
            return (bool) $pref->update([
                'id' => (int) $pref->fields['id'],
                'users_id' => $userId,
                'assign_on_transfer' => $assign,
                'replace_on_transfer' => $replace,
                'change_status_on_transfer' => $changeStatus,
                'transfer_status' => $status,
            ]);
        }

        return (bool) $pref->add([
            'users_id' => $userId,
            'assign_on_transfer' => $assign,
            'replace_on_transfer' => $replace,
            'change_status_on_transfer' => $changeStatus,
            'transfer_status' => $status,
        ]);
    }

    public static function showPreferencesForm(int $userId): void
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $prefs = self::getUserPreferences($userId);
        $assign = (int) $prefs['assign_on_transfer'] === 1;
        $replace = (int) $prefs['replace_on_transfer'] === 1;
        $changeStatus = (int) $prefs['change_status_on_transfer'] === 1;
        $transferStatus = (int) $prefs['transfer_status'];

        $config = PluginTickettransferConfig::getConfig();
        $allowPref = !isset($config['allow_pref_status']) || (int) $config['allow_pref_status'] === 1;

        $formAction = $CFG_GLPI['root_doc'] . '/plugins/tickettransfer/front/preference.form.php';

        echo "<form method='post' action='" . Html::cleanInputText($formAction) . "' class='mb-3'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken(true)]);
        echo Html::hidden('users_id', ['value' => $userId]);

        echo "<div class='card'><div class='card-body'>";
        echo "<div class='mb-3'>";
        echo "<div class='form-check'>";
        echo "<input class='form-check-input' type='checkbox' id='tickettransfer_assign_on_transfer' name='assign_on_transfer' value='1'"
            . ($assign ? ' checked' : '') . ">";
        echo "<label class='form-check-label' for='tickettransfer_assign_on_transfer'>"
            . __('Associer les utilisateurs transferes dans "Attribue a"', 'tickettransfer') . "</label>";
        echo "</div>";
        echo "</div>";

        echo "<div class='mb-3'>";
        echo "<div class='form-check'>";
        echo "<input class='form-check-input' type='checkbox' id='tickettransfer_replace_on_transfer' name='replace_on_transfer' value='1'"
            . ($replace ? ' checked' : '') . ">";
        echo "<label class='form-check-label' for='tickettransfer_replace_on_transfer'>"
            . __('Remplacer les utilisateurs deja attribues', 'tickettransfer') . "</label>";
        echo "</div>";
        echo "<div class='form-text'>" . __('Si inactive, les utilisateurs transferes seront ajoutes aux existants.', 'tickettransfer') . "</div>";
        echo "</div>";

        if ($allowPref) {
            echo "<hr>";

            echo "<div class='mb-3'>";
            echo "<div class='form-check'>";
            echo "<input class='form-check-input' type='checkbox' id='tickettransfer_change_status_on_transfer' name='change_status_on_transfer' value='1'"
                . ($changeStatus ? ' checked' : '') . ">";
            echo "<label class='form-check-label' for='tickettransfer_change_status_on_transfer'>"
                . __('Modifier le statut du ticket apres transfert', 'tickettransfer') . "</label>";
            echo "</div>";
            echo "<div class='form-text'>" . __('Si actif, vous pourrez choisir le statut applique au ticket lors du transfert.', 'tickettransfer') . "</div>";
            echo "</div>";

            echo "<div class='mb-3' id='tickettransfer_transfer_status_wrap'" . ($changeStatus ? '' : " style='display:none;'") . ">";
            echo "<label class='form-label mb-1' for='dropdown_transfer_status'>" . __('Statut par defaut apres transfert', 'tickettransfer') . "</label>";
            Ticket::dropdownStatus([
                'name'  => 'transfer_status',
                'value' => $transferStatus,
            ]);
            echo "</div>";
        }

        echo "<div class='text-end'>";
        echo Html::submit(__('Enregistrer', 'tickettransfer'), ['name' => 'update_prefs', 'class' => 'btn btn-primary']);
        echo "</div>";
        echo "</div></div>";
        echo "</form>";

        $js = <<<JS
(function() {
  var assign = document.getElementById('tickettransfer_assign_on_transfer');
  var replace = document.getElementById('tickettransfer_replace_on_transfer');
  if (assign && replace) {
    var toggle = function() {
      replace.disabled = !assign.checked;
    };
    assign.addEventListener('change', toggle);
    toggle();
  }

  var changeStatus = document.getElementById('tickettransfer_change_status_on_transfer');
  var statusWrap = document.getElementById('tickettransfer_transfer_status_wrap');
  if (changeStatus && statusWrap) {
    var toggleStatus = function() {
      statusWrap.style.display = changeStatus.checked ? '' : 'none';
    };
    changeStatus.addEventListener('change', toggleStatus);
    toggleStatus();
  }
})();
JS;
        echo Html::scriptBlock($js);
    }
}
