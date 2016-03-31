<?php
namespace Soukicz\SmsBrana;


/*
 *   (c) 2009-2013 Neogenia s.r.o.
 *   PHP trida, ktera odesila placene sms pres portal www.smsbrana.cz
 *   PHP 5 >= 5.3.0
 */


class Client {
    const AUTH_PLAIN = 1; //typ autentizace 1 - plain heslo (nedoporuceno)
    const AUTH_HASH = 2; //typ autentizace 2 - prenasen pouze kontrolni hash (doporuceno)
    private $apiScript = "http://api.smsbrana.cz/smsconnect/http.php"; //link na rozhrani API
    private $authType = 2;
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
     * @param string $loginOrHash
     * @param string|null $password
     */
    function __construct(\GuzzleHttp\Client $client, $loginOrHash, $password = null) {
        $this->httpClient = $client;
        $this->login = $loginOrHash;
        $this->password = $password;
        $this->authType = empty($password) ? self::AUTH_HASH : self::AUTH_PLAIN;
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
        if($this->authType == self::AUTH_PLAIN && (empty($this->login) || empty($this->password))) {
            throw new IOException('Missing auth parameters');
        } elseif($this->authType == self::AUTH_HASH && empty($this->login)) {
            throw new IOException('Missing auth parameters');
        } else {
            $resultArray = array();
            if($this->authType == self::AUTH_PLAIN) {
                $resultArray['login'] = $this->login;
                $resultArray['password'] = $this->password;
            } else {
                $salt = $this->salt(10);
                $time = date('Ymd') . 'T' . date('His');

                $resultArray['login'] = $this->login;
                $resultArray['sul'] = $salt;
                $resultArray['time'] = $time;
                $resultArray['hash'] = md5($this->password . $time . $salt);
            }
            return $resultArray;
        }
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
            throw new IOException('Sending error ' . (int)$response->err);
        }
        $list = [];
        foreach ($response->inbox->delivery_sms as $it) {
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
            throw new IOException('Sending error ' . (int)$response->err);
        }
        return [
            'id' => (string)$response->sms_id,
            'count' => (int)$response->sms_count,
        ];
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
