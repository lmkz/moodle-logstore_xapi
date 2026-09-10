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

global $DB;

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

$context = context_user::instance($USER->id);
$result = logstore_xapi_queue_client_statement(
    $statement,
    $statementjson,
    $USER->id,
    $context->id,
    null,
    getremoteaddr()
);

$verbid = !empty($statement['verb']['id']) ? (string)$statement['verb']['id'] : '';
$objectid = !empty($statement['object']['id']) ? (string)$statement['object']['id'] : '';
$isbackground = (bool)get_config('logstore_xapi', 'backgroundmode');
error_log('logstore_xapi client event ' . ($isbackground ? 'queued' : 'received for immediate delivery') .
    ': userid=' . $USER->id . ', verb=' . $verbid . ', object=' . $objectid .
    ', duplicate=' . (int)$result['duplicate']);

$action = $isbackground ? 'queued' : 'sent';
$delivery = null;

if (!$result['duplicate'] && !$isbackground) {
    $record = $DB->get_record('logstore_xapi_client_log', ['id' => $result['id']], '*', MUST_EXIST);
    $delivery = \logstore_xapi\client\process([$record]);
    if ($delivery['sent'] === 1) {
        $action = 'sent';
    } else {
        $action = 'failed';
    }
}

$success = $result['duplicate'] || $isbackground || ($delivery !== null && $delivery['failed'] === 0);
$response = [
    'success' => $success,
    'action' => $result['duplicate'] ? 'duplicate' : $action,
    'queued' => !$result['duplicate'] && ($isbackground || ($delivery !== null && $delivery['failed'] > 0)),
    'delivered' => !$result['duplicate'] && $delivery !== null && $delivery['sent'] === 1,
    'duplicate' => $result['duplicate'],
    'message' => $result['duplicate'] ? 'Client-side xAPI statement already processed or queued' :
        ($action === 'queued' ? 'Client-side xAPI statement queued for processing' :
        ($action === 'sent' ? 'Client-side xAPI statement sent to the LRS' :
        'Client-side xAPI statement failed to send and was queued for retry')),
];

if ($delivery !== null && !empty($delivery['results'][0])) {
    $response['error'] = $delivery['results'][0]['response'];
    $response['errortype'] = $delivery['results'][0]['errortype'];
}

echo json_encode($response);
