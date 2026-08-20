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
 * Constants
 *
 * @package    mod_paper
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_paper;

defined('MOODLE_INTERNAL') || die();

class constants {
    const M_COMPONENT = 'mod_paper';

    // Page geometry. Worksheets are burst to A4 by Ghostscript (-sPAPERSIZE=a4) and the
    // evaluation PDF is built as A4, so box percentages and millimetres are interchangeable
    // via these two numbers.
    const M_PAGE_W_MM = 210;
    const M_PAGE_H_MM = 297;

    // Languages
    const M_LANG_ENUS = 'en-US';
    const M_LANG_ENGB = 'en-GB';
    const M_LANG_ENAU = 'en-AU';
    const M_LANG_ENPH = 'en-PH';
    const M_LANG_ENNZ = 'en-NZ';
    const M_LANG_ENZA = 'en-ZA';
    const M_LANG_ENIN = 'en-IN';
    const M_LANG_ESUS = 'es-US';
    const M_LANG_ESES = 'es-ES';
    const M_LANG_FRCA = 'fr-CA';
    const M_LANG_FRFR = 'fr-FR';
    const M_LANG_DEDE = 'de-DE';
    const M_LANG_DEAT = 'de-AT';
    const M_LANG_ITIT = 'it-IT';
    const M_LANG_PTBR = 'pt-BR';
    const M_LANG_DADK = 'da-DK';
    const M_LANG_FILPH = 'fil-PH';
    const M_LANG_KOKR = 'ko-KR';
    const M_LANG_HIIN = 'hi-IN';
    const M_LANG_ARAE = 'ar-AE';
    const M_LANG_ARSA = 'ar-SA';
    const M_LANG_ZHCN = 'zh-CN';
    const M_LANG_NLNL = 'nl-NL';
    const M_LANG_NLBE = 'nl-BE';
    const M_LANG_ENIE = 'en-IE';
    const M_LANG_ENWL = 'en-WL';
    const M_LANG_ENAB = 'en-AB';
    const M_LANG_FAIR = 'fa-IR';
    const M_LANG_DECH = 'de-CH';
    const M_LANG_HEIL = 'he-IL';
    const M_LANG_IDID = 'id-ID';
    const M_LANG_JAJP = 'ja-JP';
    const M_LANG_MSMY = 'ms-MY';
    const M_LANG_PTPT = 'pt-PT';
    const M_LANG_RURU = 'ru-RU';
    const M_LANG_TAIN = 'ta-IN';
    const M_LANG_TEIN = 'te-IN';
    const M_LANG_TRTR = 'tr-TR';
    const M_LANG_NONO = 'no-NO';
    const M_LANG_NBNO = 'nb-NO';
    const M_LANG_NNNO = 'nn-NO';
    const M_LANG_PSAF = 'ps-AF';
    const M_LANG_PLPL = 'pl-PL';
    const M_LANG_RORO = 'ro-RO';
    const M_LANG_SVSE = 'sv-SE';
    const M_LANG_UKUA = 'uk-UA';
    const M_LANG_EUES = 'eu-ES';
    const M_LANG_FIFI = 'fi-FI';
    const M_LANG_HUHU = 'hu-HU';
    const M_LANG_MINZ = 'mi-NZ';
    const M_LANG_VIVN = 'vi-VN';

    const M_LANG_BGBG = 'bg-BG';
    const M_LANG_CSCZ = 'cs-CZ';
    const M_LANG_ELGR = 'el-GR';
    const M_LANG_HRHR = 'hr-HR';
    const M_LANG_LTLT = 'lt-LT';
    const M_LANG_LVLV = 'lv-LV';
    const M_LANG_SKSK = 'sk-SK';
    const M_LANG_SOSO = 'so-SO';
    const M_LANG_SLSI = 'sl-SI';
    const M_LANG_ISIS = 'is-IS';
    const M_LANG_MKMK = 'mk-MK';
    const M_LANG_SRRS = 'sr-RS';
}
