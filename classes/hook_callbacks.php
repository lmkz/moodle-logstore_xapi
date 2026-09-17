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
 * Hook callbacks for logstore_xapi.
 *
 * @package   logstore_xapi
 * @copyright Jerret Fowler <jerrett.fowler@gmail.com>
 *            Ryan Smith <https://www.linkedin.com/in/ryan-smith-uk/>
 *            David Pesce <david.pesce@exputo.com>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace logstore_xapi;

use core\hook\output\before_http_headers;

/**
 * Hook callback handlers.
 */
class hook_callbacks {
    /**
     * Conditionally include JavaScript on every page.
     *
     * @param before_http_headers $hook Hook instance.
     * @return void
     */
    public static function before_http_headers(before_http_headers $hook): void {
        global $PAGE;

        if (!get_config('logstore_xapi', 'captureclientsideevents')) {
            return;
        }

        require_once(dirname(__DIR__) . '/src/client.php');

        $PAGE->requires->js('/admin/tool/log/store/xapi/sender.js');
        $filterconfig = [
            'enabledVerbs' => \logstore_xapi\client\get_enabled_client_verb_ids(),
            'knownVerbs' => array_values(\logstore_xapi\client\get_client_verb_map()),
            'allowUnknown' => \logstore_xapi\client\get_clientverbs_allow_unknown(),
        ];
        $PAGE->requires->js_init_code(
            'window.logstoreXapiClientVerbs = ' . json_encode($filterconfig) . ';'
        );
    }
}
