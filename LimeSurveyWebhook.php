<?php

/***** ***** ***** ***** *****
* Send a JSON POST request after each afterSurveyComplete event
*
* @originalauthor Stefan Verweij <stefan@evently.nl>
* @copyright 2016 Evently
* @author IrishWolf, updated by Alex Righetto!
* @version 2.0.0
***** ***** ***** ***** *****/

class LimeSurveyWebhook extends PluginBase
{
    protected $storage = 'DbStorage';
    static protected $description = 'Webhook for LimeSurvey (JSON payload with pretty answers)';
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
    Yii::import('application.helpers.export_helper');
    require_once APPPATH . 'helpers/export_helper.php';

    $oEvent = $this->getEvent();
    $surveyId = $oEvent->get('surveyId');
    $hookSurveyId = $this->get('sId', null, null, $this->settings['sId']);

    // Controllo se il survey è quello giusto
    if (is_array($hookSurveyId)) {
        $hookSurveyIdArray = array_map('trim', $hookSurveyId);
    } else {
        $hookSurveyIdArray = explode(',', preg_replace('/\s+/', '', $hookSurveyId));
    }

    if (in_array($surveyId, $hookSurveyIdArray)) {

        // Qui raccogli le risposte in formato pulito con le etichette
        $responseId = $oEvent->get('response')['id']; // L'ID della risposta appena salvata
        $oSurvey = Survey::model()->findByPk($surveyId);

        $responseData = responseExportData(
            $surveyId,
            [$responseId],
            $oSurvey->language,
            'json',     // formato export
            'short',    // head format (short o full)
            'code'      // valore delle risposte in "code"
        );

        $this->debugVar($responseData); // Vedrai l'output nel debug log

        // E poi chiami il webhook normalmente
        $this->callWebhook('afterSurveyComplete');
    }
}

    private function callWebhook($comment)
    {
      
        $time_start = microtime(true);
        $event = $this->getEvent();
        $surveyId = $event->get('surveyId');
        $responseId = $event->get('responseId');

        // Risposte grezze
        $response = $this->pluginManager->getAPI()->getResponse($surveyId, $responseId);
        $submitDate = $response['submitdate'];

        if (empty($submitDate) || $submitDate == '1980-01-01 00:00:00') {
            $submitDate = date('Y-m-d H:i:s');
        }

        // Prendi il token
        $token = isset($response['token']) ? $response['token'] : null;

        // Partecipante (se esiste un token)
        $participant = null;
        if (!empty($token)) {
            $query = "SELECT firstname, lastname, email FROM {{tokens_$surveyId}} WHERE token = :token LIMIT 1";
            $participant = Yii::app()->db->createCommand($query)
                ->bindParam(":token", $token, PDO::PARAM_STR)
                ->queryRow();
        }

        $url = $this->get('sUrl', null, null, $this->settings['sUrl']);
        $auth = $this->get('sAuthToken', null, null, $this->settings['sAuthToken']);

        // ======= AGGIUNTA IMPORTANTE: recupero risposte "pretty" =========
        Yii::import('application.helpers.export_helper');
        require_once APPPATH . 'helpers/export_helper.php';
        
        $language = $response['startlanguage'] ?? 'en';
        $responsePretty = responseExportData(
            $surveyId,
            [$responseId],
            $language,
            'json',
            'short',
            'code'
        );

        $parameters = array(
            "api_token" => $auth,
            "survey" => $surveyId,
            "event" => $comment,
            "respondId" => $responseId,
            "response" => $response,               // Risposte grezze
            "response_pretty" => $responsePretty,   // Risposte leggibili
            "submitDate" => $submitDate,
            "token" => $token,
            "participant" => $participant
        );

        // JSON pulito
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
