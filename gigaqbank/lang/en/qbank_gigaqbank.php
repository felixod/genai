<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin strings are defined here.
 *
 * @package     qbank_gigaqbank
 * @category    string
 * @copyright   2025 Gorbatov Sergey <s.gorbatov@tu-ugmk.com>
 * @copyright   2023 Christian Grévisse <christian.grevisse@uni.lu>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['assistantid'] = 'Assistant ID';
$string['assistantid_help'] = 'ID concerning the GigaChat API assistant. This will be set by the plugin.';

$string['autotag'] = 'AutoTag';
$string['autotagintro'] = 'The following questions will be auto-tagged:';
$string['autotagparsingerror'] = 'Error while parsing the generated tags.';
$string['autotagsuccess'] = '{$a} questions have been tagged successfully.';

$string['errormsg_noneselected'] = 'Please select at least one resource.';

$string['nogigachatapikey'] = 'You need to set a GigaChat API key.';
$string['noquestionselected'] = 'No question selected.';
$string['noresources'] = 'There are no resources in your course.';

$string['ongoingtasks'] = 'The following generation tasks are ongoing:';

$string['gigachatapikey'] = 'GigaChat API key';
$string['gigachatapikey_help'] = 'To be created at <a href="https://developers.sber.ru/" target="_blank">https://developers.sber.ru/</a>.';
$string['gigachatapisettings'] = 'GigaChat API Settings';

$string['pluginname'] = 'GigaChat Question Bank';

$string['privacy:metadata:qbank_gigaqbank_gigachat_settings'] = 'The user\'s ID';
$string['privacy:metadata:qbank_gigaqbank_gigachat_settings:userid'] = 'Table that stores data related to the GigaChat API';

$string['return'] = 'Return to question bank';

$string['settings'] = 'GigaChat Question Bank Settings';
$string['title'] = 'Generate questions';
