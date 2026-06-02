<?php

/**
 * Plugin install process.
 *
 * @return bool
 */
function plugin_tickettransfer_install()
{
    include_once __DIR__ . '/sql/install.php';
    return PluginTickettransferInstall::install();
}

/**
 * Plugin uninstall process.
 *
 * @return bool
 */
function plugin_tickettransfer_uninstall()
{
    include_once __DIR__ . '/sql/install.php';
    return PluginTickettransferInstall::uninstall();
}

/**
 * Add a "Transfer ticket" action to the ticket timeline.
 *
 * @param array $params
 * @return void
 */
function plugin_tickettransfer_timeline_actions(array $params): void
{
    if (empty($params['item']) || !($params['item'] instanceof Ticket)) {
        return;
    }

    if (!Session::haveRight(PluginTickettransferProfile::RIGHTNAME, PluginTickettransferProfile::RIGHT_TRANSFER)) {
        return;
    }

    /** @var Ticket $ticket */
    $ticket = $params['item'];
    if ($ticket->isNewItem()) {
        return;
    }

    $ticketId = (int) $ticket->getID();
    if ($ticketId <= 0) {
        return;
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
        return;
    }

    /** @var array $CFG_GLPI */
    global $CFG_GLPI;

    $transferModalId = 'tickettransfer_modal_' . $ticketId;
    $transferUrl = $CFG_GLPI['root_doc'] . '/plugins/tickettransfer/front/ticket.transfer.php?tickets_id=' . $ticketId;
    $transferModal = Ajax::createIframeModalWindow($transferModalId, $transferUrl, [
        'width'         => 900,
        'height'        => 750,
        'dialog_class'  => 'modal-lg',
        'title'         => __('Transferer ticket', 'tickettransfer'),
        'reloadonclose' => true,
        'display'       => false,
    ]);

    $transferButtonId = 'tickettransfer_btn_' . $ticketId;
    $transferLabel = Html::entities_deep(__('Transferer ticket', 'tickettransfer'));
    echo $transferModal;
    echo "<li class='tickettransfer-timeline-action'>";
    echo "<span id='{$transferButtonId}' class='me-1' data-bs-toggle='tooltip' data-bs-placement='top' title='{$transferLabel}'>";
    echo "<button type='button' class='btn btn-icon btn-ghost-secondary' data-bs-toggle='modal' data-bs-target='#{$transferModalId}'>";
    echo "<i class='ti ti-user-share'></i>";
    echo "</button>";
    echo "</span>";
    echo "</li>";

    $js = <<<JS
    $(function() {
        var btn = document.getElementById('{$transferButtonId}');
        if (!btn) {
            return;
        }
        var target = document.querySelector('.filter-timeline');
        if (!target) {
            return;
        }
        target.insertBefore(btn, target.firstChild);
        var li = btn.closest('li');
        if (li) {
            li.remove();
        }

        var dialog = document.querySelector('#{$transferModalId} .modal-dialog');
        if (dialog) {
            dialog.style.maxWidth = '55vw';
            dialog.style.width = '55vw';
        }
    });
JS;
    echo Html::scriptBlock($js);
}
