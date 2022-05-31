<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class Common {
	private $connection;
	private $channelTX;
	private $channelRX;
	private $config;
	private $queueTX;
	private $queueRX;
	private $type;
	private $task;
	private $db;

	const TYPE_INITIATOR = 0;
	const TYPE_PERFORMER = 1;
	const RX = 0;
	const TX = 1;
	const QUEUE_PERFORMER_PREFIX = '.from-performer';
	const MSG_RECV = 'received';
	const MSG_SENT = 'sent';

	public function __construct($queue, $type = self::TYPE_INITIATOR) {
		try {
			$this->parseConfig('conf.ini');
			$this->connectDb();
		} catch (\Exception $e) {
			echo $e->getMessage();
			return;
		}

		$this->queueTX = $queue;
		$this->queueRX = $queue . self::QUEUE_PERFORMER_PREFIX;
		$this->type = $type;

		$this->connection = new AMQPStreamConnection(
			$this->config['HOST'],
			$this->config['PORT'],
			$this->config['USER'],
			$this->config['PWD'],
		);
		
		$this->newChannels();
	}

	public function __destruct() {
		$this->channelTX->close();
		$this->channelRX->close();
		$this->connection->close();
	}

	public function handleQueues() {
		$queues = $this->query('SELECT `name` FROM `queues`');
		if ($queues->num_rows) {
			$queues = $queues->fetch_all();
			$queues = array_column($queues, 0);
			foreach ($queues as $queue) {
				$this->task = $queue;
				switch ($queue) {
					case 'task-1':
						switch ($this->type) {
							case self::TYPE_INITIATOR:
								$digits = $this->query('SELECT * FROM `rand_numbers`');
								$digits = $digits->fetch_all();

								$this->sendMessage($digits);
								$this->basicConsume([$this, 'consumeCallback']);	
								break;
							case self::TYPE_PERFORMER:
								$this->basicConsume([$this, 'consumeCallback'], self::TX);	
								break;
						}
						break;
					case 'task-2':
						switch ($this->type) {
							case self::TYPE_INITIATOR:
								$url = 'https://www.opennet.ru/';
								$this->sendMessage($url);
								$this->basicConsume([$this, 'consumeCallback']);	
								break;
							case self::TYPE_PERFORMER:
								$this->basicConsume([$this, 'consumeCallback'], self::TX);	
								break;
						}
						break;
					case 'task-3':
						switch ($this->type) {
							case self::TYPE_INITIATOR:
								$n = 10;
								$this->sendMessage($n);
								$this->basicConsume([$this, 'consumeCallback']);	
								break;
							case self::TYPE_PERFORMER:
								$this->basicConsume([$this, 'consumeCallback'], self::TX);	
								break;
						}
						break;
					default:
						echo "\nhandle for [" . $queue . "] not found!\n";
				}
			}
		}
	}

	public function consumeCallback($msg) {
		switch($this->task) {
			case 'task-1':
				switch ($this->type) {
					case self::TYPE_INITIATOR:
						$response = json_decode($msg->body);

						foreach ($response as $digit) {
							$id = intval($digit[0]);
							$value = intval($digit[1]);
							$this->query("UPDATE `rand_numbers` SET `digit` = $value WHERE `id` = $id");
						}

						$updatedNumbers = $this->query("SELECT * FROM `rand_numbers`");

						if ($updatedNumbers->num_rows)
							$this->printMessage($updatedNumbers->fetch_all());
							
						$this->channelRX->stopConsume();
						break;
					case self::TYPE_PERFORMER:
						$response = json_decode($msg->body);
						$sort = array_column($response, 1);
						$sort = $this->quickSort($sort);
						array_walk($response, function(&$item, $key) use ($sort) {
							$item[1] = $sort[$key];
						});

						$this->channelTX->stopConsume();
						$this->sendMessage($response, self::RX);
						break;
				}
				break;
			case 'task-2':
				switch ($this->type) {
					case self::TYPE_INITIATOR:
						$this->printMessage($msg->body, self::MSG_RECV, false);
						$this->channelRX->stopConsume();
						break;
					case self::TYPE_PERFORMER:
						$url = json_decode($msg->body);

						$contents = file_get_contents($url);
						libxml_use_internal_errors(true);
						$doc = new DOMDocument();
						$doc->loadHTML($contents);
						$xpath = new DOMXpath($doc);
						$items = $xpath->query('//td[contains(@class, "tnews")]');

						$newsHeadings = [];
						$maxLimit = 10;
						$maxLimit = $items->count() > $maxLimit ? $maxLimit : $items->count();
						for ($i = 0; $i < $maxLimit; $i++) {
							$item = $items->item($i);
							if ($item->hasChildNodes())
								$item = $item->childNodes->item(0);
							$newsHeadings[] = $item->textContent; 
						}

						$this->channelTX->stopConsume();
						$this->sendMessage(json_encode($newsHeadings, JSON_UNESCAPED_UNICODE), self::RX);
						break;
				}
				break;
			case 'task-3':
				switch ($this->type) {
					case self::TYPE_INITIATOR:
						$this->printMessage($msg->body, self::MSG_RECV, false);
						$this->channelRX->stopConsume();
						break;
					case self::TYPE_PERFORMER:
						$n = json_decode($msg->body);
						$n = intval($n);

						$this->channelTX->stopConsume();
						$this->sendMessage(json_encode([
							'loop' => $this->factorial($n),
							'recursive' => $this->factorialRecursive($n)
						]), self::RX);
						
						break;
				}
				break;
			default:
				echo "\nhandle for [" . $this->task . "] not found!\n";
		}
	}

	private function factorial($n) {
		$fact = 1;
		for ($i = 1; $i <= $n; $i++) {
			$fact *= $i;
		}

		return $fact;
	}

	private function factorialRecursive($n) {
		return $n == 0 ? 1 : $n * $this->factorialRecursive($n - 1);
	}

	private function basicConsume($callback, $direction = self::RX) {
		$direction = $this->getDirection($direction);
		$direction['channel']->basic_consume($direction['queue'], '', false, true, false, false, $callback);
		$direction['channel']->consume();
	}

	private function sendMessage($data, $direction = self::TX) {
		$data = json_encode($data);
		$msg = new AMQPMessage($data);
		$direction = $this->getDirection($direction);
		$direction['channel']->basic_publish($msg, '', $direction['queue']);
		if ($this->type == self::TYPE_INITIATOR)
			$this->printMessage($data, self::MSG_SENT);
	}

	private function quickSort($array = []) {
		$loe = $gt = array();
		if (count($array) < 2)
			return $array;
		$pivot_key = key($array);
		$pivot = array_shift($array);
		foreach ($array as $val)
			if ($val <= $pivot)
				$loe[] = $val;
			elseif ($val > $pivot)
				$gt[] = $val;
		return array_merge($this->quickSort($loe),array($pivot_key=>$pivot),$this->quickSort($gt));
	}

	private function connectDb() {
		$this->db = new mysqli(
			$this->config['DB_HOST'],
			$this->config['DB_USER'],
			$this->config['DB_PWD'],
			$this->config['DB_NAME']
		);
		
		if ($this->db->connect_errno) {
			throw new Exception('mysqli error: ' . $mysqli->connect_error);
		}
	}

	private function newChannels() {
		$this->channelTX = $this->connection->channel();
		$this->channelTX->queue_declare($this->queueTX, false, false, false, false, true);
		$this->channelRX = $this->connection->channel();
		$this->channelRX->queue_declare($this->queueRX, false, false, false, false, true);
	}

	private function printMessage($data, $msgType = self::MSG_RECV, $json = true) {
		if ($json) {
			if (!(gettype($data) == 'string' && json_decode($data) && json_last_error() == JSON_ERROR_NONE)) {
				$data = json_encode($data);
			}
		} else {
			if (gettype($data) == 'string' && json_decode($data) && json_last_error() == JSON_ERROR_NONE) {
				$data = json_decode($data);
			} else {
				$data = print_r($data, true);
			}
		}

		echo "[*] Message $msgType { $data }\n";
	}

	private function getDirection($direction) {
		return $direction == self::TX
		? ['queue' => $this->queueTX, 'channel' => $this->channelTX]
		: ['queue' => $this->queueRX, 'channel' => $this->channelRX];
	}

	private function parseConfig($path) {
		$this->config = parse_ini_file($path);
		if ($this->config === false)
			throw new Exception('Fail parse "' . $path . '" file not exists or syntax error!');
	}

	private function query($sql) {
		return $this->db->query($sql);
	}
}