<?php

declare(strict_types=1);

namespace App\Core;

/** Minimal rule-based validator. Returns a flat [field => message] error list. */
final class Validator
{
    private array $errors = [];

    public function __construct(private readonly array $data)
    {
    }

    /**
     * Record a failure the rules here cannot express.
     *
     * A date that has to parse, a code that has to exist: the check belongs
     * next to the thing that knows the rule, and its answer belongs in the
     * same list as everything else so the form renders one set of messages.
     *
     * First message per field wins, as with every rule above — the earliest
     * failure is the one that explains the rest.
     */
    public function addError(string $field, string $message): self
    {
        $this->errors[$field] ??= $message;

        return $this;
    }

    public function required(string $field, string $label): self
    {
        $value = $this->data[$field] ?? null;
        if ($value === null || trim((string) $value) === '') {
            $this->errors[$field] ??= "{$label} is required.";
        }

        return $this;
    }

    public function email(string $field, string $label): self
    {
        $value = $this->data[$field] ?? '';
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[$field] ??= "{$label} must be a valid email address.";
        }

        return $this;
    }

    public function numeric(string $field, string $label): self
    {
        $value = $this->data[$field] ?? '';
        if ($value !== '' && !is_numeric($value)) {
            $this->errors[$field] ??= "{$label} must be a number.";
        }

        return $this;
    }

    public function integerMin(string $field, string $label, int $min): self
    {
        $value = $this->data[$field] ?? '';
        if ($value !== '' && (!is_numeric($value) || (int) $value < $min)) {
            $this->errors[$field] ??= "{$label} must be at least {$min}.";
        }

        return $this;
    }

    public function maxLength(string $field, string $label, int $max): self
    {
        $value = (string) ($this->data[$field] ?? '');
        if (mb_strlen($value) > $max) {
            $this->errors[$field] ??= "{$label} must be {$max} characters or fewer.";
        }

        return $this;
    }

    public function minLength(string $field, string $label, int $min): self
    {
        $value = (string) ($this->data[$field] ?? '');
        if ($value !== '' && mb_strlen($value) < $min) {
            $this->errors[$field] ??= "{$label} must be at least {$min} characters.";
        }

        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
