<?php

namespace {namespace}\controllers;

use kuiper\di\annotation\Controller;
use kuiper\web\AbstractController;
use kuiper\web\annotation\GetMapping;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * @Controller()
 */
class IndexController extends AbstractController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @GetMapping("/")
     */
    public function hello()
    {
        $this->response->getBody()->write("Hello, " . ($this->request->getQueryParams()['name'] ?? 'tars'));
    }
}