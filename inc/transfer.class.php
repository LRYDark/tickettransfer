<?php

/**
 * Un transfert de ticket : qui, quand, et le commentaire parti dans le mail.
 *
 * Sa ligne dans l'historique du ticket est une action de plugin (`linked_action` >= Log::HISTORY_PLUGIN) qui pointe
 * sur cette classe : GLPI remplit la colonne « Champ » avec getTypeName() et le texte avec getHistoryEntry(). Le
 * numéro du transfert est rangé dans `glpi_logs.new_id` ; le texte de la ligne reste dans `new_value`.
 */
class PluginTickettransferTransfer extends CommonDBTM
{
    /** Action d'historique « transfert de ticket », dans la plage que GLPI réserve aux plugins. */
    public const HISTORY_TRANSFER = Log::HISTORY_PLUGIN + 1;

    /** Texte des lignes d'historique écrites avant la 1.1.3 (simples messages), repris par la migration. */
    public const LEGACY_HISTORY_PATTERN = '% a transfere le ticket a : %';

    public static function getTypeName($nb = 0)
    {
        return __('Transfert de ticket', 'tickettransfer');
    }

    /**
     * Enregistre le transfert et écrit sa ligne dans l'historique du ticket.
     *
     * @param int    $ticketId
     * @param string $message  texte de la ligne (« X a transfere le ticket a : … »)
     * @param string $comment  commentaire envoyé dans le mail, vide si aucun
     *
     * @return void
     */
    public static function addToHistory(int $ticketId, string $message, string $comment): void
    {
        $transfer = new self();
        $transferId = $transfer->add([
            'tickets_id' => $ticketId,
            'users_id'   => (int) Session::getLoginUserID(),
            'comment'    => $comment,
        ]);

        // Si l'enregistrement échoue, la ligne d'historique est écrite quand même, sans bulle.
        Log::history(
            $ticketId,
            Ticket::class,
            [0, '', $message, null, $transferId ?: null],
            self::class,
            self::HISTORY_TRANSFER
        );
    }

    /**
     * Texte d'une ligne d'historique de transfert, appelé par Log::getHistoryData(). Le résultat est affiché tel
     * quel (HTML) : tout ce qui vient de la base est échappé ici.
     *
     * @param array $data ligne de `glpi_logs`
     *
     * @return string
     */
    public static function getHistoryEntry(array $data): string
    {
        $entry = htmlescape((string) ($data['new_value'] ?? ''));

        // Lignes d'avant la 1.1.3 (aucun transfert enregistré) ou transfert sans commentaire : pas de bulle.
        $transferId = (int) ($data['new_id'] ?? 0);
        $transfer = new self();
        if ($entry === '' || $transferId <= 0 || !$transfer->getFromDB($transferId)) {
            return $entry;
        }
        $comment = trim((string) ($transfer->fields['comment'] ?? ''));
        if ($comment === '') {
            return $entry;
        }

        return $entry . ' ' . Html::showToolTip(nl2br(htmlescape($comment)), [
            'awesome-class' => 'fa-info-circle',
            'display'       => false,
        ]);
    }
}
