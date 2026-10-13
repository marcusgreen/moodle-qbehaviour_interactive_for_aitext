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
     * try the student cannot take. A finished attempt shows the penalty notice instead.
     *
     * @param question_attempt $qa a question attempt.
     * @param question_display_options $options controls what should and should not be displayed.
     * @return string HTML fragment.
     */
    public function feedback(question_attempt $qa, question_display_options $options) {
        $tryagain = parent::feedback($qa, $options);

        if ($tryagain === '') {
            return $this->penalty_notice($qa, $options);
        }

        return $this->ai_hint($qa, $options) . $tryagain;
    }

    /**
     * Explain that the final mark includes a penalty for earlier tries.
     *
     * The AI marks each try on its own merits, so its feedback can say "5/5" while
     * the recorded mark is lower after the penalty. This notice reconciles the two.
     *
     * The mark before the penalty is not stored and cannot always be recovered,
     * because qbehaviour_interactive::adjust_fraction() floors the fraction at 0.
     * The notice therefore states the penalty rate and the number of earlier tries,
     * which is always true, and works for attempts made before this notice existed.
     *
     * @param question_attempt $qa a question attempt.
     * @param question_display_options $options controls what should and should not be displayed.
     * @return string HTML fragment, empty if no penalty applies.
     */
    protected function penalty_notice(question_attempt $qa, question_display_options $options) {
        if ($options->marks < question_display_options::MARK_AND_MAX) {
            return '';
        }

        if (!$qa->get_state()->is_finished() || $qa->get_fraction() === null) {
            return '';
        }

        // A teacher's manual grade replaces the penalised mark, so the notice no longer applies.
        if ($qa->get_last_behaviour_var('mark') !== null) {
            return '';
        }

        $penalty = (float) $qa->get_question()->penalty;
        if ($penalty <= 0) {
            return '';
        }

        // The same count qbehaviour_interactive::adjust_fraction() uses. The final
        // submission does not set _triesleft, so the last value is the one before it.
        $totaltries = (int) $qa->get_step(0)->get_behaviour_var('_triesleft');
        $triesleft = (int) $qa->get_last_behaviour_var('_triesleft');
        $earliertries = $totaltries - $triesleft;
        if ($earliertries <= 0) {
            return '';
        }

        $a = (object) [
            'penalty' => format_float($penalty * 100, 1, true, true),
            'tries' => $earliertries,
        ];
        $string = $earliertries === 1 ? 'penaltynoticeone' : 'penaltynotice';

        return html_writer::div(
            get_string($string, 'qbehaviour_interactive_for_aitext', $a),
            'penaltynotice alert alert-secondary'
        );
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
            ['class' => 'aihintheading']
        );
        $output .= format_text($hint, FORMAT_HTML, ['context' => $options->context]);

        return html_writer::tag('div', $output, ['class' => 'hint aihint alert alert-info']);
    }
}
