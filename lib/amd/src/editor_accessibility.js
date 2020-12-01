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
 * Add text for editor accessibility.
 *
 * @module     core/editor_accessibility
 * @class      editor_accessibility
 * @package    core
 * @copyright  2020 Université de Montréal
 * @author     Issam Taboubi <issam.taboubi@umontreal.ca>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/templates', 'core/notification'], function($, Templates, Notification) {

    /**
     * Add accessibility text to editor.
     *
     * @method addAccessibilityText
     * @param {string} Textarea id.
     */
    var addAccessibilityText = function(textareaid) {
        Templates.render('core_form/editor_accessibility', {})
            .then(function(html) {
                $("textarea[id='" + textareaid +"']").parent('div').prepend(html);
                return;
            }).fail(Notification.exception);
    };

    return /** @alias module:core/editor_accessibility */{
        /**
         * Add accessibility to text editor
         *
         * @method init
         * @param {string} Textarea id.
         */
        init: function(textareaid) {
            addAccessibilityText(textareaid);
        }
    };
});
