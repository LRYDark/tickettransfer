<?php


Session::checkLoginUser();
Session::checkRight(PluginTickettransferProfile::RIGHTNAME, PluginTickettransferProfile::RIGHT_TRANSFER);

/** @var array $CFG_GLPI */
global $CFG_GLPI;

$ticketId = (int) ($_GET['tickets_id'] ?? ($_POST['tickets_id'] ?? 0));
if ($ticketId <= 0) {
    throw new \Glpi\Exception\Http\BadRequestHttpException(__('Ticket manquant.', 'tickettransfer'));
}

$ticket = new Ticket();
if (!$ticket->getFromDB($ticketId)) {
    throw new \Glpi\Exception\Http\BadRequestHttpException(__('Ticket introuvable.', 'tickettransfer'));
}

$canedit = false;
if (Session::haveRight(Entity::$rightname, UPDATE)) {
    $canedit = true;
} elseif (
    $ticket->canEdit($ticketId)
    && !in_array($ticket->fields['status'], array_merge(Ticket::getSolvedStatusArray(), Ticket::getClosedStatusArray()))
) {
    $canedit = true;
}

if (!$canedit) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$baseUrl = $CFG_GLPI['root_doc'] . '/plugins/tickettransfer/front/ticket.transfer.php?tickets_id=' . $ticketId;
if (!empty($_REQUEST['_in_modal'])) {
    $baseUrl .= '&_in_modal=1';
}

$preferences = PluginTickettransferPreference::getUserPreferences(Session::getLoginUserID());
$assignOnTransfer = (int) $preferences['assign_on_transfer'] === 1;
$replaceOnTransfer = (int) $preferences['replace_on_transfer'] === 1;
$replaceOnTransfer = $assignOnTransfer ? $replaceOnTransfer : false;
$statusSetting = PluginTickettransferPreference::getEffectiveStatusSetting(Session::getLoginUserID());
$changeStatusOnTransfer = $statusSetting['change'];
$transferStatus = $statusSetting['status'];
// Per-transfer override (dropdown in the modal) only when admin allows it.
$allowModalStatusOverride = $changeStatusOnTransfer && $statusSetting['allow_modal'];
$transferComment = '';

$errors = [];
$selectedUsers = [];
$selectedGroups = [];

