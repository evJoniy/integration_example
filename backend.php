<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_time_limit(0);

include __DIR__ . '/vendor/autoload.php';

use Mautic\Auth\ApiAuth;
use Mautic\MauticApi;

$mtaSettings = require_once 'config/EndSideConfig.php';
$sqlViewSource = require_once 'config/ViewConfig.php';

$contactApi = newApi($mtaSettings);

if (isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'abtesting':
            setABTestingFieldForExistRowsIfValueNull($contactApi);
            break;
        case 'create':
            echo createLeads($mtaSettings, $sqlViewSource);
            break;
        case 'count':
            echo getLeadsCount($contactApi);
    }
}

/**
 * @param $settings
 * @return \Mautic\Api\Api|void
 */
function newApi($settings)
{
    $auth = (new ApiAuth())->newAuth($settings, $settings['authMethod']);

    try {
        return (new MauticApi())->newApi('contacts', $auth, $settings['url']);
    } catch (\Mautic\Exception\ContextNotFoundException $e) {
        echo $e;
        die();
    }
}

function setABTestingFieldForExistRowsIfValueNull($contactApi)
{
    $rowsToDo = true;
    do {
        $list = array_values($contactApi->getList('', 0, 0, 'abtesting')['contacts']);
        $update = [];

        if ($list[1]['fields']['core']['abtesting']['value'] !== null) {
            $rowsToDo = false;
        } else {
            foreach ($list as $item) {
                if ($item['fields']['core']['abtesting']['value'] == null && $item['id'] != 1) {
                    $update[] = [
                        'id' => $item['id'],
                        'abtesting' => rand(0, 1),
                    ];
                }
            }
            $contactApi->editBatch($update);
        }
    } while ($rowsToDo);
}

/**
 * @param $settings
 * @param $db
 * @return string|null
 */
function createLeads($settings, $db): ?string
{
    // ---------------------Data from PDO----------------------
//    $pdo = newPDO($db);
//    $leads = $pdo->prepare("select * from {$db['leads']}");
//    $leads->execute();
//    $leads = $leads->fetchAll();
//
//    $crm = $pdo->prepare("select * from {$db['crm']}");
//    $crm->execute();
//    $crms = $crm->fetchAll();
    // -------------------End Data from PDO--------------------

    // ---------------------Data from CSV----------------------
    $crmArr = array_map('str_getcsv', file('crm.csv'));
    $leadsArr = array_map('str_getcsv', file('leads.csv'));

    $crms = csvToAssocArr($crmArr);
    $leads = csvToAssocArr($leadsArr);
    // -------------------End Data from CSV--------------------

    if (!$leads || !$crms) {
        return 'Parsing Lead or CRM data went wrong';
    }

    $crmMap = createMap($crms, 'crm');
    $leadMap = createMap($leads, 'lead');

    $crmMap = getNewestRow($crmMap, 'crm');
    $leadMap = getNewestRow($leadMap, 'lead');

    $crmMap = createEmailFromPhoneIfEmpty($crmMap, 'crm');
    $leadMap = createEmailFromPhoneIfEmpty($leadMap, 'lead');

    $contactApiLimit = 30;
    $data = array_chunk(remap($crmMap, $leadMap), $contactApiLimit);

    for ($i = 0; $i < count($data) - 1; $i++) {
        newApi($settings)->createBatch($data[$i]);
    }

    return null;
}

/**
 * Create map from array by viewName
 *
 * @param array $arr
 * @param string $viewName
 * @return array
 */
function createMap(array $arr, string $viewName): array
{
    $phoneFieldName = $viewName == 'crm' ? 'phone_number' : 'mobile';

    $map = [];
    foreach ($arr as $item) {
        $item[$phoneFieldName] = str_replace('-', '', $item[$phoneFieldName]);
        $phone = $item[$phoneFieldName];

        if (!array_key_exists($phone, $map)) {
            $map[$phone][0] = $item;
        } else {
            $map[$phone][] = $item;
        }
    }

    return $map;
}

/**
 * @param array $arr
 * @param string $viewName
 * @return array
 */
function getNewestRow(array $arr, string $viewName): array
{
    $c = $viewName == 'crm' ? 'creation_date' : 'CreationTime';

    foreach ($arr as $email => $data) {
        $creationDate = 0;
        $searchedItem = null;

        foreach ($data as $item) {
            if ($item[$c] > $creationDate) {
                $creationDate = $item[$c];
                $searchedItem = $item;
            }
        }

        $arr[$email] = $searchedItem;
    }

    return $arr;
}

/**
 * @param array $array
 * @param string $viewName
 * @return array
 */
function createEmailFromPhoneIfEmpty(array $array, string $viewName): array
{
    $emailField = $viewName == 'crm' ? 'home_email' : 'main_mail';
    $phoneField = $viewName == 'crm' ? 'phone_number' : 'mobile';

    foreach ($array as $item) {
        if (!$item[$emailField] || empty($item[$emailField])) {
            $item[$emailField] = $item[$phoneField] . 'mail@mail.com';
        }
    }

    return $array;
}

