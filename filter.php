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
 * Full translate
 *
 * @package    filter
 * @copyright  2020 Farhan Karmali <farhan6318@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

class filter_fulltranslate extends moodle_text_filter {

    const TABLENAME = 'filter_fulltranslate';

    public function filter($text, array $options = []) {
        global $CFG;
        if (empty($text) or is_numeric($text)) {
            return $text;
        }
        $language = current_language();

        if (empty(get_config('filter_fulltranslate', 'translatewhensitedefault')) && $CFG->lang == $language) {
            return $text;
        }

        $format = 0;
        //TODO : Not sure about the code below, I am trying to escape some unwanted strings. e.g those coming from admin settings.
        if (isset($options['originalformat'])) {
            if ($options['originalformat'] == FORMAT_HTML) {
                $format = FORMAT_HTML;
            } else if ($options['originalformat'] == FORMAT_PLAIN){
                $format = 0;
            }
        }

        return $this->get_translation($text, $language, $format);
    }

    private function containsmlangtags($text) {
        $patternstocheck = [];

        $patternstocheck[] = '/{\s*mlang\s+(                               # Look for the leading {mlang
                                    (?:[a-z0-9_-]+)             # At least one language must be present
                                                                # (but dont capture it individually).
                                    (?:\s*,\s*[a-z0-9_-]+\s*)*  # More can follow, separated by commas
                                                                # (again dont capture them individually).
                                )\s*}                           # Capture the language list as a single capture.
                   (.*?)                                        # Now capture the text to be filtered.
                   {\s*mlang\s*}                                # And look for the trailing {mlang}.
                   /isx';

        if (empty($CFG->filter_multilang_force_old) and !empty($CFG->filter_multilang_converted)) {
            // new syntax
            $patternstocheck[] = '/(<span(\s+lang="[a-zA-Z0-9_-]+"|\s+class="multilang"){2}\s*>.*?<\/span>)(\s*<span(\s+lang="[a-zA-Z0-9_-]+"|\s+class="multilang"){2}\s*>.*?<\/span>)+/is';
        } else {
            // old syntax
            $patternstocheck[] = '/(<(?:lang|span) lang="[a-zA-Z0-9_-]*".*?>.*?<\/(?:lang|span)>)(\s*<(?:lang|span) lang="[a-zA-Z0-9_-]*".*?>.*?<\/(?:lang|span)>)+/is';
        }

        foreach ($patternstocheck as $patterntocheck) {
            if (preg_match($patterntocheck, $text)) {
                return true;
            }
        }
    }

    public function get_translation($text, $language, $format) {
        global $DB, $CFG, $SESSION;
        $hashkey = sha1(trim($text));
        $records = $DB->get_records(self::TABLENAME, ['hashkey' => $hashkey, 'lang' => $language], 'id ASC', 'translation', 0, 1);
        if (isset(reset($records)->translation)) {
            $translatedtext = reset($records)->translation;
        }

        if (isset($translatedtext)) {
            $DB->set_field(self::TABLENAME, 'lastaccess', time(), ['hashkey' => $hashkey, 'lang' => $language]);
        } else {
            $translatedtext = $this->generate_translation_update_database($text, $language, $hashkey, $format);
        }

        if (self::showeditbutton()) {
            $records =$DB->get_records(self::TABLENAME, ['hashkey' => $hashkey, 'lang' => $language], 'id ASC', 'id', 0, 1);
            $id = reset($records)->id;

            if (!isset($SESSION->filter_fulltranslate)) {
                $SESSION->filter_fulltranslate = new stdClass();
                $SESSION->filter_fulltranslate->strings = [];
            } else {
                $SESSION->filter_fulltranslate->strings[$id] = $translatedtext;
            }
            $translatedtext .= '<a target="_blank" data-action="translation-edit" data-recordid="'.$id.'" href="'.$CFG->wwwroot.'/admin/tool/translationmanager/edit.php?id='.$id.'">
                <i class="fa fa-pencil-square-o" aria-hidden="true"></i></a>';
        }
        return $translatedtext;
    }

    private static $showeditbutton = null;
    private static function showeditbutton() {
        if (!isset(self::$showeditbutton)) {
            self::$showeditbutton = true;

            foreach (array_keys($_GET) as $param) {
                if (strpos($param, 'download') !== false) {
                    self::$showeditbutton = false;
                    return self::$showeditbutton;
                }
            }

            if (!empty(WS_SERVER)) {
                self::$showeditbutton = false;
                return self::$showeditbutton;
            }

            if (!has_capability('filter/fulltranslate:edittranslations', context_system::instance())) {
                self::$showeditbutton = false;
                return self::$showeditbutton;
            }
        }

        return self::$showeditbutton;
    }

    public function generate_translation_update_database($text, $language, $hashkey, $format) {
        global $DB, $PAGE, $CFG;
        $translation = $this->generate_translation($text, $language);
        if ($translation) {
           $hidefromtable = 0;
        } else {
            $translation = $text;
            $hidefromtable = 1;
        }
        $record = (object) [
            'hashkey' => $hashkey,
            'sourcetext' => $text,
            'textformat' => $format ? 'html' : 'plain',
            'timecreated' => time(),
            'lang' => $language,
            'url' => str_replace($CFG->wwwroot, '', $PAGE->url->out()),
            'automatic' => true,
            'translation' => $translation,
            'hidefromtable' => $hidefromtable
        ];
        $DB->insert_record(self::TABLENAME, $record);
        return $translation;
    }

    public function generate_translation($text, $language) {
        global $CFG;
        if (get_config('filter_fulltranslate', 'usegoogle') ==  0) {
            return $text;
        }

        if (!empty(get_config('filter_fulltranslate', 'skipmlangtags')) && $this->containsmlangtags($text)) {
            return $text;
        }

        $language = str_replace('_wp', '', $language);
        require_once($CFG->libdir. "/filelib.php");
        $curl = new curl();
        // Note: We could pass a param called 'source' => 'en' instead of letting google autodetect this.
        $params = [
            'target' => $language,
            'key' => get_config('filter_fulltranslate', 'apikey'),
            'q' => $text
        ];
        $resp = $curl->post('https://translation.googleapis.com/language/translate/v2?', $params);
        $resp = json_decode($resp);

        // We are comparing the autodetected language and the target language if they are both same, we ignore the translation.
        if (!empty($resp->data->translations[0]->translatedText) && $resp->data->translations[0]->detectedSourceLanguage != $language) {
            return $resp->data->translations[0]->translatedText;
        }
    }
}
