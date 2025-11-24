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
 * @package     qbank_gigaai
 * @category    string
 * @copyright   2023 Christian Grévisse <christian.grevisse@uni.lu>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['model'] = 'Модель';
$string['model_help'] = 'Название модели для использования (например, GigaChat:Pro, GigaChat:Max и т.д.).';

$string['autotag'] = 'Автотеги';
$string['autotagintro'] = 'Следующие вопросы будут помечены тегами автоматически:';
$string['autotagparsingerror'] = 'Ошибка при разборе сгенерированных тегов.';
$string['autotagsuccess'] = '{$a} вопросов были успешно помечены тегами.';

$string['errormsg_noneselected'] = 'Пожалуйста, выберите хотя бы один ресурс.';

$string['noapikey'] = 'Вам нужно установить ключ API.';
$string['noquestionselected'] = 'Вопросы не выбраны.';
$string['noresources'] = 'В вашем курсе нет ресурсов.';

$string['ongoingtasks'] = 'Следующие задачи генерации выполняются:';

$string['apikey'] = 'Ключ API';
$string['apikey_help'] = 'Ваш ключ API для сервиса ИИ.';
$string['apisettings'] = 'Настройки API ИИ';

$string['pluginname'] = 'Банк вопросов GigaAI';

$string['privacy:metadata:qbank_gigaai_ai_settings'] = 'ID пользователя';
$string['privacy:metadata:qbank_gigaai_ai_settings:userid'] = 'Таблица, хранящая данные, связанные с API ИИ';

$string['return'] = 'Вернуться в банк вопросов';

$string['settings'] = 'Настройки банка вопросов GigaAI';
$string['title'] = 'Сгенерировать вопросы';