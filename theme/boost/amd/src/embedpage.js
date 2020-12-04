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
 * Manage displaying embed page.
 *
 * @package    theme_boost
 * @copyright  2019 Université de Montréal
 * @author     Issam Taboubi <issam.taboubi@umontreal.ca>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery'], function() {

    /**
     * Constructor for embed page.
     *
     */
    var Embedpage = function() {
        // This template should be used when the page has to be embedded in an iframe. If it is not the case, unembed the page.
        if ( window.location === window.parent.location ) {
            var url = window.location.href;

            if(url.indexOf('embed=1') > -1) {
                // There is already an embed=1, change it to embed=0.
                url = url.replace('embed=1', 'embed=0');
            } else if (url.indexOf('?') > -1){
                // There are other parameters, so add it before any other parameter.
                url = url.replace('?', '?embed=0&');
            } else if(url.indexOf('#') > -1) {
                // No parameter : add param before anchor and keep anchor.
                url = url.replace('#', '?embed=0#');
            } else {
                // No parameter, no anchor : add at the end of the URL.
                url = url += '?embed=0';
            }

            window.location.href = url;
        }
    };

    return {
        'init': function() {
            return new Embedpage();
        }
    };

});
