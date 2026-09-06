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
 * Question behaviour for AI-graded text questions with multiple tries and AI hints.
 *
 * @package    qbehaviour_interactive_for_aitext
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/behaviour/interactive/behaviour.php');

/**
 * Question behaviour for qtype_aitext with multiple tries and LLM generated hints.
 *
 * Extends the core interactive behaviour. After each submission that does not end
 * the attempt, a second call is made to the LLM to produce a hint tailored to what
 * the student actually wrote. The hint is stored on the step as the cached
 * behaviour variable _aihint and rendered above the "Try again" button.
 *
 * The question_hints rows attached to the question are NOT shown to the student.
 * Their text is used as a per-try instruction telling the LLM how strong a hint to
 * give at that stage. The number of hint rows therefore still sets the number of
 * tries, exactly as in the core interactive behaviour.
 *
 * Cached behaviour variables written by this behaviour:
 *   _comment             AI-generated feedback (HTML).
 *   _aiprompt            The full grading prompt sent to the AI.
 *   _spellcheckresponse  Grammar/spelling correction, if enabled.
 *   _aihint              The AI-generated hint for the next try (plain text).
 *   _aihintprompt        The full hint prompt sent to the AI.
 *
 * @package    qbehaviour_interactive_for_aitext
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_interactive_for_aitext extends qbehaviour_interactive {

    /**
     * Only compatible with qtype_aitext_question instances.
     *
     * @param question_definition $question the question.
     * @return bool true if this behaviour can be used with this question type.
     */
    public function is_compatible_question(question_definition $question) {
        return $question instanceof qtype_aitext_question;
    }

    /**
     * The stored hints are instructions for the LLM, not text for the student.
     *
     * Returning them here would leak the teacher's instruction to the student via
     * qtype_renderer::feedback(). The student-facing hint is the AI-generated one,
     * which this behaviour's renderer displays from the _aihint behaviour variable.
     *
     * @return null always.
     */
    public function get_applicable_hint() {
        return null;
    }

    /**
     * The mark fraction at or above which the attempt is considered successful.
     *
     * Core interactive ends the attempt early only on question_state::$gradedright,
     * which means a fraction of 1.0. AI-marked prose rarely scores full marks, so
     * without a lower threshold every student would use every try however well they
     * did. Configurable via the qtype_aitext/hintsuccessthreshold admin setting,
     * which defaults to 1.0 so behaviour is unchanged until an admin lowers it.
     *
     * @return float a fraction between 0 and 1.
     */
    protected function get_success_threshold(): float {
        $threshold = get_config('qtype_aitext', 'hintsuccessthreshold');
        if ($threshold === false || $threshold === '' || !is_numeric($threshold)) {
            return 1.0;
        }
        return min(1.0, max(0.0, (float) $threshold));
    }

    /**
     * Process a submit action.
     *
     * This is a modified copy of qbehaviour_interactive::process_submit(). It cannot
     * simply call the parent because of two changes:
     *  - the attempt ends early on any fraction at or above the success threshold,
     *    not only on question_state::$gradedright;
     *  - AI results and, where a try remains, an AI hint are written onto the step.
     *
     * @param question_attempt_pending_step $pendingstep the step being processed.
     * @return bool question_attempt::KEEP or question_attempt::DISCARD.
     */
    public function process_submit(question_attempt_pending_step $pendingstep) {
        if ($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }

        if (!$this->is_complete_response($pendingstep)) {
            $pendingstep->set_state(question_state::$invalid);
            return question_attempt::KEEP;
        }

        $response = $pendingstep->get_qt_data();

        // Do not spend a try, or two AI calls, on a response the student has not changed.
        if ($this->is_same_as_last_submitted_response($response)) {
            return question_attempt::DISCARD;
        }

        $triesleft = $this->qa->get_last_behaviour_var('_triesleft');
        [$fraction, $state] = $this->question->grade_response($response);

        // Persist whatever the grading call produced, whichever way the attempt goes.
        $this->apply_ai_results_to_step($pendingstep);

        $finished = $fraction >= $this->get_success_threshold() || $triesleft == 1;

        if ($finished) {
            $pendingstep->set_state($state);
            $pendingstep->set_fraction($this->adjust_fraction($fraction, $pendingstep));
        } else {
            $pendingstep->set_behaviour_var('_triesleft', $triesleft - 1);
            $pendingstep->set_state(question_state::$todo);
            $this->apply_ai_hint_to_step($pendingstep, $response, $triesleft);
        }

        $pendingstep->set_new_response_summary($this->question->summarise_response($response));
        return question_attempt::KEEP;
    }

    /**
     * Has this response already been submitted (as opposed to merely saved)?
     *
     * A student who presses Submit twice without editing should not lose a try or
     * trigger further AI calls. Only submitted steps are compared; intermediate
     * autosaves are ignored.
     *
     * @param array $response the response being submitted.
     * @return bool true if an earlier submit carried the same response.
     */
    protected function is_same_as_last_submitted_response(array $response): bool {
        foreach ($this->qa->get_reverse_step_iterator() as $step) {
            if (!$step->has_behaviour_var('submit')) {
                continue;
            }
            return $this->question->is_same_response($step->get_qt_data(), $response);
        }
        return false;
    }

    /**
     * Write AI grading results from the question cache onto a pending step.
     *
     * After grade_response() runs, the question caches its results on public
     * properties. This writes them as cached behaviour variables so they are
     * persisted by the question engine unit of work.
     *
     * @param question_attempt_pending_step $pendingstep the step currently being built.
     */
    protected function apply_ai_results_to_step(question_attempt_pending_step $pendingstep): void {
        $question = $this->question;

        if (isset($question->lastaicomment) && $question->lastaicomment !== null) {
            // The AI comment is always HTML; the renderer hard-codes FORMAT_HTML when
            // displaying it, so no separate _commentformat var is persisted.
            $pendingstep->set_behaviour_var('_comment', $question->lastaicomment);
        }

        if (isset($question->lastaiprompt) && $question->lastaiprompt !== null) {
            $pendingstep->set_behaviour_var('_aiprompt', $question->lastaiprompt);
        }

        if (isset($question->lastspellcheckresponse) && $question->lastspellcheckresponse !== null) {
            $pendingstep->set_behaviour_var('_spellcheckresponse', $question->lastspellcheckresponse);
        }
    }

    /**
     * Generate a hint for the next try and write it onto the pending step.
     *
     * The teacher's hint row for this try is passed to the LLM as an instruction
     * describing how strong a hint to give. Hint generation never blocks the
     * attempt: if it fails or returns nothing, no _aihint var is written and the
     * student simply gets the "Try again" button with no hint.
     *
     * @param question_attempt_pending_step $pendingstep the step currently being built.
     * @param array $response the response just submitted.
     * @param int $triesleft the number of tries left before this submission.
     */
    protected function apply_ai_hint_to_step(
        question_attempt_pending_step $pendingstep,
        array $response,
        int $triesleft
    ): void {
        $totaltries = $this->qa->get_step(0)->get_behaviour_var('_triesleft');
        $attemptnumber = $totaltries - $triesleft + 1;

        $hint = $this->question->generate_hint(
            $response,
            $this->get_hint_instruction($attemptnumber),
            (string) ($this->question->lastaicomment ?? ''),
            $attemptnumber,
            $this->get_previous_responses()
        );

        if (trim($hint) === '') {
            return;
        }

        $pendingstep->set_behaviour_var('_aihint', $hint);

        if (isset($this->question->lastaihintprompt) && $this->question->lastaihintprompt !== null) {
            $pendingstep->set_behaviour_var('_aihintprompt', $this->question->lastaihintprompt);
        }
    }

    /**
     * The teacher's instruction to the LLM for the given try, if there is one.
     *
     * Hint rows are consumed in order, so the first submission uses the first row.
     * A question with no hint rows has only one try and never reaches this method.
     *
     * @param int $attemptnumber which submission this is, counting from 1.
     * @return string|null the instruction text, or null if there is no row for this try.
     */
    protected function get_hint_instruction(int $attemptnumber): ?string {
        $hint = $this->question->get_hint($attemptnumber - 1, $this->qa);
        if (empty($hint) || trim((string) $hint->hint) === '') {
            return null;
        }
        return $hint->hint;
    }

    /**
     * The responses the student submitted on earlier tries, oldest first.
     *
     * Passed to the hint prompt so a later hint can take account of what has
     * already been tried, rather than repeating an earlier one.
     *
     * @return string[] the answer text of each earlier submitted response.
     */
    protected function get_previous_responses(): array {
        $responses = [];
        foreach ($this->qa->get_step_iterator() as $step) {
            if (!$step->has_behaviour_var('submit')) {
                continue;
            }
            $data = $step->get_qt_data();
            if (!empty($data['answer'])) {
                $responses[] = (string) $data['answer'];
            }
        }
        return $responses;
    }

    /**
     * Process a finish action.
     *
     * Calls the parent, which grades the response, then persists the AI results.
     * No hint is generated: the attempt is over.
     *
     * @param question_attempt_pending_step $pendingstep the step being processed.
     * @return bool question_attempt::KEEP or question_attempt::DISCARD.
     */
    public function process_finish(question_attempt_pending_step $pendingstep) {
        $result = parent::process_finish($pendingstep);

        if ($result === question_attempt::KEEP) {
            $this->apply_ai_results_to_step($pendingstep);
        }

        return $result;
    }

    /**
     * Dispatch the processing of a pending step to the appropriate handler.
     *
     * A 'spellcheckedit' behaviour variable means a teacher submitted an edited
     * version of the student's response via the AI spellcheck form; that is handled
     * separately because it must not change the grade. Everything else goes to the
     * core interactive dispatcher.
     *
     * @param question_attempt_pending_step $pendingstep the step being processed.
     * @return bool question_attempt::KEEP or question_attempt::DISCARD.
     */
    public function process_action(question_attempt_pending_step $pendingstep) {
        if ($pendingstep->has_behaviour_var('spellcheckedit')) {
            return $this->process_spellcheck_edit($pendingstep);
        }
        return parent::process_action($pendingstep);
    }

    /**
     * Persist a teacher's edited spellcheck version of the response.
     *
     * The state and fraction are unchanged; the step is kept only so the edited
     * response persists for display in the renderer.
     *
     * @param question_attempt_pending_step $pendingstep the step being processed.
     * @return bool question_attempt::KEEP or question_attempt::DISCARD.
     */
    protected function process_spellcheck_edit(question_attempt_pending_step $pendingstep): bool {
        if (!$this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }
        $pendingstep->set_state($this->qa->get_state());
        $pendingstep->set_fraction($this->qa->get_fraction());
        return question_attempt::KEEP;
    }

    /**
     * Summarise what happened in a given step.
     *
     * @param question_attempt_step $step the step to summarise.
     * @return string a plain-text summary.
     */
    public function summarise_action(question_attempt_step $step) {
        if ($step->has_behaviour_var('spellcheckedit')) {
            return get_string('spellcheckeditaction', 'qbehaviour_interactive_for_aitext');
        }
        return parent::summarise_action($step);
    }
}