/**
 * @param $crmMap
 * @param $leadMap
 * @return array
 */
function remap($crmMap, $leadMap): array
{
    $mergedMap = [];
    foreach ($crmMap as $phone => $crm) {
        if (!empty($phone) && array_key_exists($phone, $leadMap)) {
            $mergedMap[$phone]['crm'] = $crm;
            $mergedMap[$phone]['lead'] = $leadMap[$phone];
            unset($leadMap[$phone]);
            unset($crmMap[$phone]);
        }
    }

    $data = [];
    foreach ($mergedMap as $item) {
        $data[] = remapLeadsToCrm($item['lead'], $item['crm']);
    }

    foreach ($leadMap as $lead) {
        $data[] = remapLead($lead);
    }

    foreach ($crmMap as $crm) {
        $data[] = remapCrm($crm);
    }

    return $data;
}

/**
 * Map leads to crm
 *
 * @param null $lead
 * @param null $crm
 * @return array
 */
function remapLeadsToCrm($lead = null, $crm = null): array
{
    $mLead[] = [
        'firstname' => $lead['FirstName'] ?? $crm['Firstname'],
        'lastname' => $lead['LastName'] ?? $crm['Surname'],

        'mobile' => $crm['phone_number'] ?? $lead['mobile'],
        'email' => $crm['home_email'] ?? $lead['main_mail'],
        'study' => $crm['group_description'] ?? '',
        'mail_approved' => $crm['mail_approved'] ?? '',

        'createdAt' => $crm['creation_date'] ?? '',
    ];

    crmDescriptionMapper($crm, $mLead);

    return array_merge(...$mLead);
}

/**
 * @param array $crm
 * @param array $mLead
 */
function crmDescriptionMapper(array $crm, array &$mLead)
{
    if ($crm['Event_Description'] === 'text'
        || $crm['Event_Description'] === 'text1') {
        $mLead[] = [
            'leadsource' => $crm['Event_Description'],
        ];
    }

    if ($crm['Result_Description'] === 'text') {
        $mLead[] = [
            'status' => 'status',
            'month' => 'month',
            'date' => 'September 4, 2022 11:00 AM',
            'result' => 'October 15, 2022 11:00 AM'
        ];
    }
    //-----------------------end psychometricstatus-----------------------------

    //----------------------------not sure--------------------------------------
    $notSureStatuses = [
        '1',
        '2',
        '3',
        '4',
    ];

    if (in_array($crm['Result_Description'], $notSureStatuses)) {
        $mLead[] = [
            'notsurestatus' => $crm['Result_Description'],
        ];
    }
    //----------------------------end not sure----------------------------------

    //----------------------------Clarificationcall-----------------------------
    $Clarificationcalls = [
        'Lorem Ipsum is simply dummy text of',
        'the printing and typesetting industry',
        'Lorem Ipsum has been the industry',
        'standard dummy text ever since',
        'the 1500s, when an unknown',
        'printer took a galley of type',
    ];

    if (in_array($crm['Result_Description'], $Clarificationcalls)) {
        $mLead[] = [
            'Clarificationcall' => $crm['Result_Description'],
        ];
    }
    //----------------------------end Clarificationcall-------------------------
}

/**
 * @param array $lead
 * @return array
 */
function remapLead(array $lead): array
{
    return [
        'firstname' => $lead['FirstName'],
        'lastname' => $lead['LastName'],

        'mobile' => $lead['mobile'],
        'email' => $lead['email'],
    ];
}

/**
 * @param array $crm
 * @return array|string[]
 */
function remapCrm(array $crm): array
{
    return [
        'firstname' => $crm['Firstname'] ?? '',
        'lastname' => $crm['Surname'] ?? '',

        'mobile' => $crm['phone_number'] ?? '',
        'email' => $crm['home_email'] ?? '',
        'study' => $crm['group_description'] ?? '',
        'mail_approved' => $crm['mail_approved'] ?? '',

        'createdAt' => $crm['creation_date'] ?? '',
    ];
}

/**
 * @param $contactApi
 * @return int
 */
function getLeadsCount($contactApi): int
{
    return $contactApi->getList()['total'];
}

/**
 * @param array $db
 * @return PDO
 */
function newPDO(array $db): PDO
{
    $dsn = $db['db'] . ':host=' . $db['host'] . ';dbname=' . $db['dbName'];

    return new PDO($dsn, $db['dbUser'], $db['dbPass']);
}

/**
 * Create associative array using first element (legend) as keys
 *
 * @param $parsedCsv
 * @return array
 */
function csvToAssocArr($parsedCsv): array
{
    $assoc = [];
    for ($i = 1; $i < count($parsedCsv) - 1; $i++) {
        $assoc[] = array_combine($parsedCsv[0], $parsedCsv[$i]);
    }

    return $assoc;
}
