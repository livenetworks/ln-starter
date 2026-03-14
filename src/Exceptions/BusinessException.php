<?php

namespace LiveNetworks\LnStarter\Exceptions;

use Exception;

class BusinessException extends Exception
{
    public function __construct(
        string $message,
        public string $title = 'Error',
        int $code = 400
    ) {
        $this->title = __($title);
        parent::__construct($message, $code);
    }
}
