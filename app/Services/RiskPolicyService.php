<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

class RiskPolicyService
{
    private $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?: (string)config('submesh.risk_policy_path');
    }

    public function replace(array $payload): array
    {
        $policy = $this->normalize($payload);
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create risk policy directory.');
        }

        $lock = fopen($this->path . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Unable to open risk policy lock.');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Unable to lock risk policy.');
            }
            try {
                $current = $this->readFile();
            } catch (UnexpectedValueException $exception) {
                // A valid signed snapshot is authoritative and can repair a corrupt local file.
                $current = null;
            }
            if ($current !== null) {
                if ($policy['version'] < $current['version']) {
                    throw new UnexpectedValueException('Risk policy version rollback rejected.');
                }
                if ($policy['version'] === $current['version']) {
                    if ($policy !== $current) {
                        throw new UnexpectedValueException('Risk policy version conflict.');
                    }
                    return $current;
                }
            }

            $json = json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new RuntimeException('Unable to encode risk policy.');
            }
            $temporary = $this->path . '.tmp.' . bin2hex(random_bytes(8));
            try {
                if (file_put_contents($temporary, $json . "\n", LOCK_EX) === false) {
                    throw new RuntimeException('Unable to write risk policy.');
                }
                @chmod($temporary, 0640);
                if (!rename($temporary, $this->path)) {
                    throw new RuntimeException('Unable to replace risk policy.');
                }
            } finally {
                if (isset($temporary) && is_file($temporary)) {
                    @unlink($temporary);
                }
            }
            return $policy;
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function current(): ?array
    {
        $policy = $this->readFile();
        if ($policy === null && strlen((string)config('submesh.risk_policy_secret', '')) >= 32) {
            throw new RuntimeException('Risk policy has not been synchronized.');
        }
        return $policy;
    }

    public function targetEmailFor(string $email): ?string
    {
        $policy = $this->current();
        if ($policy === null || !$policy['enabled']) {
            return null;
        }
        $email = strtolower(trim($email));
        return in_array($email, $policy['emails'], true) ? $policy['target_email'] : null;
    }

    public function isRiskEmail(string $email): bool
    {
        $policy = $this->current();
        if ($policy === null) {
            return false;
        }
        return in_array(strtolower(trim($email)), $policy['emails'], true);
    }

    private function readFile(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }
        $raw = file_get_contents($this->path);
        if ($raw === false) {
            throw new RuntimeException('Unable to read risk policy.');
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            throw new UnexpectedValueException('Stored risk policy is invalid.');
        }
        try {
            return $this->normalize($payload);
        } catch (InvalidArgumentException $exception) {
            throw new UnexpectedValueException('Stored risk policy is invalid.', 0, $exception);
        }
    }

    private function normalize(array $payload): array
    {
        foreach (['version', 'enabled', 'target_email', 'emails', 'updated_at'] as $field) {
            if (!array_key_exists($field, $payload)) {
                throw new InvalidArgumentException("Missing risk policy field: {$field}");
            }
        }
        if (!is_int($payload['version']) || $payload['version'] < 1) {
            throw new InvalidArgumentException('Invalid risk policy version.');
        }
        if (!is_bool($payload['enabled'])) {
            throw new InvalidArgumentException('Invalid risk policy enabled flag.');
        }
        if (!is_string($payload['target_email']) || !is_array($payload['emails']) || !is_string($payload['updated_at'])) {
            throw new InvalidArgumentException('Invalid risk policy field types.');
        }
        if (count($payload['emails']) > (int)config('submesh.risk_policy_max_emails', 10000)) {
            throw new InvalidArgumentException('Risk policy contains too many emails.');
        }

        $target = $this->normalizeEmail($payload['target_email'], !$payload['enabled']);
        if ($payload['enabled'] && $target === '') {
            throw new InvalidArgumentException('Target email is required when risk policy is enabled.');
        }
        if ($payload['updated_at'] === '' || strlen($payload['updated_at']) > 64 || strtotime($payload['updated_at']) === false) {
            throw new InvalidArgumentException('Invalid risk policy updated_at.');
        }

        $emails = [];
        foreach ($payload['emails'] as $email) {
            if (!is_string($email)) {
                throw new InvalidArgumentException('Invalid risk email.');
            }
            $emails[] = $this->normalizeEmail($email, false);
        }
        $emails = array_values(array_unique($emails));
        sort($emails, SORT_STRING);
        if ($target !== '' && in_array($target, $emails, true)) {
            throw new InvalidArgumentException('Target email cannot be included in the risk list.');
        }

        return [
            'version' => $payload['version'],
            'enabled' => $payload['enabled'],
            'target_email' => $target,
            'emails' => $emails,
            'updated_at' => $payload['updated_at'],
        ];
    }

    private function normalizeEmail(string $email, bool $allowEmpty): string
    {
        $email = strtolower(trim($email));
        if ($allowEmpty && $email === '') {
            return '';
        }
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid email in risk policy.');
        }
        return $email;
    }
}
