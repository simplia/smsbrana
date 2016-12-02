<?php
namespace Soukicz\SmsBrana;


/*
 *   (c) 2009-2013 Neogenia s.r.o.
 *   PHP trida, ktera odesila placene sms pres portal www.smsbrana.cz
 *   PHP 5 >= 5.3.0
 */


class Client {
    private $apiScript = "http://api.smsbrana.cz/smsconnect/http.php"; //link na rozhrani API
    private $login = null; //uzivatelske jmeno SMSconnectu
    private $password = null; //heslo SMSconnectu
    /**
     * @var null|\SimpleXMLElement
     */
    private $queue = null; //sms ktere jsou k odeslani

    /**
     * @var \GuzzleHttp\Client
     */
    protected $httpClient;

    /**
     * Client constructor.
     * @param \GuzzleHttp\Client $client
     * @param string $login
     * @param string|null $password
     */
    function __construct(\GuzzleHttp\Client $client, $login, $password) {
        $this->httpClient = $client;
        $this->login = $login;
        $this->password = $password;
        if(empty($this->login) || empty($this->password)) {
            throw new IOException('Missing auth parameters');
        }
        $this->create();
    }

    /*
     * Create new queue
     *
     */
    public function create() {
        $this->queue = new \SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><queue></queue>');

    }

    /*
     * Generate salt for access
     *
     * @param Int $delka the length of salt to be returned
     * @return String
     */
    private function salt($length) {
        $result = '';
        $source = array_merge(range('a', 'z'), range('A', 'Z'), range(0, 9), array(':'));

        for ($counter = 0; $counter < $length; $counter++) {
            $result .= $source[rand(0, count($source) - 1)];
        }
        return $result;
    }


    /*
     * Create URL attributes
     *
     * @return Array of login attributes | null if no login attributes are set
     */
    private function getAuthData() {
        $resultArray = array();
        $salt = $this->salt(10);
        $time = date('Ymd') . 'T' . date('His');

        $resultArray['login'] = $this->login;
        $resultArray['sul'] = $salt;
        $resultArray['time'] = $time;
        $resultArray['hash'] = md5($this->password . $time . $salt);

        return $resultArray;
    }

    /*
     * Try to output xml if $data in xml format, or else output raw $data
     *
     * @param String $data content of some URL
     *
     * @return String in xml format | String content of some URL
     */
    private function getAnswer($data) {
        $xmlSolid = simplexml_load_string($data);//Pokusí se vytvořit platný XML objekt se správnou strukturou

        if($xmlSolid === false || !$xmlSolid instanceof \SimpleXMLElement) {
            return $data;
        }
        return $xmlSolid->asXML();
    }

    /*
     * Get inbox SMS
     *
     * @return Message[]
     */
    public function inbox($delete = true) {
        $dataArray = $this->getAuthData();
        $dataArray['action'] = 'inbox';

        $response = new \SimpleXMLElement($this->getAnswer((string)$this->httpClient->get($this->apiScript, [
            'query' => array_merge($dataArray, ['delete' => $delete ? 1 : 0])
        ])->getBody()));
        if((int)$response->err > 0) {
            throw new IOException('Sending error ' . (int)$response->err . ': ' . $this->getErrorMessage((int)$response->err), (int)$response->err);
        }
        $list = [];
        foreach ($response->inbox->delivery_sms->item as $it) {
            if(empty($it) || strlen(trim($it->number)) === 0) {
                continue;
            }
            $message = new Message();
            $message->setNumber((string)$it->number);
            $message->setText((string)$it->message);
            $message->setDate(\DateTime::createFromFormat('Ymd His', str_replace('T', ' ', $it->time)));
            $list[] = $message;
        }
        return $list;
    }

    /*
     * Send 1 SMS
     *
     * @param String $number phone number of receiver
     * @param String $message message for receiver
     * @param String $time sending time
     * @param String $sender phone number of sender
     * @param String $delivery delivery report?
     *
     * @return array
     */
    public function send($number, $message, $time = "", $sender = "", $delivery = "") {
        $dataArray = $this->getAuthData();

        $dataArray['action'] = 'send_sms';
        $dataArray['number'] = $number;
        $dataArray['message'] = $message;
        $dataArray['when'] = $time;
        $dataArray['sender_id'] = $sender;
        $dataArray['delivery_report'] = $delivery;

        $response = new \SimpleXMLElement($this->getAnswer((string)$this->httpClient->get($this->apiScript, [
            'query' => $dataArray,
        ])->getBody()));
        if((int)$response->err > 0) {
            throw new IOException('Sending error err ' . (int)$response->err . ' (' . $number . '): ' . $this->getErrorMessage((int)$response->err), (int)$response->err);
        }
        return [
            'id' => (string)$response->sms_id,
            'count' => (int)$response->sms_count,
        ];
    }

    private function getErrorMessage($id) {
        if($id == 1) {
            return 'neznámá chyba';
        } elseif($id == 2) {
            return 'neplatný login';
        } elseif($id == 3) {
            return 'neplatný hash nebo password (podle varianty zabezpečení přihlášení)';
        } elseif($id == 4) {
            return 'neplatný time, větší odchylka času mezi servery než maximální akceptovaná v nastavení služby SMS Connect';
        } elseif($id == 5) {
            return 'nepovolená IP, viz nastavení služby SMS Connect';
        } elseif($id == 6) {
            return 'neplatný název akce';
        } elseif($id == 7) {
            return 'tato sul byla již jednou za daný den použita';
        } elseif($id == 8) {
            return 'nebylo navázáno spojení s databází';
        } elseif($id == 9) {
            return 'nedostatečný kredit';
        } elseif($id == 10) {
            return 'neplatné číslo příjemce SMS';
        } elseif($id == 11) {
            return 'prázdný text zprávy';
        } elseif($id == 12) {
            return 'SMS je delší než povolených 459 znaků';
        }
        return 'unknown error';
    }

    /*
     * Insert sms to queue (supposed xml object)
     *
     * @param String $number phone number of receiver
     * @param String $message message for receiver
     * @param String $time sending time
     * @param String $sender phone number of sender
     * @param String $delivery delivery report?
     *
     * @return true on success | false on fail
     */
    public function add_SMS($number, $message, $time = '', $sender = '', $delivery = '') {
        if(!is_null($this->queue)) {
            $sms = $this->queue->addChild('sms');
            $sms->addChild('number', $this->xmlEncode($number));
            $sms->addChild('message', $this->xmlEncode($message));
            $sms->addChild('when', $this->xmlEncode($time));
            $sms->addChild('sender_id', $this->xmlEncode($sender));
            $sms->addChild('delivery_report', $this->xmlEncode($delivery));
            return true;
        } else
            return false;
    }

    /*
     * Give queue to system
     *
     * @return String response body of the target page | false on no queue
     */
    public function sendAllSMS() {
        $dataArray = $this->getAuthData();
        if(!$this->queue->count()) {
            return false;
        }
        $dataArray['action'] = 'xml_queue';

        return $this->getAnswer((string)$this->httpClient->post($this->apiScript, [
            'query' => $dataArray,
            'xml' => $this->queue->asXML(),
        ])->getBody());
    }

    protected function xmlEncode($string) {
        return htmlspecialchars(preg_replace('#[\x00-\x08\x0B\x0C\x0E-\x1F]+#', '', $string), ENT_QUOTES);
    }
}