if (isset($_POST['transfer'])) {
    /** @var DBmysql $DB */
    global $DB;

    $assignOnTransfer = !empty($_POST['assign_on_transfer']);
    $replaceOnTransfer = !empty($_POST['replace_on_transfer']);
    if (!$assignOnTransfer) {
        $replaceOnTransfer = false;
    }
    if ($allowModalStatusOverride && isset($_POST['transfer_status'])) {
        $postedStatus = (int) $_POST['transfer_status'];
        if (array_key_exists($postedStatus, Ticket::getAllStatusArray(false))) {
            $transferStatus = $postedStatus;
        }
    }
    $transferComment = trim((string) ($_POST['transfer_comment'] ?? ''));

    // Combined field returns values prefixed "User_<id>" and "Group_<id>".
    $selectedTargets = $_POST['transfer_targets'] ?? [];
    if (!is_array($selectedTargets)) {
        $selectedTargets = [$selectedTargets];
    }
    foreach ($selectedTargets as $target) {
        if (preg_match('/^User_(\d+)$/', (string) $target, $m)) {
            $selectedUsers[] = (int) $m[1];
        } elseif (preg_match('/^Group_(\d+)$/', (string) $target, $m)) {
            $selectedGroups[] = (int) $m[1];
        }
    }
    $selectedUsers = array_values(array_unique(array_filter($selectedUsers)));
    $selectedGroups = array_values(array_unique(array_filter($selectedGroups)));

    if (empty($selectedUsers) && empty($selectedGroups)) {
        $errors[] = __('Veuillez selectionner au moins un utilisateur ou un groupe.', 'tickettransfer');
    }

    $emailSet = [];          // lowercase email => email (dedupe)
    $recipientNames = [];     // for ticket history
    $missingEmails = [];
    $invalidUsers = [];
    $validUsers = [];         // direct users to assign
    $validGroups = [];        // groups to assign

    foreach ($selectedUsers as $userId) {
        $user = new User();
        if (!$user->getFromDB($userId) || (int) ($user->fields['is_active'] ?? 0) !== 1) {
            $invalidUsers[] = $userId;
            continue;
        }
        $validUsers[] = $userId;
        $recipientNames[] = $user->getName();
        $email = $user->getDefaultEmail();
        if ($email === '') {
            $missingEmails[] = $user->getName();
            continue;
        }
        $emailSet[strtolower($email)] = $email;
    }

    foreach ($selectedGroups as $groupId) {
        $group = new Group();
        if (!$group->getFromDB($groupId)) {
            continue;
        }
        $validGroups[] = $groupId;
        $recipientNames[] = $group->getName();

        $groupHasEmail = false;
        $members = $DB->request([
            'SELECT' => 'users_id',
            'FROM'   => 'glpi_groups_users',
            'WHERE'  => ['groups_id' => $groupId],
        ]);
        foreach ($members as $member) {
            $memberUser = new User();
            if (
                !$memberUser->getFromDB((int) $member['users_id'])
                || (int) ($memberUser->fields['is_active'] ?? 0) !== 1
                || (int) ($memberUser->fields['is_deleted'] ?? 0) === 1
            ) {
                continue;
            }
            $memberEmail = $memberUser->getDefaultEmail();
            if ($memberEmail !== '') {
                $emailSet[strtolower($memberEmail)] = $memberEmail;
                $groupHasEmail = true;
            }
        }
        if (!$groupHasEmail) {
            $missingEmails[] = $group->getName();
        }
    }

    $recipients = array_values($emailSet);

    if (!empty($invalidUsers)) {
        $errors[] = __('Certains utilisateurs selectionnes sont invalides.', 'tickettransfer');
    }
    if (!empty($missingEmails)) {
        $errors[] = sprintf(
            __('Adresse email manquante pour : %s', 'tickettransfer'),
            implode(', ', $missingEmails)
        );
    }
    if (empty($recipients)) {
        $errors[] = __('Aucune adresse email de destinataire trouvee.', 'tickettransfer');
    }

    if ($assignOnTransfer && !$ticket->canAssign()) {
        $errors[] = __('Vous n avez pas le droit d attribuer ce ticket.', 'tickettransfer');
    }

    if (empty($errors)) {
        if ($assignOnTransfer) {
            $ticketUser = new Ticket_User();
            $groupTicket = new Group_Ticket();

            if ($replaceOnTransfer) {
                $DB->delete('glpi_tickets_users', [
                    'tickets_id' => $ticketId,
                    'type'       => CommonITILActor::ASSIGN,
                ]);
                $DB->delete('glpi_groups_tickets', [
                    'tickets_id' => $ticketId,
                    'type'       => CommonITILActor::ASSIGN,
                ]);
                $DB->delete('glpi_suppliers_tickets', [
                    'tickets_id' => $ticketId,
                    'type'       => CommonITILActor::ASSIGN,
                ]);
            }

            $existing = [];
            foreach ($ticketUser->find([
                'tickets_id' => $ticketId,
                'type'       => CommonITILActor::ASSIGN,
            ]) as $row) {
                $existing[(int) $row['users_id']] = true;
            }

            foreach ($validUsers as $userId) {
                if (isset($existing[$userId])) {
                    continue;
                }
                $ticketUser->add([
                    'tickets_id' => $ticketId,
                    'users_id'   => $userId,
                    'type'       => CommonITILActor::ASSIGN,
                    '_from_object' => true,
                ]);
            }

            $existingGroups = [];
            foreach ($groupTicket->find([
                'tickets_id' => $ticketId,
                'type'       => CommonITILActor::ASSIGN,
            ]) as $row) {
                $existingGroups[(int) $row['groups_id']] = true;
            }

            foreach ($validGroups as $groupId) {
                if (isset($existingGroups[$groupId])) {
                    continue;
                }
                $groupTicket->add([
                    'tickets_id' => $ticketId,
                    'groups_id'  => $groupId,
                    'type'       => CommonITILActor::ASSIGN,
                    '_from_object' => true,
                ]);
            }
        }

        if ($changeStatusOnTransfer && (int) $ticket->fields['status'] !== $transferStatus) {
            $ticket->update([
                'id'     => $ticketId,
                'status' => $transferStatus,
            ]);
        }

        $ticketPath = Ticket::getFormURLWithID($ticketId, false);
        if ($ticketPath !== '' && $ticketPath[0] !== '/') {
            $ticketPath = '/' . $ticketPath;
        }
        $ticketUrl = $ticketPath;
        if (!empty($CFG_GLPI['url_base'])) {
            $ticketUrl = rtrim($CFG_GLPI['url_base'], '/') . $ticketPath;
        }
        $senderName = getUserName(Session::getLoginUserID());
        $recipientList = implode(', ', $recipientNames);

        // Plugin tags available in the configurable notification template.
        $tags = [
            '##ticket.id##'           => (string) $ticketId,
            '##ticket.title##'        => $ticket->getName(),
            '##ticket.url##'          => $ticketUrl,
            '##transfer.author##'     => $senderName,
            '##transfer.recipients##' => $recipientList,
            '##transfer.comment##'    => ($transferComment !== '' ? $transferComment : __('(aucun)', 'tickettransfer')),
        ];
        // HTML-safe variants of the values for the HTML body.
        $htmlTags = [];
        foreach ($tags as $tag => $value) {
            if ($tag === '##ticket.url##') {
                $htmlTags[$tag] = htmlescape($value);
            } elseif ($tag === '##transfer.comment##') {
                $htmlTags[$tag] = nl2br(htmlescape($value));
            } else {
                $htmlTags[$tag] = htmlescape($value);
            }
        }

        $subject  = '';
        $bodyText = '';
        $bodyHtml = '';

        // Load the configured template (with session-language fallback).
        $templateId = (int) (PluginTickettransferConfig::getConfig()['notificationtemplates_id'] ?? 0);
        if ($templateId > 0) {
            $curLang = $_SESSION['glpilanguage'] ?? ($CFG_GLPI['language'] ?? 'fr_FR');
            $langs   = array_values(array_unique([$curLang, substr((string) $curLang, 0, 2), '']));
            $order   = new \Glpi\DBAL\QueryExpression(
                "FIELD(language,'" . implode("','", array_map('addslashes', $langs)) . "')"
            );
            $tpl = $DB->request([
                'SELECT' => ['subject', 'content_text', 'content_html'],
                'FROM'   => 'glpi_notificationtemplatetranslations',
                'WHERE'  => ['notificationtemplates_id' => $templateId],
                'ORDER'  => [$order],
                'LIMIT'  => 1,
            ])->current();
            if (is_array($tpl)) {
                $subject  = strtr((string) ($tpl['subject'] ?? ''), $tags);
                $bodyText = strtr((string) ($tpl['content_text'] ?? ''), $tags);
                $bodyHtml = strtr((string) ($tpl['content_html'] ?? ''), $htmlTags);
            }
        }

        // Built-in fallback when no template/translation is configured.
        if ($subject === '') {
            $subject = sprintf('[GLPI] %s #%d', __('Ticket transfere', 'tickettransfer'), $ticketId);
        }
        if ($bodyText === '' && $bodyHtml === '') {
            $textLines = [
                sprintf(__('Ticket #%d transfere par %s', 'tickettransfer'), $ticketId, $senderName),
                sprintf(__('Titre : %s', 'tickettransfer'), $ticket->getName()),
                sprintf(__('Lien : %s', 'tickettransfer'), $ticketUrl),
            ];
            $htmlLines = [
                sprintf(__('Ticket #%d transfere par %s', 'tickettransfer'), $ticketId, $htmlTags['##transfer.author##']),
                sprintf(__('Titre : %s', 'tickettransfer'), $htmlTags['##ticket.title##']),
                sprintf(__('Lien : %s', 'tickettransfer'), "<a href=\"{$htmlTags['##ticket.url##']}\">{$htmlTags['##ticket.url##']}</a>"),
            ];
            if ($transferComment !== '') {
                $commentLabel = __('Commentaire', 'tickettransfer');
                $textLines[] = $commentLabel . ' : ' . $transferComment;
                $htmlLines[] = $commentLabel . ' : ' . $htmlTags['##transfer.comment##'];
            }
            $bodyText = implode(PHP_EOL, $textLines);
            $bodyHtml = implode('<br>', $htmlLines);
        }

        // File d'attente des notifications de GLPI, envoi immédiat : le résultat s'affiche tout de suite comme avant ;
        // un envoi en échec reste en file et GLPI le réessaie (tâche « queuednotification »). Une ligne par
        // destinataire : chacun reçoit son mail, sans voir les adresses des autres.
        $sender = Config::getEmailSender();
        $result = PluginTickettransferMailqueue::send([
            'itemtype'    => Ticket::class,
            'items_id'    => $ticketId,
            'entities_id' => (int) ($ticket->fields['entities_id'] ?? 0),
            'event'       => 'plugin_tickettransfer_transfer',
            'subject'     => $subject,
            'text'        => $bodyText !== '' ? $bodyText : strip_tags($bodyHtml),
            'html'        => $bodyHtml !== '' ? $bodyHtml : nl2br(htmlescape($bodyText)),
            'to'          => array_map(static fn($recipient): array => [(string) $recipient, ''], $recipients),
            'from'        => !empty($sender['email']) ? [(string) $sender['email'], (string) ($sender['name'] ?? '')] : null,
        ], true);
        if ($result['pending'] !== []) {
            // Transfert fait, mail en file : GLPI le renverra tout seul.
            Session::addMessageAfterRedirect(htmlescape(sprintf(
                __('Le mail de transfert n\'est pas encore parti (%s) : il reste en file d\'attente et GLPI le renverra automatiquement.', 'tickettransfer'),
                implode(' ; ', array_unique($result['errors']))
            )), true, WARNING);
        }

        // Transfert réussi dès qu'un mail est parti ou reste en file (GLPI le renverra) ; échec seulement si rien
        // n'a pu partir ni être mis en file.
        if ($result['sent'] > 0 || $result['pending'] !== []) {
            $historyMessage = sprintf(
                __('%1$s a transfere le ticket a : %2$s', 'tickettransfer'),
                $senderName,
                implode(', ', $recipientNames)
            );
            Log::history(
                $ticketId,
                'Ticket',
                [0, '', $historyMessage],
                '',
                Log::HISTORY_LOG_SIMPLE_MESSAGE
            );

            Session::addMessageAfterRedirect(__('Ticket transfere.', 'tickettransfer'), true, INFO);
            Html::redirect($baseUrl);
        } else {
            $errors[] = __('Echec de l envoi de l email.', 'tickettransfer');
        }
    }
}

