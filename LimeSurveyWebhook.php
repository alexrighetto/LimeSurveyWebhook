<?php

class LimeSurveyWebhook extends PluginBase
{
    protected $storage = 'DbStorage';
    static protected $description = 'Webhook for LimeSurvey (JSON payload with pretty answers)';
    static protected $name = 'LimeSurveyWebhook';

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
            'label' => 'Enable Debug Mode'
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

        // Risposta grezza
        $response = $this->pluginManager->getAPI()->getResponse($surveyId, $responseId);

        // Correggi submit date se mancante
        $submitDate = $response['submitdate'];
        if (empty($submitDate) || $submitDate == '1980-01-01 00:00:00') {
            $submitDate = date('Y-m-d H:i:s');
        }

        // Recupera token e dati partecipante se esistono
        $token = isset($response['token']) ? $response['token'] : null;
        $participant = null;
        if (!empty($token)) {
            $query = "SELECT firstname, lastname, email FROM {{tokens_$surveyId}} WHERE token = :token LIMIT 1";
            $participant = Yii::app()->db->createCommand($query)
                ->bindParam(":token", $token, PDO::PARAM_STR)
                ->queryRow();
        }

        // ------ Mapping PRETTY ------
        $oSurvey = Survey::model()->findByPk($surveyId);
        $language = $response['startlanguage'] ?? $oSurvey->language;

        Yii::import('application.helpers.export_helper');

        // Crea fieldmap con etichette
        $fieldMap = createFieldMap($oSurvey, 'full', false, false, $language);

        // Mappa le risposte
        $responsePretty = [];
        foreach ($response as $field => $value) {
            if (isset($fieldMap[$field])) {
                $questionText = $fieldMap[$field]['question'] ?? $field;
                $responsePretty[$questionText] = $value;
            }
        }

        // ------ Fine mapping ------

        $url = $this->get('sUrl', null, null, $this->settings['sUrl']);
        $auth = $this->get('sAuthToken', null, null, $this->settings['sAuthToken']);

        $parameters = array(
            "api_token" => $auth,
            "survey" => $surveyId,
            "event" => $comment,
            "respondId" => $responseId,
            "response" => $response,
            "response_pretty" => $responsePretty,
            "submitDate" => $submitDate,
            "token" => $token,
            "participant" => $participant
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
            $html = '<pre><br>---------------- DEBUG ---------------- <br>';
            $html .= 'Payload: <br>' . htmlspecialchars(json_encode($parameters, JSON_PRETTY_PRINT));
            $html .= '<br>Hook URL: ' . htmlspecialchars($url);
            $html .= '<br>Response: ' . htmlspecialchars($hookSent);
            $html .= '<br>Execution time: ' . (microtime(true) - $time_start) . 's';
            $html .= '</pre>';
            $event = $this->getEvent();
            $event->getContent($this)->addContent($html);
        }
    }
}
