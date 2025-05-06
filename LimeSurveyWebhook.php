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

    // Prendi il token
    $token = isset($response['token']) ? $response['token'] : null;

    // Recupera dati partecipante
    $participant = null;
    if (!empty($token)) {
        $query = "SELECT firstname, lastname, email FROM {{tokens_$surveyId}} WHERE token = :token LIMIT 1";
        $participant = Yii::app()->db->createCommand($query)
            ->bindParam(":token", $token, PDO::PARAM_STR)
            ->queryRow();
    }

    // ---  Recupera tutte le domande e sotto-domande per il survey
    $questionsRaw = Yii::app()->db->createCommand()
        ->select('*')
        ->from('{{questions}}')
        ->where('sid = :sid', array(':sid' => $surveyId))
        ->queryAll();

    // ---  Costruisce la mappa domanda -> risposte
    $questions = [];
    foreach ($questionsRaw as $q) {
        if (!isset($questions[$q['parent_qid']])) {
            $questions[$q['parent_qid']] = [];
        }
        $questions[$q['parent_qid']][] = $q;
    }

    // ---  Crea la lista leggibile delle risposte date
    $answersReadable = [];

    foreach ($response as $code => $value) {
        // Skip campi di sistema
        if (in_array($code, ['id', 'token', 'submitdate', 'startlanguage', 'seed', 'startdate', 'datestamp', 'ipaddr', 'refurl', 'lastpage'])) {
            continue;
        }

        if ($value === '' || $value === null) {
            continue; // Non risposto
        }

        // Trova la domanda padre
        $questionText = null;
        $answerText = null;

        // Se il campo è tipo "G1Q00001_SQ001" (subquestion)
        if (preg_match('/^([A-Z0-9]+)(_SQ[0-9]+)?(_[0-9]+)?$/', $code, $matches)) {

            $questionCode = $matches[1];
            $subCode = isset($matches[2]) ? substr($matches[2], 1) : null; // SQ001 -> 001
            $rankCode = isset($matches[3]) ? substr($matches[3], 1) : null; // per ranking (non usato ora)

            // Trova domanda principale
            $mainQuestion = null;
            foreach ($questions[0] as $q) {
                if ($q['question_code'] == $questionCode) {
                    $mainQuestion = $q;
                    break;
                }
            }

            if (!$mainQuestion) {
                continue; // Codice domanda non trovato
            }

            $questionText = $mainQuestion['question_text'];

            // Se ha subquestion
            if ($subCode) {
                $subQuestion = null;
                foreach ($questions[$mainQuestion['qid']] as $sq) {
                    if ($sq['question_code'] == 'SQ' . $subCode) {
                        $subQuestion = $sq;
                        break;
                    }
                }
                if ($subQuestion) {
                    $answerText = $subQuestion['question_text'];
                } else {
                    $answerText = $value; // fallback
                }

            } elseif (preg_match('/^SQ[0-9]+$/', $value)) {
                // Caso ranking o codice selezionato (es: SQ045)
                $subQuestion = null;
                foreach ($questions[$mainQuestion['qid']] as $sq) {
                    if ($sq['question_code'] == $value) {
                        $subQuestion = $sq;
                        break;
                    }
                }
                if ($subQuestion) {
                    $answerText = $subQuestion['question_text'];
                } else {
                    $answerText = $value;
                }

            } else {
                // Domanda con risposta aperta o yes/no
                if ($value == 'Y') {
                    $answerText = "Yes";
                } elseif ($value == 'N') {
                    $answerText = "No";
                } else {
                    $answerText = $value;
                }
            }

            // --- Aggiungi alla lista finale
            $answersReadable[] = [
                'question' => $questionText,
                'answer' => $answerText
            ];
        }
    }

    // ---  Prepara il JSON finale
    $url = $this->get('sUrl', null, null, $this->settings['sUrl']);
    $auth = $this->get('sAuthToken', null, null, $this->settings['sAuthToken']);

    $parameters = array(
        "api_token" => $auth,
        "survey" => $surveyId,
        "event" => $comment,
        "respondId" => $responseId,
        "submitDate" => $submitDate,
        "token" => $token,
        "participant" => $participant,
        "answers" => $answersReadable
    );

    $payload = json_encode($parameters, JSON_PRETTY_PRINT);
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
