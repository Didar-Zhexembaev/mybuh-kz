<?php

require_once 'Common.php';

$initiator = new Common('mybuh', Common::TYPE_PERFORMER);

$initiator->handleQueues();