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
 * This script listens for xAPI events from H5P content and sends them to a
 * server-side handler in logstore_xapi.
 *
 * @package   logstore_xapi
 * @copyright Jerret Fowler <jerrett.fowler@gmail.com>
 *            Ryan Smith <https://www.linkedin.com/in/ryan-smith-uk/>
 *            David Pesce <david.pesce@exputo.com>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function() {
    if (typeof window === 'undefined') {
        return;
    }

    window.addEventListener('load', function() {
        require(['jquery'], function($) {
            if (typeof H5P !== 'undefined' && typeof H5P.externalDispatcher !== 'undefined') {
                registerDispatcher(H5P.externalDispatcher, $);
            } else {
                registerIframeDispatchers($);
            }
        });
    });
})();

/**
 * Register an xAPI event listener on a dispatcher.
 *
 * @param {Object} dispatcher H5P external dispatcher.
 * @param {Object} $ jQuery object.
 */
function registerDispatcher(dispatcher, $) {
    if (!dispatcher || typeof dispatcher.on !== 'function') {
        return;
    }

    dispatcher.on('xAPI', function(event) {
        if (!event || !event.data || !event.data.statement) {
            return;
        }

        var statement = validateStatement(event.data.statement);
        send($, statement);
    });
}

/**
 * Attach listeners to H5P dispatchers found in iframes.
 *
 * @param {Object} $ jQuery object.
 */
function registerIframeDispatchers($) {
    var iframes = document.getElementsByTagName('iframe');
    for (var i = 0; i < iframes.length; i++) {
        if (iframes[i].src.indexOf('h5p') === -1) {
            continue;
        }

        try {
            if (iframes[i].contentWindow &&
                    iframes[i].contentWindow.H5P &&
                    iframes[i].contentWindow.H5P.externalDispatcher) {
                registerDispatcher(iframes[i].contentWindow.H5P.externalDispatcher, $);
            }
        } catch (ex) {
            console.debug('logstore_xapi: unable to access iframe dispatcher', ex);
        }
    }
}

/**
 * Send an xAPI statement to the server-side handler.
 *
 * @param {Object} $ jQuery object.
 * @param {Object} statement xAPI statement.
 */
function send($, statement) {
    $.ajax({
        url: M.cfg.wwwroot + '/admin/tool/log/store/xapi/ajax/client_events.php',
        type: 'POST',
        dataType: 'json',
        data: {
            sesskey: M.cfg.sesskey,
            statement: JSON.stringify(statement)
        },
        success: function(response) {
            console.debug('logstore_xapi: client event received by backend', response);
        },
        error: function(xhr, status, error) {
            console.error('logstore_xapi: failed to send xAPI statement', status, error);
        }
    });
}

/**
 * Validate and enrich an xAPI statement.
 *
 * @param {Object} statement xAPI statement.
 * @returns {Object} validated statement.
 */
function validateStatement(statement) {
    statement = addCourseId(statement);
    statement = validateActivityId(statement);
    statement = validateChoiceIds(statement);
    statement = addTimestamp(statement);
    return statement;
}

/**
 * Add course grouping context to the statement where available.
 *
 * @param {Object} statement xAPI statement.
 * @returns {Object} modified statement.
 */
function addCourseId(statement) {
    if (!statement.context) {
        statement.context = {};
    }

    var courseId = M.cfg.courseId;
    if (courseId) {
        statement.context.contextActivities = statement.context.contextActivities || {};
        statement.context.contextActivities.grouping = [{
            id: M.cfg.wwwroot + '/course/view.php?id=' + courseId
        }];
    }

    return statement;
}

/**
 * Ensure object.id is current page URL.
 *
 * @param {Object} statement xAPI statement.
 * @returns {Object} modified statement.
 */
function validateActivityId(statement) {
    if (!statement.object) {
        statement.object = {};
    }

    if (statement.object.id !== window.location.href) {
        statement.object.id = window.location.href;
    }

    return statement;
}

/**
 * Ensure choice ids are strings.
 *
 * @param {Object} statement xAPI statement.
 * @returns {Object} modified statement.
 */
function validateChoiceIds(statement) {
    if (statement.object && statement.object.definition && statement.object.definition.choices) {
        statement.object.definition.choices.forEach(function(choice) {
            if (typeof choice.id !== 'string') {
                choice.id = choice.id.toString();
            }
        });
    }

    return statement;
}

/**
 * Add an ISO timestamp to the statement.
 *
 * @param {Object} statement xAPI statement.
 * @returns {Object} modified statement.
 */
function addTimestamp(statement) {
    statement.timestamp = new Date().toISOString();
    return statement;
}
