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

namespace core;

use core\oauth2\issuer;


/**
 * Tests for issuer.
 *
 * @package    core
 * @copyright  2024 Gurvan Giboire <gurvan.giboire@umontreal.ca>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class issuer_test extends \advanced_testcase {

    /**
     * Data Provider for test_get_display_name.
     *
     * @return array
     */
    public static function get_display_name_provider(): array {
        return [
            'Only name'  => [
                'issuer name',
                'issuer name',
            ],
            'Only one value as loginpagename'  => [
                'Connection',
                'issuer name',
                'Connection',
            ],
            'Only one value as loginpagename with pipe'  => [
                'Sign in | Sign up',
                'issuer name',
                'Sign in | Sign up',
            ],
            'Two cases as loginpagename french' => [
                'Connexion',
                'issuer name',
                "Connection\nConnexion|fr",
                "fr",
            ],
            'Two cases as loginpagename french with pipe' => [
                "Connexion|S'inscrire",
                "issuer name",
                "Connection\nConnexion|S'inscrire|fr",
                "fr",
            ],
            'Two cases as loginpagename spanish without value' => [
                'Connection',
                'issuer name',
                "Connection\nConnexion|fr",
                "es",
            ],
            'Two cases as loginpagename canadian french without value' => [
                'Connexion',
                'issuer name',
                "Connection\nConnexion|fr,fr_ca",
                "fr_ca",
            ],
            'Three cases with bad format' => [
                'Connection',
                'issuer name',
                "Connection\nConnexion|Connexion\nConnexion|Connexion\n",
                "es",
            ],
        ];
    }

    /**
     * Test get_display_name method.
     * @dataProvider get_display_name_provider
     * @param string $expected The expected result
     * @param string $name identity issuer name
     * @param ?string $loginpagename identity issuer loginpagename
     * @param string $lang language uses
     * @covers ::get_display_name
     * @return void
     */
    public function test_get_display_name(
        string $expected,
        string $name,
        ?string $loginpagename = null,
        string $lang = 'en'
    ): void {
        global $SESSION;
        $SESSION->forcelang = $lang;
        $issuer = new issuer;
        $issuer->set('name', $name);
        $issuer->set('loginpagename', $loginpagename);
        $this->assertEquals($expected, $issuer->get_display_name());
    }
}
