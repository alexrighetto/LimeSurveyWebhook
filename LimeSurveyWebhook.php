<?php

/***** ***** ***** ***** *****
* Send a JSON POST request after each afterSurveyComplete event
*
* @originalauthor Stefan Verweij <stefan@evently.nl>
* @copyright 2016 Evently
* @author IrishWolf, updated by Alex Righetto
* @version 1.2.0
***** ***** ***** ***** *****/

class LimeSurveyWebhook extends PluginBase
{
    protected $storage = 'DbStorage';
    static protected $description = 'A simple Webhook for LimeSurvey (JSON payload)';
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
            'help' => 'To test get one from https://webhook.site'
        ),
        'sId' => array(
            'type' => 'string',
            'default' => '000000',
            'label' => 'The ID of the surveys:',
            'help' => 'You can set multiple surveys separated by ","'
        ),
        'sAuthToken' => array(
            'type' => 'string',
            'label' => 'API Authentication Token',
            'help' => 'Token sent in plain text (not encoded)'
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

        if (is_array($hookSurveyId)) {
            $hookSurveyIdArray = array_map('trim', $hookSurveyId);
        } else {
            $hookSurveyIdArray = explode(',', preg_replace('/\s+/', '', $hookSurveyId));
        }

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

        $response = $this->pluginManager->getAPI()->getResponse($surveyId, $responseId);
        $submitDate = $response['submitdate'];

        // Correzione data
        if (empty($submitDate) || $submitDate == '1980-01-01 00:00:00') {
            $submitDate = date('Y-m-d H:i:s');
        }

        // Token
        $token = isset($response['token']) ? $response['token'] : null;

        // Dati partecipante
        $participant = null;
        if (!empty($token)) {
            $query = "SELECT firstname, lastname, email FROM {{tokens_$surveyId}} WHERE token = :token LIMIT 1";
            $participant = Yii::app()->db->createCommand($query)
                ->bindParam(":token", $token, PDO::PARAM_STR)
                ->queryRow();
        }

        // Recupera le domande e le opzioni
        $sql = "SELECT q.qid, q.sid, q.parent_qid, q.type, q.title AS question_code, ql.question AS question_text, ql.language
                FROM {{questions}} q 
                LEFT JOIN {{question_l10ns}} ql ON q.qid = ql.qid 
                WHERE q.sid = :surveyid";
        $rows = Yii::app()->db->createCommand($sql)
            ->bindParam(":surveyid", $surveyId, PDO::PARAM_INT)
            ->queryAll();

        $questions = [];
        $choices = [];

        foreach ($rows as $row) {
            if ($row['parent_qid'] == 0) {
                $questions[] = array(
                    'qid' => $row['qid'],
                    'sid' => $row['sid'],
                    'parent_qid' => $row['parent_qid'],
                    'type' => $row['type'],
                    'question_code' => $row['question_code'],
                    'question_text' => $row['question_text'],
                    'language' => $row['language']
                );
            } else {
                $choices[] = array(
                    'qid' => $row['qid'],
                    'sid' => $row['sid'],
                    'parent_qid' => $row['parent_qid'],
                    'type' => $row['type'],
                    'answer_code' => $row['question_code'],
                    'answer_text' => $row['question_text'],
                    'language' => $row['language']
                );
            }
        }

        $url = $this->get('sUrl', null, null, $this->settings['sUrl']);
        $auth = $this->get('sAuthToken', null, null, $this->settings['sAuthToken']);

        $parameters = array(
            "api_token" => $auth,
            "survey" => $surveyId,
            "event" => $comment,
            "respondId" => $responseId,
            "response" => $response,
            "submitDate" => $submitDate,
            "token" => $token,
            "participant" => $participant,
            "questions" => $questions,
            "choices" => $choices
        );

        $payload = json_encode($parameters);
        $hookSent = $this->httpPost($url, $payload);

        $this->log($comment . " | JSON Payload: " . $payload . " | Response: " . $hookSent);
        $this->debug($url, $parameters, $hookSent, $time_start, $response, $comment);
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

    private function debug($url, $parameters, $hookSent, $time_start, $response, $comment)
    {
        if ($this->get('sBug', null, null, $this->settings['sBug']) == 1) {
            $this->log($comment);
            $html = '<pre><br><br>----------------------------- DEBUG ----------------------------- <br><br>';
            $html .= 'Parameters: <br>' . htmlspecialchars(json_encode($parameters, JSON_PRETTY_PRINT));
            $html .= "<br><br> ----------------------------- <br><br>";
            $html .= 'Hook sent to: ' . htmlspecialchars($url) . '<br>';
            $html .= 'Response: ' . htmlspecialchars($hookSent) . '<br>';
            $html .= 'Total execution time in seconds: ' . (microtime(true) - $time_start);
            $html .= '</pre>';
            $event = $this->getEvent();
            $event->getContent($this)->addContent($html);
        }
    }
}