Html::header(
    __('Transferer ticket', 'tickettransfer'),
    $_SERVER['PHP_SELF'],
    'plugins',
    'tickettransfer',
    'ticket'
);

Html::displayMessageAfterRedirect();

echo "<div class='card'><div class='card-body'>";
echo "<div class='mb-3'><strong>Ticket</strong> " . (int) $ticketId . " - " . htmlescape($ticket->getName()) . "</div>";

if (!empty($errors)) {
    echo "<div class='alert alert-danger'>";
    foreach ($errors as $error) {
        echo "<div>" . htmlescape($error) . "</div>";
    }
    echo "</div>";
}

echo "<form method='post' action='" . htmlescape($baseUrl) . "'>";
echo Html::hidden('tickets_id', ['value' => $ticketId]);
if (!empty($_REQUEST['_in_modal'])) {
    echo Html::hidden('_in_modal', ['value' => 1]);
}

// Build pre-selected <option> entries (kept on validation errors).
$selectedOptions = '';
foreach ($selectedUsers as $userId) {
    $u = new User();
    if ($u->getFromDB($userId)) {
        $uName = $u->getName();
        $uEmail = $u->getDefaultEmail();
        $uTitle = $uEmail !== '' ? sprintf('%s - %s', $uName, $uEmail) : $uName;
        $selectedOptions .= "<option value='User_{$userId}' title='" . htmlescape($uTitle) . "' selected>"
            . htmlescape($uName) . "</option>";
    }
}
foreach ($selectedGroups as $groupId) {
    $g = new Group();
    if ($g->getFromDB($groupId)) {
        $gName = (string) ($g->fields['completename'] !== '' ? $g->fields['completename'] : $g->getName());
        $selectedOptions .= "<option value='Group_{$groupId}' selected>"
            . htmlescape($gName) . "</option>";
    }
}

