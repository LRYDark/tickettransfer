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

    // Mise en forme de la fenetre : large et centree sur ordinateur, panneau
    // ancre en bas de l'ecran sur telephone (meme comportement que les modals
    // des plugins RP et Gestion). Les largeurs en `vw` etaient auparavant
    // posees en style inline par JS, ce qui rendait la fenetre illisible sur
    // mobile (55% de la largeur d'un telephone).
    $modalCss = <<<'CSS'
    @media (min-width: 769px) {
        .modal.tickettransfer-modal .modal-dialog {
            width: 55vw;
            min-width: 620px;
            max-width: 1050px;
        }
        .modal.tickettransfer-modal .modal-body {
            max-height: 82vh;
            overflow-y: auto;
        }
    }

    @media (max-width: 768px) {
        .modal.tickettransfer-modal .modal-dialog {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100%;
            max-width: 100%;
            margin: 0;
            display: flex;
            align-items: flex-end;
            /* le panneau reste a la hauteur de son contenu, quelles que soient
               les classes Bootstrap ajoutees par le coeur GLPI */
            height: auto !important;
            max-height: 92svh;
        }

        .modal.tickettransfer-modal .modal-content {
            width: 100%;
            max-height: 92svh;
            border-bottom-left-radius: 0;
            border-bottom-right-radius: 0;
            border-bottom: 0;
        }

        .modal.tickettransfer-modal .modal-body {
            padding: .5rem;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        /* Le panneau glisse depuis le bas */
        .modal.tickettransfer-modal.fade .modal-dialog {
            transform: translateY(100%);
            transition: transform .25s ease-out;
        }

        .modal.tickettransfer-modal.show .modal-dialog {
            transform: none;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .modal.tickettransfer-modal.fade .modal-dialog { transition: none; }
    }

    /* Barre d'actions du ticket, telephone uniquement.
       `.filter-timeline` (coeur GLPI) accueille les boutons du plugin. C'est un
       element flex retrecissable dont les enfants sont des `inline-block` :
       quand la place manque il se reduit a la largeur d'une icone et les
       boutons s'empilent en colonne, ce qui etire toute la barre. On lui rend
       sa largeur de contenu et on compacte les icones ; le bouton principal
       (« Repondre ») cede la largeur necessaire en tronquant son libelle, sans
       jamais descendre sous la taille de son icone. */
    @media (max-width: 768px) {
        #itil-footer .buttons-bar .timeline-buttons {
            align-items: center;
            min-width: 0;
        }

        #itil-footer .buttons-bar .filter-timeline {
            display: flex;
            flex: 0 0 auto;
            flex-wrap: nowrap;
            align-items: center;
        }

        #itil-footer .buttons-bar .filter-timeline .btn-icon {
            width: 1.9rem;
            min-width: 1.9rem;
            height: 1.9rem;
            padding: 0;
        }

        #itil-footer .buttons-bar .main-actions {
            min-width: 0;
        }

        #itil-footer .buttons-bar .main-actions .btn {
            min-width: 2.75rem;
            overflow: hidden;
        }

        #itil-footer .buttons-bar .main-actions .btn span {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
    }
    CSS;

    $transferButtonId = 'tickettransfer_btn_' . $ticketId;
    $transferLabel = Html::entities_deep(__('Transferer ticket', 'tickettransfer'));
    echo "<style id='tickettransfer_modal_css'>{$modalCss}</style>";
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
        // La feuille de style est ecrite dans la liste d'actions de la timeline
        // (seul endroit ou le hook peut produire du HTML) : on la deplace dans
        // le <head> pour ne rien laisser d'etranger dans le DOM du ticket.
        var css = document.getElementById('tickettransfer_modal_css');
        if (css && css.parentNode !== document.head) {
            document.head.appendChild(css);
        }

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

        var modal = document.getElementById('{$transferModalId}');
        if (modal) {
            modal.classList.add('tickettransfer-modal');
        }
    });
JS;
    echo Html::scriptBlock($js);
}
