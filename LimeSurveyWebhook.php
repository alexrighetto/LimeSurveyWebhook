<?php

/***** ***** ***** ***** *****
* Send a JSON POST request after each afterSurveyComplete event
*
* @originalauthor Stefan Verweij <stefan@evently.nl>
* @copyright 2016 Evently
* @author IrishWolf, updated by Alex Righetto
* @version 1.2.0 - Enhanced with Question Titles
***** ***** ***** ***** *****/

class LimeSurveyWebhook extends PluginBase
{
    protected $storage = 'DbStorage';
    static protected $description = 'Advanced Webhook for LimeSurvey (includes question and answer labels)';
    static protected $name = 'LimeSurveyWebhook';
    protected $surveyId;

    public function init()
    {
        $this->subscribe('afterSurveyComplete');
    }

    protected $settings = array(
        'sUrl' => array(
            'type' => 'string',
            'label' => 'The default URL to send the webhook to:',
            'help' => 'Use https://webhook.site for testing'
        ),
        'sId' => array(
            'type' => 'string',
            'default' => '000000',
            'label' => 'Survey IDs:',
            'help' => 'Multiple surveys separated by ","'
        ),
        'sAuthToken' => array(
            'type' => 'string',
            'label' => 'API Authentication Token'
        ),
        'sBug' => array(
            'type' => 'select',
            'options' => array(
                0 => 'No',
                1 => 'Yes'
            ),
            'default' => 0,
            'label' => 'Enable Debug Mode',
            'help' => 'Enable debug mode to see what data is transmitted.'
        )
    );

    public function afterSurveyComplete()
    {
        $oEvent = $this->getEvent();
        $surveyId = $oEvent->get('surveyId');
        $hookSurveyId = $this->get('sId', null, null, $this->settings['sId']);

        $hookSurveyIdArray = is_array($hookSurveyId) ? array_map('trim', $hookSurveyId) : explode(',', preg_replace('/\s+/', '', $hookSurveyId));

        if (in_array($surveyId, $hookSurveyIdArray)) {
            $this->callWebhook('afterSurveyComplete');
        }
    }

    private function callWebhook($comment)
    {
        $time_start = microtime(true);
        $event = $this->getEvent();
        $surveyId = $event->get('surveyId');
        $responseId = $event->get('responseId');

        //  Ottieni la risposta grezza
        $response = $this->pluginManager->getAPI()->getResponse($surveyId, $responseId);

        //  Data submit corretta
        $submitDate = empty($response['submitdate']) || $response['submitdate'] == '1980-01-01 00:00:00' ? date('Y-m-d H:i:s') : $response['submitdate'];

        //  Token e partecipante
        $token = $response['token'] ?? null;

        $participant = null;
        if (!empty($token)) {
            $query = "SELECT firstname, lastname, email FROM {{tokens_$surveyId}} WHERE token = :token LIMIT 1";
            $participant = Yii::app()->db->createCommand($query)
                ->bindParam(":token", $token, PDO::PARAM_STR)
                ->queryRow();
        }

        //  Costruisci le risposte arricchite con le domande e opzioni
        $enrichedResponses = $this->getEnrichedResponses($surveyId, $response);

        //  Prepara il payload
        $payload = array(
            "api_token" => $this->get('sAuthToken', null, null, $this->settings['sAuthToken']),
            "survey" => $surveyId,
            "event" => $comment,
            "respondId" => $responseId,
            "submitDate" => $submitDate,
            "token" => $token,
            "participant" => $participant,
            "responses" => $enrichedResponses
        );

        $jsonPayload = json_encode($payload);

        //  Invia il webhook
        $hookSent = $this->httpPost(
            $this->get('sUrl', null, null, $this->settings['sUrl']),
            $jsonPayload
        );

        //  Debug (se attivo)
        $this->log($comment . " | JSON Payload: " . $jsonPayload . " | Response: " . $hookSent);
        $this->debug($jsonPayload, $hookSent, $time_start);
    }

    /**
     * Ottiene il testo delle domande e delle opzioni risposta per ogni risposta
     */
    private function getEnrichedResponses($surveyId, $response)
    {
        $enriched = [];

        foreach ($response as $questionCode => $answerCode) {
            if (in_array($questionCode, ['id', 'submitdate', 'lastpage', 'seed', 'startlanguage', 'token', 'datestamp', 'ipaddr', 'refurl'])) {
                continue;
            }

            $questionData = $this->getQuestionDetails($surveyId, $questionCode, $answerCode);

            $enriched[] = array(
                "question_code" => $questionCode,
                "question_text" => $questionData['question_text'] ?? null,
                "answer_code" => $answerCode,
                "answer_label" => $questionData['answer_text'] ?? $answerCode
            );
        }

        return $enriched;
    }

    /**
     * Recupera il testo della domanda e della risposta
     */
    private function getQuestionDetails($surveyId, $questionCode, $answerCode)
    {
        $db = Yii::app()->db;

        // Esempio: G1Q00001_SQ001 → question group: G1Q00001 | subquestion: SQ001
        if (preg_match('/^([A-Z0-9]+)(?:_SQ([0-9]+))?/', $questionCode, $matches)) {
            $questionGroup = $matches[1];
            $subquestionCode = isset($matches[2]) ? 'SQ' . $matches[2] : null;

            $sql = "
                SELECT 
                    q.title AS question_code,
                    l.question AS question_text,
                    lbl.code AS answer_code,
                    lbltext.title AS answer_text
                FROM {{questions}} q
                LEFT JOIN {{question_l10ns}} l 
                    ON q.qid = l.qid AND l.language = 'en'
                LEFT JOIN {{answers}} lbl 
                    ON q.qid = lbl.qid AND lbl.code = :answerCode
                LEFT JOIN {{answer_l10ns}} lbltext 
                    ON lbl.qid = lbltext.qid AND lbl.code = lbltext.code AND lbltext.language = 'en'
                WHERE q.sid = :surveyId AND q.title = :questionGroup
                LIMIT 1
            ";

            $command = $db->createCommand($sql);
            $command->bindParam(":surveyId", $surveyId, PDO::PARAM_INT);
            $command->bindParam(":questionGroup", $questionGroup, PDO::PARAM_STR);
            $command->bindParam(":answerCode", $answerCode, PDO::PARAM_STR);

            $result = $command->queryRow();

            return $result ?: [];
        }

        return [];
    }

    private function httpPost($url, $jsonPayload)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $output = curl_exec($ch);
        if ($output === false) {
            $this->log("cURL error: " . curl_error($ch));
        }
        curl_close($ch);
        return $output;
    }

    private function debug($jsonPayload, $hookSent, $time_start)
    {
        if ($this->get('sBug', null, null, $this->settings['sBug']) == 1) {
            $html = '<pre><br><br>----------------------------- DEBUG ----------------------------- <br><br>';
            $html .= 'JSON Payload Sent: <br>' . htmlspecialchars($jsonPayload);
            $html .= "<br><br> Response: " . htmlspecialchars($hookSent);
            $html .= '<br>Total execution time in seconds: ' . (microtime(true) - $time_start);
            $html .= '</pre>';
            $event = $this->getEvent();
            $event->getContent($this)->addContent($html);
        }
    }
}