echo "<div class='mb-3'>";
echo "<label class='form-label mb-1' for='tickettransfer_targets'>" . __('Destinataires (utilisateurs / groupes)', 'tickettransfer') . "</label>";
echo "<select id='tickettransfer_targets' name='transfer_targets[]' multiple class='form-select' style='width:100%'>";
echo $selectedOptions;
echo "</select>";
echo "<div class='form-text'>" . __('Pour un groupe, le transfert sera envoye a tous ses utilisateurs.', 'tickettransfer') . "</div>";
echo "</div>";

echo "<div class='mb-3'>";
echo "<div class='form-check'>";
echo "<input class='form-check-input' type='checkbox' id='tickettransfer_assign_on_transfer' name='assign_on_transfer' value='1'"
    . ($assignOnTransfer ? ' checked' : '') . ">";
echo "<label class='form-check-label' for='tickettransfer_assign_on_transfer'>"
    . __('Associer les utilisateurs transferes dans "Attribue a"', 'tickettransfer') . "</label>";
echo "</div>";
echo "</div>";

echo "<div class='mb-3'>";
echo "<div class='form-check'>";
echo "<input class='form-check-input' type='checkbox' id='tickettransfer_replace_on_transfer' name='replace_on_transfer' value='1'"
    . ($replaceOnTransfer ? ' checked' : '') . (!$assignOnTransfer ? ' disabled' : '') . ">";
