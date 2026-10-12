<?php

if (!defined('GLPI_ROOT')) {
}

$from_tab = $GLOBALS['PLUGIN_TICKETTRANSFER_FROM_TAB'] ?? false;

// Plugin configuration is reserved to super-admins (core config right).
Session::checkRight('config', UPDATE);

$configItem = PluginTickettransferConfig::getInstance();
$config = PluginTickettransferConfig::getConfig();

$form_action = $configItem->getFormURL();
if ($from_tab) {
    $form_action .= '?forcetab=' . urlencode('PluginTickettransferConfig$1');
}

if (isset($_POST['update'])) {
    if (PluginTickettransferConfig::updateConfig($_POST)) {
        Session::addMessageAfterRedirect(__('Configuration mise a jour.', 'tickettransfer'), true, INFO);
    } else {
        Session::addMessageAfterRedirect(__('Echec de la mise a jour.', 'tickettransfer'), true, ERROR);
    }
    Html::back();
}

if (!$from_tab) {
    Html::header(
        PluginTickettransferConfig::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'config',
        'plugins'
    );
    Html::displayMessageAfterRedirect();
}

$cfgChangeStatus = !empty($config['change_status_on_transfer']);
$cfgTransferStatus = (int) ($config['transfer_status'] ?? 4);
$cfgAllowPref = !isset($config['allow_pref_status']) || !empty($config['allow_pref_status']);
$cfgAllowModal = !isset($config['allow_modal_status']) || !empty($config['allow_modal_status']);

echo "<form name='tickettransfer_config' method='post' action='" . $form_action . "' class='mb-3'>";
echo Html::hidden('id', ['value' => $configItem->fields['id'] ?? 1]);

echo "<div class='card mb-3'>";
echo "<div class='card-header'><h3 class='card-title mb-0'>" . __('Transfert de ticket', 'tickettransfer') . "</h3></div>";
echo "<div class='card-body'>";
echo "<div class='row g-3 align-items-end'>";

echo "<div class='col-md-6'>";
echo "<div class='form-check'>";
echo "<input class='form-check-input' type='checkbox' name='change_status_on_transfer' id='tickettransfer_config_change_status' value='1'"
    . ($cfgChangeStatus ? ' checked' : '') . ">";
echo "<label class='form-check-label' for='tickettransfer_config_change_status'>"
    . __('Modifier le statut du ticket apres transfert (valeur par defaut globale)', 'tickettransfer') . "</label>";
echo "</div>";
echo "<div class='form-text'>" . __('Statut applique par defaut lors d un transfert.', 'tickettransfer') . "</div>";
echo "</div>";

echo "<div class='col-md-6' id='tickettransfer_config_transfer_status_wrap'" . ($cfgChangeStatus ? '' : " style='display:none;'") . ">";
echo "<label class='form-label mb-1' for='dropdown_transfer_status'>" . __('Statut par defaut apres transfert', 'tickettransfer') . "</label>";
Ticket::dropdownStatus([
    'name'  => 'transfer_status',
    'value' => $cfgTransferStatus,
]);
echo "</div>";

echo "<div class='col-md-6'>";
echo "<div class='form-check'>";
echo "<input class='form-check-input' type='checkbox' name='allow_pref_status' id='tickettransfer_config_allow_pref' value='1'"
    . ($cfgAllowPref ? ' checked' : '') . ">";
echo "<label class='form-check-label' for='tickettransfer_config_allow_pref'>"
    . __('Autoriser la modification dans les preferences utilisateur', 'tickettransfer') . "</label>";
echo "</div>";
echo "<div class='form-text'>" . __('Si desactive, le reglage est masque dans les preferences et n est plus pris en compte.', 'tickettransfer') . "</div>";
echo "</div>";

echo "<div class='col-md-6'>";
echo "<div class='form-check'>";
echo "<input class='form-check-input' type='checkbox' name='allow_modal_status' id='tickettransfer_config_allow_modal' value='1'"
    . ($cfgAllowModal ? ' checked' : '') . ">";
echo "<label class='form-check-label' for='tickettransfer_config_allow_modal'>"
    . __('Autoriser la modification depuis le modal de transfert', 'tickettransfer') . "</label>";
