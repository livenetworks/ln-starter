<?php

namespace LiveNetworks\LnStarter\DTOs;

class Message implements \JsonSerializable
{
    public function __construct(
        public string $type,
        public string $title = '',
        public string $body = '',
        public array $data = []
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'type'  => $this->type,
            'title' => $this->title,
            'body'  => $this->body,
            'data'  => $this->data,
        ];
    }
}
