# qbehaviour_interactive_for_aitext

Question behaviour plugin for Moodle that extends interactive-with-multiple-tries
to work with AI-graded text questions, adding LLM-generated hints between tries.

## Purpose

Designed exclusively for use with `qtype_aitext`. After each submission that
doesn't end the attempt, a second call is made to the LLM to produce a hint
tailored to what the student actually wrote, shown above the "Try again" button.

The question's `question_hints` rows are NOT shown to the student. Their text
is instead passed to the LLM as a per-try instruction on how strong a hint to
give at that stage. The number of hint rows still sets the number of tries,
exactly as in the core interactive behaviour.

## How it works

`process_submit()` grades the response, persists the AI results, and — if a
try remains and the mark is below the success threshold — generates and
stores an AI hint for the next attempt.

| Variable              | Content                                      |
|------------------------|----------------------------------------------|
| `_comment`             | AI-generated feedback (HTML)                 |
| `_aiprompt`            | Full grading prompt sent to the AI           |
| `_spellcheckresponse`  | Grammar/spelling correction (if enabled)     |
| `_aihint`              | AI-generated hint for the next try (text)    |
| `_aihintprompt`        | Full hint prompt sent to the AI              |

Unlike core interactive, which ends the attempt only on a full-mark
`gradedright` state, this behaviour ends early once the mark reaches a
configurable success threshold (`qtype_aitext/hintsuccessthreshold`, admin
setting, defaults to 1.0 so behaviour is unchanged until lowered) — since
AI-marked prose rarely scores full marks.

## Requirements

- Moodle 4.5 or later
- `qtype_aitext` question type

## Installation

Place in `question/behaviour/interactive_for_aitext/`. Run Moodle upgrade.

This behaviour is not archetypal — it does not appear in quiz settings. It is
selected automatically by `qtype_aitext_question::make_behaviour()`.

## License

GNU GPL v3 or later — http://www.gnu.org/copyleft/gpl.html

Copyright 2026 Marcus Green
