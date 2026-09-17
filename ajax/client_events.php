<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AJAX endpoint: receives and queues client-side xAPI events.
 *
 * @package   logstore_xapi
 * @copyright Jerret Fowler <jerrett.fowler@gmail.com>
 *            Ryan Smith <https://www.linkedin.com/in/ryan-smith-uk/>
 *            David Pesce <david.pesce@exputo.com>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(dirname(dirname(dirname(dirname(dirname(dirname(dirname(__FILE__))))))) . '/config.php');
require_once($CFG->dirroot . '/admin/tool/log/store/xapi/lib.php');
require_once($CFG->dirroot . '/admin/tool/log/store/xapi/src/client.php');

global $DB, $CFG;

require_login();
require_sesskey();

header('Content-Type: application/json');

$statementjson = required_param('statement', PARAM_RAW);
$statement = json_decode($statementjson, true);

if (json_last_error() !== JSON_ERROR_NONE || !logstore_xapi_validate_client_statement($statement, $error)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'action' => 'rejected',
        'queued' => false,
        'delivered' => false,
        'duplicate' => false,
        'message' => $error ?: 'Invalid xAPI statement',
    ]);
    die;
}

if (!\logstore_xapi\client\is_client_verb_enabled($statement['verb']['id'] ?? '')) {
    echo json_encode([
        'success' => true,
        'action' => 'filtered',
        'queued' => false,
        'delivered' => false,
        'duplicate' => false,
        'message' => 'Client-side xAPI statement filtered by verb settings',
    ]);
    die;
}

// The actor is derived from the authenticated user and any client supplied
// actor is discarded so statements cannot be made to impersonate other users.
$statement['actor'] = \logstore_xapi\client\get_actor_for_user($USER, [
    'send_mbox' => (bool)get_config('logstore_xapi', 'mbox'),
    'send_name' => (bool)get_config('logstore_xapi', 'send_name'),
    'send_username' => (bool)get_config('logstore_xapi', 'send_username'),
    'account_homepage' => (string)get_config('logstore_xapi', 'account_homepage'),
    'app_url' => $CFG->wwwroot,
]);
$statementjson = json_encode($statement, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$context = context_user::instance($USER->id);
$result = logstore_xapi_queue_client_statement(
    $statement,
    $statementjson,
    $USER->id,
    $context->id,
    null,
    getremoteaddr()
);

$isbackground = (bool)get_config('logstore_xapi', 'backgroundmode');

$action = $isbackground ? 'queued' : 'send';
$delivery = null;

if (!$result['duplicate'] && !$isbackground) {
    $record = $DB->get_record('logstore_xapi_client_log', ['id' => $result['id']]);
    if ($record === false) {
        // The scheduled task already delivered the statement concurrently.
        $delivery = [
            'processed' => 1,
            'sent' => 1,
            'failed' => 0,
            'skipped' => false,
            'results' => [['id' => $result['id'], 'success' => true, 'errortype' => 0, 'response' => '']],
        ];
    } else {
        $delivery = \logstore_xapi\client\process_records([$record]);
    }
}

if ($delivery !== null) {
    if (!empty($delivery['skipped'])) {
        $action = 'queued';
    } else if ($delivery['sent'] === 1) {
        $action = 'sent';
    } else {
        $action = 'failed';
    }
}

$success = $result['duplicate'] || $isbackground ||
    ($delivery !== null && (empty($delivery['skipped']) && $delivery['failed'] === 0));
$response = [
    'success' => $success,
    'action' => $result['duplicate'] ? 'duplicate' : $action,
    'queued' => !$result['duplicate'] && ($isbackground || $action === 'queued' || $action === 'failed'),
    'delivered' => !$result['duplicate'] && $action === 'sent',
    'duplicate' => $result['duplicate'],
    'message' => $result['duplicate'] ? 'Client-side xAPI statement already processed or queued' :
        ($action === 'queued' ? 'Client-side xAPI statement queued for processing' :
        ($action === 'sent' ? 'Client-side xAPI statement sent to the LRS' :
        'Client-side xAPI statement failed to send and was queued for retry')),
];

if ($delivery !== null && $action === 'failed' && !empty($delivery['results'][0])) {
    $response['error'] = $delivery['results'][0]['response'];
    $response['errortype'] = $delivery['results'][0]['errortype'];
}

echo json_encode($response);
