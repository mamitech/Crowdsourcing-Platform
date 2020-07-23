<?php

namespace App\Repository\Questionnaire\Statistics;

use Illuminate\Support\Facades\DB;

class QuestionnaireStatisticsRepository {

    /**
     * * getQuestionnaireResponseStatistics
     * * Returns responses vs goal responses.
     * *
     * * @param mixed $questionnaireId
     * * @return QuestionnaireResponseStatistics
     * */
    public function getQuestionnaireResponseStatistics($questionnaireId) {
        $totalResponses = DB::select('select count(*) as count from questionnaire_responses where questionnaire_id = ? and deleted_at is null;', [$questionnaireId]);
        $goalResponses = DB::select('select goal from questionnaires where id = ?;', [$questionnaireId]);
        return new QuestionnaireResponseStatistics($totalResponses[0]->count, $goalResponses[0]->goal);
    }

    /**
     * * getNumberOfResponsesPerLanguage
     * * Returns number of responses per language.
     * *
     * * @param mixed $questionnaireId
     * * @return QuestionnaireResponsesPerLanguage
     * */
    public function getNumberOfResponsesPerLanguage($questionnaireId) {
        $query = DB::select('SELECT count(*) as num_responses, language_code, language_name FROM questionnaire_responses as qr
                            join languages_lkp as ll on qr.language_id = ll.id
                            where questionnaire_id = ?
                            and qr.deleted_at is null
                            group by language_code, language_name;', [$questionnaireId]);

        return new QuestionnaireResponsesPerLanguage($query);
    }

    /**
     * * getStatisticsPerQuestion
     * * Returns a (sorted by question_id) collection of both fixed and free type replies.
     * *
     * * @param mixed $questionnaire
     * * @return array
     * * Example: $data = [
     * *       [
     * *           'question_id' => 1,
     * *           'question_title' => 'Title 1',
     * *           'question_type' => 'fixed_choices',
     * *           'statistics' => [
     * *       [
     * *           'answer_title' => 'answer 1',
     * *           'num_responses' => 10
     * *       ],
     * *       [
     * *           'answer_title' => 'answer 2',
     * *           'num_responses' => 19
     * *       ],
     * *       [
     * *           'answer_title' => 'answer 3',
     * *           'num_responses' => 12
     * *       ]
     * *   ]]];
     * */
    public function getStatisticsPerQuestion($questionnaire) {
        $fixedTypeQuestionStatistics = $this->getStatisticsForFixedChoicesQuestion($questionnaire->id);
        $freeTypeQuestionStatistics = $this->getStatisticsForFreeTextQuestion($questionnaire->id);
        $completeStatisticsPerQuestionnaire = array_merge($fixedTypeQuestionStatistics, $freeTypeQuestionStatistics);
        // Sort the array before collect().
        usort($completeStatisticsPerQuestionnaire, function($a, $b) {
            return $a['question_id'] <=> $b['question_id'];
        });

        return collect($completeStatisticsPerQuestionnaire);
    }

    /**
     * * getStatisticsForFixedChoicesQuestion
     * * Returns statistics for all fixed type questions for $questionnaireId
     * *
     * * @param int $questionnaireId
     * */
    private function getStatisticsForFixedChoicesQuestion(int $questionnaireId) {
        $query = DB::select("
            select qq.id as question_id, question as title, 'fixed_choices' as question_type, qpa.answer as answer_title, count(*) as num_responses from questionnaire_response_answers as qra
                inner join questionnaire_questions as qq on qra.question_id = qq.id
                inner join questionnaire_possible_answers as qpa on answer_id = qpa.id
            where qq.questionnaire_id = ? and qra.deleted_at is null and qq.deleted_at is null and qq.type in ('radiogroup', 'checkbox')
            group by question, qq.id, qpa.answer
            order by qq.id
        ;", [$questionnaireId]);
        $query = collect($query)->map(function($x) { return (array) $x;} )->toArray();

        // Construct a multidimensional array from $query.
        $constructedQuery = array();
        foreach ($query as $pos => $question) {
            if (array_search($question['question_id'], array_column($constructedQuery, 'question_id')) === false) {
                $constructedQuery[] = [
                    'question_id' => $question['question_id'],
                    'question_title' => $question['title'],
                    'question_type' => $question['question_type'],
                    'statistics' => [[
                        'answer_title' => $question['answer_title'],
                        'num_responses' => $question['num_responses']
                    ]]
                ];
            } else {
                // Get last array key to append to.
                $arrayLastKey = array_key_last($constructedQuery);
                array_push($constructedQuery[$arrayLastKey]['statistics'], array(
                    'answer_title' => $question['answer_title'],
                    'num_responses' => $question['num_responses']
                ));
            }
        }

        return $constructedQuery;
    }

    /**
     * * getStatisticsForFreeTextQuestion
     * * Returns statistics for all free type questions for $questionnaireId
     * *
     * * @param int $questionnaireId
     * */
    protected function getStatisticsForFreeTextQuestion(int $questionnaireId) {
        $query = DB::select("select qq.id as question_id,
                   question as title,
                   'free_text' as question_type,
                   ifnull(english_translation, answer) as answer_text,
                   case when english_translation is null then 0 else 1 end as is_translated,
                   answer as answer_original_text,
                   ifnull(ll.language_name, 'English') as language_name,
                   ifnull(initial_language_detected, 'en') as language_code
                   from questionnaire_questions as qq
                join questionnaire_response_answers as qra on qra.question_id = qq.id
                join questionnaire_response_answer_texts as qrat on qrat.questionnaire_response_answer_id = qra.id
                left outer join languages_lkp as ll on ll.language_code = initial_language_detected
            where qq.type in ('text', 'comment') and qq.deleted_at is null and qq.questionnaire_id = ?
            order by question;
            ", [$questionnaireId]);
        $query = collect($query)->map(function($x) { return (array) $x;} )->toArray();

        // Construct a multidimensional array from $query.
        $constructedQuery = array();
        foreach ($query as $pos => $question) {
            if (array_search($question['question_id'], array_column($constructedQuery, 'question_id')) === false) {
                $constructedQuery[] = [
                    'question_id' => $question['question_id'],
                    'question_title' => $question['title'],
                    'question_type' => $question['question_type'],
                    'statistics' => [[
                        'answer_text' => $question['answer_text'],
                        'is_translated' => $question['is_translated'],
                        'answer_original_text' => $question['answer_original_text'],
                        'origin_language' => [
                            'language_name' => $question['language_name'],
                            'language_code' => $question['language_code']
                        ]
                    ]]
                ];
            } else {
                // Get last array key to append to.
                $arrayLastKey = array_key_last($constructedQuery);
                array_push($constructedQuery[$arrayLastKey]['statistics'], array(
                    'answer_text' => $question['answer_text'],
                    'is_translated' => $question['is_translated'],
                    'answer_original_text' => $question['answer_original_text'],
                    'origin_language' => [
                        'language_name' => $question['language_name'],
                        'language_code' => $question['language_code']
                    ]
                ));
            }
        }

        return $constructedQuery;
    }

}