echo "<label class='form-check-label' for='tickettransfer_replace_on_transfer'>"
    . __('Remplacer les utilisateurs deja attribues', 'tickettransfer') . "</label>";
echo "</div>";
echo "</div>";

echo "<div class='alert alert-info mb-3' id='tickettransfer_transfer_info'>";
if (!$assignOnTransfer) {
    echo htmlescape(__('Les utilisateurs transferes ne seront pas associes dans "Attribue a".', 'tickettransfer'));
} elseif ($replaceOnTransfer) {
    echo htmlescape(__('Les utilisateurs transferes remplaceront les assignes existants.', 'tickettransfer'));
} else {
    echo htmlescape(__('Les utilisateurs transferes seront ajoutes aux assignes existants.', 'tickettransfer'));
}
echo "</div>";

if ($allowModalStatusOverride) {
    echo "<div class='mb-3'>";
    echo "<label class='form-label mb-1' for='dropdown_transfer_status'>" . __('Statut du ticket apres transfert', 'tickettransfer') . "</label>";
    Ticket::dropdownStatus([
        'name'  => 'transfer_status',
        'value' => $transferStatus,
    ]);
    echo "</div>";
}

echo "<div class='mb-3'>";
echo "<label class='form-label mb-1' for='tickettransfer_transfer_comment'>" . __('Commentaire', 'tickettransfer') . "</label>";
echo "<textarea class='form-control' id='tickettransfer_transfer_comment' name='transfer_comment' rows='3'>"
    . htmlescape($transferComment) . "</textarea>";
echo "</div>";

echo "<div class='text-end'>";
echo Html::submit(__('Transferer', 'tickettransfer'), ['name' => 'transfer', 'class' => 'btn btn-primary']);
echo "</div>";
echo "</form>";

echo "</div></div>";

$infoNoAssign = json_encode(__('Les utilisateurs transferes ne seront pas associes dans "Attribue a".', 'tickettransfer'));
$infoReplace = json_encode(__('Les utilisateurs transferes remplaceront les assignes existants.', 'tickettransfer'));
$infoAppend = json_encode(__('Les utilisateurs transferes seront ajoutes aux assignes existants.', 'tickettransfer'));
$js = <<<JS
(function() {
    var assign = document.getElementById('tickettransfer_assign_on_transfer');
    var replace = document.getElementById('tickettransfer_replace_on_transfer');
    var info = document.getElementById('tickettransfer_transfer_info');
    if (!assign || !replace || !info) {
        return;
    }
    var update = function() {
        if (!assign.checked) {
            replace.checked = false;
            replace.disabled = true;
            info.textContent = {$infoNoAssign};
            return;
        }
        replace.disabled = false;
        if (replace.checked) {
            info.textContent = {$infoReplace};
        } else {
            info.textContent = {$infoAppend};
        }
    };
    assign.addEventListener('change', update);
    replace.addEventListener('change', update);
    update();
})();
JS;
echo Html::scriptBlock($js);

$targetsUrl = json_encode($CFG_GLPI['root_doc'] . '/plugins/tickettransfer/front/ajax/transfertargets.php');
$targetsPlaceholder = json_encode(__('Rechercher un utilisateur ou un groupe...', 'tickettransfer'));
$selectJs = <<<JS
$(function() {
    var \$select = $('#tickettransfer_targets');
    if (!\$select.length || typeof \$select.select2 !== 'function') {
        return;
    }
    \$select.select2({
        width: '100%',
        placeholder: {$targetsPlaceholder},
        allowClear: true,
        minimumInputLength: 0,
        dropdownParent: \$select.parent(),
        ajax: {
            url: {$targetsUrl},
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return { searchText: params.term || '' };
            },
            processResults: function(data) {
                return data;
            },
            cache: true
        },
        templateResult: function(item) {
            if (item.loading || !item.id) {
                return item.text;
            }
            var \$row = $('<span></span>').text(item.text);
            if (item.title) {
                \$row.attr('title', item.title);
            }
            return \$row;
        },
        templateSelection: function(item) {
            var \$sel = $('<span></span>').text(item.text || '');
            if (item.title) {
                \$sel.attr('title', item.title);
            }
            return \$sel;
        }
    });
});
JS;
echo Html::scriptBlock($selectJs);

Html::footer();
