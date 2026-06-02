<?php

include('../../../inc/includes.php');

Session::checkLoginUser();
Session::checkRight(PluginTickettransferProfile::RIGHTNAME, PluginTickettransferProfile::RIGHT_PREFERENCES);

$userId = Session::getLoginUserID();
$postedUserId = (int) ($_POST['users_id'] ?? 0);
if ($postedUserId <= 0 || $postedUserId !== $userId) {
    Html::displayRightError();
}

if (isset($_POST['update_prefs'])) {
    if (PluginTickettransferPreference::saveUserPreferences($userId, $_POST)) {
        Session::addMessageAfterRedirect(__('Preferences mises a jour.', 'tickettransfer'), true, INFO);
    } else {
        Session::addMessageAfterRedirect(__('Echec de la mise a jour des preferences.', 'tickettransfer'), true, ERROR);
    }
}

/** @var array $CFG_GLPI */
global $CFG_GLPI;
$redirect = $CFG_GLPI['root_doc'] . '/front/preference.php';
Html::redirect($redirect);
