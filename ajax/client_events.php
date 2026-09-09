<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * AJAX endpoint: receives client-side xAPI events.
 *
 * @package   logstore_xapi
 * @copyright Jerret Fowler <jerrett.fowler@gmail.com>
 *            Ryan Smith <https://www.linkedin.com/in/ryan-smith-uk/>
 *            David Pesce <david.pesce@exputo.com>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(dirname(dirname(dirname(dirname(dirname(dirname(dirname(__FILE__))))))) . '/config.php');

require_login();
require_sesskey();

$statementjson = required_param('statement', PARAM_RAW);
$statement = json_decode($statementjson, true);

if (!is_array($statement) ||
        !array_key_exists('actor', $statement) ||
        !array_key_exists('verb', $statement) ||
        !array_key_exists('object', $statement)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid xAPI statement',
    ]);
    die;
}

$verbid = '';
if (!empty($statement['verb']) && is_array($statement['verb']) && !empty($statement['verb']['id'])) {
    $verbid = (string)$statement['verb']['id'];
}

$objectid = '';
if (!empty($statement['object']) && is_array($statement['object']) && !empty($statement['object']['id'])) {
    $objectid = (string)$statement['object']['id'];
}

error_log('logstore_xapi client event arrived: userid=' . $USER->id .
    ', verb=' . $verbid . ', object=' . $objectid);

echo json_encode([
    'success' => true,
    'message' => 'Client-side xAPI statement received',
]);
