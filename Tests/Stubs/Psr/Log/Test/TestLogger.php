<?php
namespace Psr\Log\Test;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
class TestLogger implements LoggerInterface {
    public array $records = [];
    public array $recordsByLevel = [];
    public function log($level, $message, array $context = []): void {
        $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
        $this->recordsByLevel[$level][] = ['message' => $message, 'context' => $context];
    }
    public function emergency($message, array $context = []): void { $this->log(LogLevel::EMERGENCY, $message, $context); }
    public function alert($message, array $context = []): void { $this->log(LogLevel::ALERT, $message, $context); }
    public function critical($message, array $context = []): void { $this->log(LogLevel::CRITICAL, $message, $context); }
    public function error($message, array $context = []): void { $this->log(LogLevel::ERROR, $message, $context); }
    public function warning($message, array $context = []): void { $this->log(LogLevel::WARNING, $message, $context); }
    public function notice($message, array $context = []): void { $this->log(LogLevel::NOTICE, $message, $context); }
    public function info($message, array $context = []): void { $this->log(LogLevel::INFO, $message, $context); }
    public function debug($message, array $context = []): void { $this->log(LogLevel::DEBUG, $message, $context); }
    public function hasRecords($level): bool { return !empty($this->recordsByLevel[$level]); }
    public function hasRecord($message, $level): bool { foreach ($this->recordsByLevel[$level] ?? [] as $r) if ($r['message'] === $message) return true; return false; }
    public function __call($name, $args) {
        // handle hasInfoThatContains, hasWarningThatContains etc.
        if (preg_match('/^has(\w+)ThatContains$/', $name, $m)) {
            $level = strtolower($m[1]);
            // map Info -> info, Warning -> warning etc.
            $levelMap = ['info'=>LogLevel::INFO,'warning'=>LogLevel::WARNING,'error'=>LogLevel::ERROR,'debug'=>LogLevel::DEBUG,'notice'=>LogLevel::NOTICE,'alert'=>LogLevel::ALERT,'critical'=>LogLevel::CRITICAL,'emergency'=>LogLevel::EMERGENCY];
            $lvl = $levelMap[$level] ?? $level;
            $substr = $args[0] ?? '';
            foreach ($this->recordsByLevel[$lvl] ?? [] as $r) {
                if (str_contains((string)$r['message'], (string)$substr)) return true;
                // also check context
                foreach ($r['context'] as $v) if (is_string($v) && str_contains($v, $substr)) return true;
            }
            return false;
        }
        if (preg_match('/^has(\w+)Records$/', $name, $m)) {
            $level = strtolower($m[1]);
            $levelMap = ['info'=>LogLevel::INFO,'warning'=>LogLevel::WARNING,'error'=>LogLevel::ERROR,'debug'=>LogLevel::DEBUG];
            $lvl = $levelMap[$level] ?? $level;
            return !empty($this->recordsByLevel[$lvl]);
        }
        throw new \BadMethodCallException("Method $name not implemented in stub TestLogger");
    }
}
