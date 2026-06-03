<?php

namespace Administrator\Deno2\Core;

class Logger
{
    private string $logDir;

    public function __construct(string $module = 'app')
    {
        // Works both in web (DOCUMENT_ROOT set) and CLI contexts
        $root = $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 3);
        $this->logDir = rtrim($root, '/\\') . '/deno2/logs/' . $module . '/';
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->write('DEBUG', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0755, true);
        }
        $line = date('Y-m-d H:i:s') . " [$level] $message";
        if ($context) {
            $line .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        @file_put_contents(
            $this->logDir . date('Y-m-d') . '.log',
            $line . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
