<?php
declare(strict_types=1);

final class ApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400, public readonly array $errors = [], public readonly ?string $errorCode = null)
    {
        parent::__construct($message, $status);
    }

    public static function validation(array $errors): self
    {
        return new self(reset($errors) ?: 'Please check the highlighted fields.', 422, $errors, 'validation');
    }

    public static function notFound(string $what = 'Record'): self
    {
        return new self("$what not found.", 404, [], 'not_found');
    }

    public static function forbidden(string $msg = 'You do not have permission to do this.'): self
    {
        return new self($msg, 403, [], 'forbidden');
    }
}
