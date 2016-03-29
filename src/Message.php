<?php
namespace Soukicz\SmsBrana;

class Message {
    protected $number;
    protected $text;

    /**
     * @return string
     */
    public function getNumber() {
        return $this->number;
    }

    /**
     * @param string $number
     * @return Message
     */
    public function setNumber($number) {
        $this->number = $number;
        return $this;
    }

    /**
     * @return string
     */
    public function getText() {
        return $this->text;
    }

    /**
     * @param string $text
     * @return Message
     */
    public function setText($text) {
        $this->text = $text;
        return $this;
    }

    /**
     * @return \DateTime
     */
    public function getDate() {
        return $this->date;
    }

    /**
     * @param \DateTime $date
     * @return Message
     */
    public function setDate($date) {
        $this->date = $date;
        return $this;
    }

    /**
     * @var \DateTime
     */
    protected $date;
}
