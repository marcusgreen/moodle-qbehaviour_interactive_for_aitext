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
 * Walkthrough tests for the interactive_for_aitext question behaviour.
 *
 * @package    qbehaviour_interactive_for_aitext
 * @category   test
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qbehaviour_interactive_for_aitext;

use question_bank;
use question_hint;
use question_state;
use question_utils;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../../../engine/lib.php');
require_once(__DIR__ . '/../../../engine/tests/helpers.php');

/**
 * Walkthrough tests for qbehaviour_interactive_for_aitext.
 *
 * Under PHPUNIT_TEST, qtype_aitext_question::perform_request() short-circuits and
 * returns the literal string "AI Feedback" without contacting a backend. That reply
 * is not JSON, so grading yields no marks and a fraction of 0, which is the case
 * these tests exercise: the student never clears the success threshold and so uses
 * every try.
 *
 * @package    qbehaviour_interactive_for_aitext
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qbehaviour_interactive_for_aitext
 */
final class walkthrough_test extends \qbehaviour_walkthrough_test_base {

    /**
     * Build an aitext question with the given number of hint rows.
     *
     * Each hint row is an instruction to the AI and grants the student one extra try,
     * so a question with two rows allows three tries in total.
     *
     * @param int $numhints how many hint rows to attach.
     * @return \qtype_aitext_question the question.
     */
    protected function make_question_with_hints(int $numhints) {
        require_once(__DIR__ . '/../../../type/aitext/tests/helper.php');

        $question = \test_question_maker::make_question('aitext', 'plain');
        $question->penalty = 0.3333333;

        $question->hints = [];
        for ($i = 1; $i <= $numhints; $i++) {
            $question->hints[] = new question_hint($i, 'Hint instruction ' . $i, FORMAT_HTML);
        }

        return $question;
    }

    public function test_student_gets_a_hint_and_a_second_try(): void {
        $this->resetAfterTest();

        $question = $this->make_question_with_hints(1);
        $this->start_attempt_at_question($question, 'interactive', 2);

        // Two hint-derived tries: one hint row plus the final try.
        $this->assertEquals(2, $this->quba->get_question_attempt($this->slot)
                ->get_last_behaviour_var('_triesleft'));

        // First submission. Grading yields no marks, so a try remains.
        $this->process_submission(['answer' => 'My first attempt.', '-submit' => 1]);

        $this->check_current_state(question_state::$todo);
        $this->check_current_mark(null);
        $this->assertEquals(1, $this->quba->get_question_attempt($this->slot)
                ->get_last_behaviour_var('_triesleft'));

        // A hint was generated and stored for the student to read before retrying.
        $hint = $this->quba->get_question_attempt($this->slot)->get_last_behaviour_var('_aihint');
        $this->assertNotNull($hint);
        $this->assertStringContainsString('AI Feedback', $hint);

        // The prompt sent to the AI is kept alongside it.
        $this->assertNotNull($this->quba->get_question_attempt($this->slot)
                ->get_last_behaviour_var('_aihintprompt'));

        // The student is offered the try again button rather than another submit.
        $this->render();
        $this->check_output_contains_lang_string('tryagain', 'qbehaviour_interactive');

        // Try again returns the question to an answerable state.
        $this->process_submission(['-tryagain' => 1]);
        $this->check_current_state(question_state::$todo);

        // Second and final submission ends the attempt.
        $this->process_submission(['answer' => 'My second, different attempt.', '-submit' => 1]);
        $this->assertTrue($this->quba->get_question_state($this->slot)->is_finished());
    }

    public function test_resubmitting_an_unchanged_response_is_discarded(): void {
        $this->resetAfterTest();

        $question = $this->make_question_with_hints(2);
        $this->start_attempt_at_question($question, 'interactive', 3);

        $this->process_submission(['answer' => 'The same words.', '-submit' => 1]);
        $triesleft = $this->quba->get_question_attempt($this->slot)->get_last_behaviour_var('_triesleft');
        $stepcount = $this->quba->get_question_attempt($this->slot)->get_num_steps();

        // Submitting the identical response must not cost a try or add a step,
        // because it would spend two AI calls to tell the student nothing new.
        $this->process_submission(['-tryagain' => 1]);
        $this->process_submission(['answer' => 'The same words.', '-submit' => 1]);

        $this->assertEquals($triesleft, $this->quba->get_question_attempt($this->slot)
                ->get_last_behaviour_var('_triesleft'));
        // Only the try again step was added.
        $this->assertEquals($stepcount + 1, $this->quba->get_question_attempt($this->slot)->get_num_steps());
    }

    public function test_no_hint_is_generated_on_the_final_try(): void {
        $this->resetAfterTest();

        // No hint rows means a single try, exactly as in the core interactive behaviour.
        $question = $this->make_question_with_hints(0);
        $this->start_attempt_at_question($question, 'interactive', 1);

        $this->assertEquals(1, $this->quba->get_question_attempt($this->slot)
                ->get_last_behaviour_var('_triesleft'));

        $this->process_submission(['answer' => 'My only attempt.', '-submit' => 1]);

        $this->assertTrue($this->quba->get_question_state($this->slot)->is_finished());
        $this->assertNull($this->quba->get_question_attempt($this->slot)->get_last_behaviour_var('_aihint'));
    }

    public function test_success_threshold_ends_the_attempt_early(): void {
        $this->resetAfterTest();

        // A threshold of 0 means any grade at all is a pass, so the first submission
        // finishes the attempt even though tries remain.
        set_config('hintsuccessthreshold', '0', 'qtype_aitext');

        $question = $this->make_question_with_hints(2);
        $this->start_attempt_at_question($question, 'interactive', 3);

        $this->process_submission(['answer' => 'Good enough.', '-submit' => 1]);

        $this->assertTrue($this->quba->get_question_state($this->slot)->is_finished());
        $this->assertNull($this->quba->get_question_attempt($this->slot)->get_last_behaviour_var('_aihint'));
    }

    public function test_ai_grading_results_are_persisted_on_the_step(): void {
        $this->resetAfterTest();

        $question = $this->make_question_with_hints(1);
        $this->start_attempt_at_question($question, 'interactive', 2);

        $this->process_submission(['answer' => 'Something to grade.', '-submit' => 1]);

        $qa = $this->quba->get_question_attempt($this->slot);
        $this->assertStringContainsString('AI Feedback', $qa->get_last_behaviour_var('_comment'));
        $this->assertStringContainsString(
            'Something to grade.',
            $qa->get_last_behaviour_var('_aiprompt')
        );
    }

    public function test_the_behaviour_used_is_the_ai_interactive_one(): void {
        $this->resetAfterTest();

        $question = $this->make_question_with_hints(1);
        $this->start_attempt_at_question($question, 'interactive', 2);

        $this->assertInstanceOf(
            \qbehaviour_interactive_for_aitext::class,
            $this->quba->get_question_attempt($this->slot)->get_behaviour()
        );
    }
}
