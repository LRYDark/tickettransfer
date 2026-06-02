<?php

class PluginTickettransferInstall
{
    /**
     * Install plugin database schema and defaults.
     *
     * @return bool
     */
    public static function install(): bool
    {
        global $DB;

        foreach (glob(__DIR__ . '/../inc/*.class.php') as $filepath) {
            if (preg_match("/inc.(.+)\.class.php/", $filepath) !== 0) {
                include_once $filepath;
            }
        }

        $migration = new Migration(PLUGIN_TICKETTRANSFER_VERSION);

        $charset = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $keySign = DBConnection::getDefaultPrimaryKeySignOption();

        // Config table
        $configTable = 'glpi_plugin_tickettransfer_configs';
        if (!$DB->tableExists($configTable)) {
            $query = <<<SQL
                CREATE TABLE `$configTable` (
                    `id` int $keySign NOT NULL auto_increment,
                    `change_status_on_transfer` tinyint NOT NULL DEFAULT '0',
                    `transfer_status` int NOT NULL DEFAULT '4',
                    `allow_pref_status` tinyint NOT NULL DEFAULT '1',
                    `allow_modal_status` tinyint NOT NULL DEFAULT '1',
                    `notificationtemplates_id` int $keySign NOT NULL DEFAULT '0',
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;
SQL;
            $DB->doQuery($query);

            $DB->insert($configTable, [
                'change_status_on_transfer' => 0,
                'transfer_status'           => 4,
                'allow_pref_status'         => 1,
                'allow_modal_status'        => 1,
                'notificationtemplates_id'  => 0,
            ]);
        } else {
            if (!$DB->fieldExists($configTable, 'change_status_on_transfer')) {
                $migration->addField($configTable, 'change_status_on_transfer', 'bool', ['value' => 0]);
            }
            if (!$DB->fieldExists($configTable, 'transfer_status')) {
                $migration->addField($configTable, 'transfer_status', 'integer', ['value' => 4]);
            }
            if (!$DB->fieldExists($configTable, 'allow_pref_status')) {
                $migration->addField($configTable, 'allow_pref_status', 'bool', ['value' => 1]);
            }
            if (!$DB->fieldExists($configTable, 'allow_modal_status')) {
                $migration->addField($configTable, 'allow_modal_status', 'bool', ['value' => 1]);
            }
            if (!$DB->fieldExists($configTable, 'notificationtemplates_id')) {
                $migration->addField($configTable, 'notificationtemplates_id', 'integer', ['value' => 0]);
            }
        }

        // Preferences table
        $prefTable = 'glpi_plugin_tickettransfer_preferences';
        if (!$DB->tableExists($prefTable)) {
            $query = <<<SQL
                CREATE TABLE `$prefTable` (
                    `id` int $keySign NOT NULL auto_increment,
                    `users_id` int $keySign NOT NULL DEFAULT '0',
                    `assign_on_transfer` tinyint NOT NULL DEFAULT '1',
                    `replace_on_transfer` tinyint NOT NULL DEFAULT '1',
                    `change_status_on_transfer` tinyint NOT NULL DEFAULT '0',
                    `transfer_status` int NOT NULL DEFAULT '4',
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `users_id` (`users_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;
SQL;
            $DB->doQuery($query);
        } else {
            if (!$DB->fieldExists($prefTable, 'change_status_on_transfer')) {
                $migration->addField($prefTable, 'change_status_on_transfer', 'bool', ['value' => 0]);
            }
            if (!$DB->fieldExists($prefTable, 'transfer_status')) {
                $migration->addField($prefTable, 'transfer_status', 'integer', ['value' => 4]);
            }
            $migration->addKey($prefTable, 'users_id', 'users_id', 'UNIQUE');
        }

        if (class_exists(PluginTickettransferProfile::class)) {
            PluginTickettransferProfile::install($migration);
        }

        $migration->executeMigration();

        // Create the default notification template (editable in GLPI) and
        // associate it with the plugin if no template is configured yet.
        self::installNotificationTemplate();

        return true;
    }

    /**
     * Create a default, editable email notification template and set it as the
     * plugin default. Idempotent: an existing plugin template is reused.
     *
     * @return void
     */
    public static function installNotificationTemplate(): void
    {
        global $DB;

        $configTable = 'glpi_plugin_tickettransfer_configs';
        if (!$DB->tableExists($configTable) || !$DB->fieldExists($configTable, 'notificationtemplates_id')) {
            return;
        }

        $marker = 'Created by the plugin tickettransfer';

        // Reuse the plugin template if it already exists, otherwise create it.
        $existing = $DB->request([
            'SELECT' => 'id',
            'FROM'   => 'glpi_notificationtemplates',
            'WHERE'  => ['comment' => $marker],
            'LIMIT'  => 1,
        ])->current();

        if (is_array($existing) && !empty($existing['id'])) {
            $templateId = (int) $existing['id'];
        } else {
            $DB->insert('glpi_notificationtemplates', [
                'name'     => 'Transfert de ticket',
                'itemtype' => 'Ticket',
                'comment'  => $marker,
                'css'      => '',
            ]);
            $templateId = (int) $DB->insertId();
        }

        if ($templateId <= 0) {
            return;
        }

        // Ensure the template has its translation (idempotent).
        $hasTranslation = $DB->request([
            'SELECT' => 'id',
            'FROM'   => 'glpi_notificationtemplatetranslations',
            'WHERE'  => ['notificationtemplates_id' => $templateId],
            'LIMIT'  => 1,
        ])->current();
        if (!is_array($hasTranslation) || empty($hasTranslation['id'])) {
            $DB->insert('glpi_notificationtemplatetranslations', [
                'notificationtemplates_id' => $templateId,
                'language'                 => '',
                'subject'                  => self::getDefaultSubject(),
                'content_text'             => self::getDefaultTextContent(),
                'content_html'             => self::getDefaultHtmlContent(),
            ]);
        }

        // Associate by default when unset, or repair the bogus value (1).
        $config = $DB->request([
            'SELECT' => ['id', 'notificationtemplates_id'],
            'FROM'   => $configTable,
            'LIMIT'  => 1,
        ])->current();

        if (is_array($config) && (int) ($config['notificationtemplates_id'] ?? 0) === 0) {
            $DB->update(
                $configTable,
                ['notificationtemplates_id' => $templateId],
                ['id' => (int) $config['id']]
            );
        }
    }

    /**
     * Default mail subject (supports plugin tags).
     */
    public static function getDefaultSubject(): string
    {
        return '[GLPI] Ticket transféré ###ticket.id##';
    }

    /**
     * Default plain-text body (supports plugin tags).
     */
    public static function getDefaultTextContent(): string
    {
        return "Bonjour,\n\n"
            . "Le ticket ###ticket.id## vous a été transféré par ##transfer.author##.\n\n"
            . "Titre : ##ticket.title##\n"
            . "Lien  : ##ticket.url##\n\n"
            . "Commentaire : ##transfer.comment##\n\n"
            . "--\nE-mail envoyé automatiquement par GLPI lors du transfert d'un ticket.";
    }

    /**
     * Default HTML body (supports plugin tags). Stored raw, editable in GLPI.
     */
    public static function getDefaultHtmlContent(): string
    {
        return <<<'HTML'
<div style="background-color:#f4f6fb;padding:24px 0;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
    <tr><td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e3e8ef;">
        <tr>
          <td style="background-color:#2c6ecb;padding:20px 28px;color:#ffffff;font-size:20px;font-weight:600;">
            Ticket transféré
          </td>
        </tr>
        <tr>
          <td style="padding:28px;color:#2d3748;font-size:15px;line-height:1.6;">
            <p style="margin:0 0 16px;">Bonjour,</p>
            <p style="margin:0 0 20px;">Le ticket suivant vous a été transféré par <strong>##transfer.author##</strong>.</p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background-color:#f7f9fc;border:1px solid #e3e8ef;border-radius:8px;margin-bottom:20px;">
              <tr>
                <td style="padding:12px 18px;border-bottom:1px solid #e3e8ef;font-size:13px;color:#64748b;width:140px;">N° du ticket</td>
                <td style="padding:12px 18px;border-bottom:1px solid #e3e8ef;font-weight:600;">###ticket.id##</td>
              </tr>
              <tr>
                <td style="padding:12px 18px;font-size:13px;color:#64748b;">Titre</td>
                <td style="padding:12px 18px;font-weight:600;">##ticket.title##</td>
              </tr>
            </table>
            <div style="border-left:4px solid #2c6ecb;background-color:#f0f6ff;padding:12px 16px;border-radius:0 6px 6px 0;margin-bottom:24px;">
              <div style="font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#2c6ecb;margin-bottom:4px;">Commentaire</div>
              <div style="color:#2d3748;">##transfer.comment##</div>
            </div>
            <table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
              <tr><td style="border-radius:6px;background-color:#2c6ecb;">
                <a href="##ticket.url##" style="display:inline-block;padding:12px 26px;color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;">Ouvrir le ticket</a>
              </td></tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="background-color:#f7f9fc;padding:18px 28px;border-top:1px solid #e3e8ef;color:#94a3b8;font-size:12px;">
            E-mail envoyé automatiquement par GLPI lors du transfert d'un ticket.
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</div>
HTML;
    }

    /**
     * Remove plugin database schema.
     *
     * @return bool
     */
    public static function uninstall(): bool
    {
        foreach (glob(__DIR__ . '/../inc/*.class.php') as $filepath) {
            if (preg_match("/inc.(.+)\.class.php/", $filepath) !== 0) {
                include_once $filepath;
            }
        }

        global $DB;

        // Remove the plugin notification template (and its translations).
        $marker = 'Created by the plugin tickettransfer';
        foreach ($DB->request([
            'SELECT' => 'id',
            'FROM'   => 'glpi_notificationtemplates',
            'WHERE'  => ['comment' => $marker],
        ]) as $row) {
            $templateId = (int) $row['id'];
            $DB->delete('glpi_notificationtemplatetranslations', ['notificationtemplates_id' => $templateId]);
            $DB->delete('glpi_notificationtemplates', ['id' => $templateId]);
        }

        $migration = new Migration(PLUGIN_TICKETTRANSFER_VERSION);
        $migration->dropTable('glpi_plugin_tickettransfer_preferences');
        $migration->dropTable('glpi_plugin_tickettransfer_configs');
        $migration->executeMigration();

        if (class_exists(PluginTickettransferProfile::class)) {
            PluginTickettransferProfile::removeRights();
        }

        return true;
    }
}
