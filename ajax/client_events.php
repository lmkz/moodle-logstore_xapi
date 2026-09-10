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

require_login();
require_sesskey();

header('Content-Type: application/json');

$statementjson = required_param('statement', PARAM_RAW);
$statement = json_decode($statementjson, true);

if (json_last_error() !== JSON_ERROR_NONE || !logstore_xapi_validate_client_statement($statement, $error)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
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
error_log('logstore_xapi client event queued: userid=' . $USER->id .
    ', verb=' . $verbid . ', object=' . $objectid . ', duplicate=' . (int)$result['duplicate']);

echo json_encode([
    'success' => true,
    'queued' => $result['queued'],
    'duplicate' => $result['duplicate'],
    'message' => $result['duplicate'] ? 'Client-side xAPI statement already queued' :
        'Client-side xAPI statement queued for processing',
]);
