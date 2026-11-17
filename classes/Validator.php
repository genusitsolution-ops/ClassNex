<?php
declare(strict_types=1);

/**
 * Small validation helper.
 *   $v = Validator::make($input, ['name' => 'required|max:120', 'email' => 'email', 'dob' => 'date']);
 *   $clean = $v->validated();
 * Rules: required, max:n, min:n, email, date, time, int, money, in:a,b, phone, bool, url
 */
final class Validator
{
    private array $errors = [];
    private array $clean = [];

    private function __construct(private array $data, private array $rules, private array $labels = [])
    {
    }

    public static function make(array $data, array $rules, array $labels = []): self
    {
        $v = new self($data, $rules, $labels);
        $v->run();
        return $v;
    }

    private function label(string $f): string
    {
        return $this->labels[$f] ?? ucfirst(str_replace('_', ' ', $f));
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleStr) {
            $rules = explode('|', $ruleStr);
            $raw = $this->data[$field] ?? null;
            $val = is_string($raw) ? trim($raw) : $raw;
            $empty = $val === null || $val === '' || $val === [];
            if ($empty) {
                if (in_array('required', $rules, true)) {
                    $this->errors[$field] = $this->label($field) . ' is required.';
                } elseif (array_key_exists($field, $this->data)) {
                    $this->clean[$field] = in_array('bool', $rules, true) ? 0 : null;
                }
                continue;
            }
            foreach ($rules as $r) {
                [$name, $arg] = array_pad(explode(':', $r, 2), 2, null);
                $err = $this->check($name, $arg, $val);
                if ($err !== null) {
                    $this->errors[$field] = $this->label($field) . ' ' . $err;
                    continue 2;
                }
                $val = $this->cast($name, $val);
            }
            $this->clean[$field] = $val;
        }
    }

    private function check(string $name, ?string $arg, mixed $v): ?string
    {
        $s = is_scalar($v) ? (string) $v : '';
        return match ($name) {
            'max'   => mb_strlen($s) > (int) $arg ? "must be at most $arg characters." : null,
            'min'   => mb_strlen($s) < (int) $arg ? "must be at least $arg characters." : null,
            'email' => filter_var($s, FILTER_VALIDATE_EMAIL) ? null : 'is not a valid email address.',
            'url'   => filter_var($s, FILTER_VALIDATE_URL) ? null : 'is not a valid URL.',
            'date'  => (($d = DateTime::createFromFormat('Y-m-d', $s)) && $d->format('Y-m-d') === $s) ? null : 'must be a valid date.',
            'time'  => preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $s) ? null : 'must be a valid time.',
            'int'   => filter_var($s, FILTER_VALIDATE_INT) !== false ? null : 'must be a whole number.',
            'money' => preg_match('/^\d+(\.\d{1,2})?$/', str_replace([',', '₹', ' '], '', $s)) ? null : 'must be a valid amount.',
            'in'    => in_array($s, explode(',', (string) $arg), true) ? null : 'has an invalid value.',
            'phone' => preg_match('/^[\d\s\-+()]{6,20}$/', $s) ? null : 'is not a valid number.',
            default => null,
        };
    }

    private function cast(string $name, mixed $v): mixed
    {
        return match ($name) {
            'int'   => (int) $v,
            'money' => Money::toPaise($v),
            'bool'  => filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'email' => strtolower((string) $v),
            default => $v,
        };
    }

    public function fails(): bool
    {
        return (bool) $this->errors;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function validated(): array
    {
        if ($this->errors) {
            throw ApiException::validation($this->errors);
        }
        return $this->clean;
    }
}
