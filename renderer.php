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
 * Renderer for the interactive with AI hints question behaviour.
 *
 * @package    qbehaviour_interactive_for_aitext
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/behaviour/interactive/renderer.php');

/**
 * Renders the AI-generated hint above the "Try again" button.
 *
 * The hint is displayed from the _aihint cached behaviour variable rather than
 * through a question_hint object, because qtype_aitext_renderer::feedback() —
 * which is where core would render a question_hint — deliberately returns an
 * empty string outside question preview.
 *
 * @package    qbehaviour_interactive_for_aitext
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_interactive_for_aitext_renderer extends qbehaviour_interactive_renderer {

    /**
     * Render the AI hint, then the "Try again" button.
     *
     * The parent decides whether a try-again button is appropriate at all and
     * returns an empty string when it is not. The hint is only shown when the
     * parent produces a button, so a finished attempt never displays a hint for a
     * try the student cannot take.
     *
     * @param question_attempt $qa a question attempt.
     * @param question_display_options $options controls what should and should not be displayed.
     * @return string HTML fragment.
     */
    public function feedback(question_attempt $qa, question_display_options $options) {
        $tryagain = parent::feedback($qa, $options);

        if ($tryagain === '') {
            return '';
        }

        return $this->ai_hint($qa, $options) . $tryagain;
    }

    /**
     * The AI-generated hint for the current try, if there is one.
     *
     * @param question_attempt $qa a question attempt.
     * @param question_display_options $options controls what should and should not be displayed.
     * @return string HTML fragment, empty if no hint was generated.
     */
    protected function ai_hint(question_attempt $qa, question_display_options $options) {
        if (!$options->feedback) {
            return '';
        }

        $hint = $qa->get_last_behaviour_var('_aihint');
        if ($hint === null || trim($hint) === '') {
            return '';
        }

        // The hint text is formatted by qtype_aitext_question::generate_hint(), which
        // also appends the site disclaimer with {{model}} resolved to the model that
        // actually produced the hint.
        $output = html_writer::tag(
            'div',
            get_string('aihintheading', 'qbehaviour_interactive_for_aitext'),
            ['class' => 'aihintheading font-weight-bold']
        );
        $output .= format_text($hint, FORMAT_HTML, ['context' => $options->context]);

        return html_writer::tag('div', $output, ['class' => 'hint aihint alert alert-info']);
    }
}
