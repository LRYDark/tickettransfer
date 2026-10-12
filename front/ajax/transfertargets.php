<?php


Session::checkLoginUser();
Session::checkRight(PluginTickettransferProfile::RIGHTNAME, PluginTickettransferProfile::RIGHT_TRANSFER);

header('Content-Type: application/json; charset=UTF-8');
Html::header_nocache();

/** @var DBmysql $DB */
global $DB;

$search = trim((string) ($_GET['searchText'] ?? ($_POST['searchText'] ?? '')));
$limit  = 50;

$userResults  = [];
$groupResults = [];

// --- Users ---
$userWhere = [
    'glpi_users.is_active'  => 1,
    'glpi_users.is_deleted' => 0,
];
if ($search !== '') {
    $like = '%' . $search . '%';
    $userWhere[] = [
        'OR' => [
            ['glpi_users.name'      => ['LIKE', $like]],
            ['glpi_users.realname'  => ['LIKE', $like]],
            ['glpi_users.firstname' => ['LIKE', $like]],
        ],
    ];
}
$users = $DB->request([
    'SELECT'    => [
        'glpi_users.id',
        'glpi_users.name',
        'glpi_users.realname',
        'glpi_users.firstname',
        'glpi_useremails.email AS default_email',
    ],
    'FROM'      => 'glpi_users',
    'LEFT JOIN' => [
        'glpi_useremails' => [
            'ON' => [
                'glpi_useremails' => 'users_id',
                'glpi_users'      => 'id',
                ['AND' => ['glpi_useremails.is_default' => 1]],
            ],
        ],
    ],
    'WHERE'     => $userWhere,
    'ORDER'     => ['glpi_users.realname', 'glpi_users.firstname', 'glpi_users.name'],
    'LIMIT'     => $limit,
]);
foreach ($users as $user) {
    $label = formatUserName($user['id'], $user['name'], $user['realname'], $user['firstname']);
    $email = (string) ($user['default_email'] ?? '');
    $userResults[] = [
        'id'    => 'User_' . (int) $user['id'],
        'text'  => $label,
        'email' => $email,
        'title' => $email !== '' ? sprintf('%s - %s', $label, $email) : $label,
    ];
}

// --- Groups ---
$groupWhere = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    $groupWhere[] = [
        'OR' => [
            ['name'         => ['LIKE', $like]],
            ['completename' => ['LIKE', $like]],
        ],
    ];
}
$groups = $DB->request([
    'SELECT' => ['id', 'completename', 'name'],
    'FROM'   => 'glpi_groups',
    'WHERE'  => $groupWhere,
    'ORDER'  => ['completename'],
    'LIMIT'  => $limit,
]);
foreach ($groups as $group) {
    $label = (string) ($group['completename'] !== '' ? $group['completename'] : $group['name']);
    $groupResults[] = [
        'id'   => 'Group_' . (int) $group['id'],
        'text' => $label,
    ];
}

$results = [];
if (!empty($userResults)) {
    $results[] = [
        'text'     => __('Utilisateurs', 'tickettransfer'),
        'children' => $userResults,
    ];
}
if (!empty($groupResults)) {
    $results[] = [
        'text'     => __('Groupes', 'tickettransfer'),
        'children' => $groupResults,
    ];
}

echo json_encode(['results' => $results]);