echo "</div>";
echo "<div class='form-text'>" . __('Si desactive, la liste deroulante du statut n apparait pas dans le modal.', 'tickettransfer') . "</div>";
echo "</div>";

echo "</div>"; // row
echo "</div>"; // card-body
echo "</div>"; // card

// Email template (gabarit) card
$cfgTemplateId = (int) ($config['notificationtemplates_id'] ?? 0);
echo "<div class='card mb-3'>";
echo "<div class='card-header'><h3 class='card-title mb-0'>" . __('Gabarit de notification (e-mail de transfert)', 'tickettransfer') . "</h3></div>";
echo "<div class='card-body'>";

echo "<div class='row g-3 align-items-center'>";
echo "<div class='col-md-3'><label class='form-label mb-0'>" . __('Gabarit : Modèle de notifications', 'tickettransfer') . "</label></div>";
echo "<div class='col-md-9'>";
Dropdown::show('NotificationTemplate', [
    'name'                => 'notificationtemplates_id',
    'value'               => $cfgTemplateId,
    'display_emptychoice' => 1,
    'emptylabel'          => '-----',
    'entity_restrict'     => 0,
]);
echo "<div class='form-text'>" . __('E-mail envoyé aux destinataires lors d un transfert. Modifiable dans Configuration > Notifications > Gabarits de notifications.', 'tickettransfer') . "</div>";
echo "</div>";
echo "</div>"; // row

// Supported tags
$tags = [
    '###ticket.id##'         => __('Ticket : ID', 'tickettransfer'),
    '##ticket.title##'       => __('Ticket : Titre', 'tickettransfer'),
    '##ticket.url##'         => __('Ticket : Lien (URL)', 'tickettransfer'),
    '##transfer.author##'    => __('Transfert : Auteur', 'tickettransfer'),
    '##transfer.recipients##'=> __('Transfert : Destinataires', 'tickettransfer'),
    '##transfer.comment##'   => __('Transfert : Commentaire', 'tickettransfer'),
];
echo "<button type='button' id='tickettransfer-tags-toggle' class='btn btn-link p-0 mt-3 text-start text-decoration-none fw-bold'>"
    . "<span id='tickettransfer-tags-icon' class='me-1'>&uarr;</span>" . __('Balises prises en charge', 'tickettransfer') . "</button>";
echo "<div id='tickettransfer-tags-wrap' style='display:none;' class='mt-2'>";
echo "<table class='table table-sm align-middle mb-0'><tbody>";
foreach ($tags as $tag => $label) {
    echo "<tr><td style='width:230px;'><code>" . htmlescape($tag) . "</code></td><td>" . htmlescape($label) . "</td></tr>";
}
echo "</tbody></table>";
echo "</div>";

echo "</div>"; // card-body
echo "</div>"; // card

echo "<div class='card mb-3'>";
echo "<div class='card-body text-center'>";
echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
echo "</div>";
echo "</div>";

Html::closeForm();

$toggleJs = <<<JS
(function() {
    var cb = document.getElementById('tickettransfer_config_change_status');
    var wrap = document.getElementById('tickettransfer_config_transfer_status_wrap');
    if (!cb || !wrap) {
        return;
    }
    var toggle = function() {
        wrap.style.display = cb.checked ? '' : 'none';
    };
    cb.addEventListener('change', toggle);
    toggle();
})();
JS;
echo Html::scriptBlock($toggleJs);

$tagsJs = <<<JS
(function() {
    var btn = document.getElementById('tickettransfer-tags-toggle');
    var icon = document.getElementById('tickettransfer-tags-icon');
    var wrap = document.getElementById('tickettransfer-tags-wrap');
    if (!btn || !icon || !wrap) {
        return;
    }
    btn.addEventListener('click', function() {
        var open = wrap.style.display === 'none';
        wrap.style.display = open ? '' : 'none';
        icon.innerHTML = open ? '&darr;' : '&uarr;';
    });
})();
JS;
echo Html::scriptBlock($tagsJs);

if (!$from_tab) {
    Html::footer();
}
