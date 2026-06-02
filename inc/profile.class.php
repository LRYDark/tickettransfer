<?php

class PluginTickettransferProfile extends Profile
{
    public const RIGHTNAME = 'plugin_tickettransfer';
    public static $rightname = self::RIGHTNAME;

    public const RIGHT_TRANSFER    = 1;
    public const RIGHT_PREFERENCES = 2;

    public static function getTypeName($nb = 0)
    {
        return _n('Transfert de ticket', 'Transferts de ticket', $nb, 'tickettransfer');
    }

    public static function getIcon()
    {
        return 'ti ti-user-share';
    }

    public static function install(Migration $migration): void
    {
        $migration->addRight(
            self::$rightname,
            self::RIGHT_TRANSFER | self::RIGHT_PREFERENCES
        );
    }

    public static function removeRights(): void
    {
        global $DB;
        if ($DB->tableExists('glpi_profilerights')) {
            $DB->delete(
                'glpi_profilerights',
                ['name' => self::$rightname]
            );
        }
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile) {
            return self::createTabEntry(self::getTypeName(2));
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Profile) {
            $profile = new self();
            /** @phpstan-ignore-next-line */
            $profile->showForm($item->getID());
        }
        return true;
    }

    public function showForm($ID, $options = [])
    {
        $canedit = Session::haveRight('profile', UPDATE);
        ProfileRight::cleanAllPossibleRights();
        self::addDefaultProfileInfos($ID, [
            self::RIGHTNAME => 0,
        ]);

        echo "<div class='spaced'>";
        $profile = new Profile();
        $profile->getFromDB($ID);
        echo "<form method='post' action='" . $profile->getFormURL() . "'>";

        $rights = [
            [
                'itemtype' => self::class,
                'label'    => __('Transfert de ticket', 'tickettransfer'),
                'field'    => self::$rightname,
                'rights'   => [
                    self::RIGHT_TRANSFER    => __('Transferer un ticket', 'tickettransfer'),
                    self::RIGHT_PREFERENCES => __('Gerer la configuration depuis les preferences', 'tickettransfer'),
                ],
            ],
        ];
        $matrix_options = [
            'title'   => __('Transfert de ticket', 'tickettransfer'),
            'canedit' => $canedit,
        ];
        $profile->displayRightsChoiceMatrix($rights, $matrix_options);

        echo "<div class='center'>";
        echo Html::hidden('id', ['value' => $ID]);
        echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
        echo "</div>\n";
        Html::closeForm();
        echo "</div>";

        return true;
    }

    public static function getAllRights($all = false): array
    {
        return [
            [
                'itemtype' => self::class,
                'label'    => self::getTypeName(2),
                'field'    => self::RIGHTNAME,
                'rights'   => [
                    self::RIGHT_TRANSFER    => __('Transferer un ticket', 'tickettransfer'),
                    self::RIGHT_PREFERENCES => __('Gerer la configuration depuis les preferences', 'tickettransfer'),
                ],
            ],
        ];
    }

    public static function addDefaultProfileInfos($profiles_id, array $rights, bool $drop_existing = false): void
    {
        $profileRight = new ProfileRight();
        $dbu = new DbUtils();

        foreach ($rights as $right => $value) {
            if ($drop_existing && $dbu->countElementsInTable('glpi_profilerights', [
                'profiles_id' => $profiles_id,
                'name'        => $right,
            ])) {
                $profileRight->deleteByCriteria(['profiles_id' => $profiles_id, 'name' => $right]);
            }

            if (!$dbu->countElementsInTable('glpi_profilerights', [
                'profiles_id' => $profiles_id,
                'name'        => $right,
            ])) {
                $profileRight->add([
                    'profiles_id' => $profiles_id,
                    'name'        => $right,
                    'rights'      => $value,
                ]);
            }
        }
    }

    public static function createFirstAccess($profiles_id): void
    {
        self::addDefaultProfileInfos($profiles_id, [
            self::RIGHTNAME => self::RIGHT_TRANSFER | self::RIGHT_PREFERENCES,
        ], true);
    }

    public static function initProfile(): void
    {
        global $DB;

        $profile = new self();
        $dbu = new DbUtils();
        foreach ($profile->getAllRights(true) as $data) {
            if ($dbu->countElementsInTable('glpi_profilerights', ['name' => $data['field']]) == 0) {
                ProfileRight::addProfileRights([$data['field']]);
            }
        }

        if (isset($_SESSION['glpiactiveprofile']['id'])) {
            self::addDefaultProfileInfos($_SESSION['glpiactiveprofile']['id'], [
                self::RIGHTNAME => 0,
            ]);
            foreach ($DB->request([
                'FROM'  => 'glpi_profilerights',
                'WHERE' => [
                    'profiles_id' => $_SESSION['glpiactiveprofile']['id'],
                    'name'        => ['LIKE', '%plugin_tickettransfer%'],
                ],
            ]) as $prof) {
                $_SESSION['glpiactiveprofile'][$prof['name']] = $prof['rights'];
            }
        }
    }

    public static function removeRightsFromSession(): void
    {
        foreach (self::getAllRights(true) as $right) {
            if (isset($_SESSION['glpiactiveprofile'][$right['field']])) {
                unset($_SESSION['glpiactiveprofile'][$right['field']]);
            }
        }
    }
}
