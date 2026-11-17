<?php
declare(strict_types=1);

/** SMTP email (STARTTLS / SSL, AUTH LOGIN) without external libraries. */
final class EmailChannel
{
    public static function config(): array
    {
        $c = DB::one('SELECT * FROM email_configurations WHERE id = 1') ?? ['mode' => 'off'];
        $c['password'] = Crypto::decrypt($c['password_enc'] ?? null);
        return $c;
    }

    public static function send(array $job): array
    {
        $c = self::config();
        if ($c['mode'] === 'off') {
            return ['ok' => false, 'error' => 'Email channel is turned off', 'retry' => false];
        }
        if (!filter_var($job['recipient'], FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Invalid email address', 'retry' => false];
        }
        if ($c['mode'] === 'test') {
            return ['ok' => true, 'id' => 'test-' . $job['id'], 'simulated' => true];
        }
        try {
            $id = self::smtp($c, $job['recipient'], (string) ($job['recipient_name'] ?? ''), (string) $job['subject'], (string) $job['body']);
            return ['ok' => true, 'id' => $id];
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            // 4xx SMTP replies are temporary; 5xx permanent
            return ['ok' => false, 'error' => mb_substr($msg, 0, 400), 'retry' => !preg_match('/\b5\d\d\b/', $msg)];
        }
    }

    public static function smtp(array $c, string $to, string $toName, string $subject, string $text): string
    {
        $host = ($c['encryption'] === 'ssl' ? 'ssl://' : '') . $c['host'];
        $fp = @stream_socket_client($host . ':' . (int) $c['port'], $errno, $errstr, 15, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]));
        if (!$fp) {
            throw new RuntimeException("SMTP connect failed: $errstr");
        }
        stream_set_timeout($fp, 20);
        $read = static function () use ($fp): string {
            $out = '';
            while (($line = fgets($fp, 515)) !== false) {
                $out .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $out;
        };
        $cmd = static function (string $c, array $ok) use ($fp, $read): string {
            fwrite($fp, $c . "\r\n");
            $r = $read();
            if (!in_array((int) substr($r, 0, 3), $ok, true)) {
                throw new RuntimeException('SMTP error after ' . explode(' ', $c)[0] . ': ' . trim($r));
            }
            return $r;
        };
        $read();
        $ehlo = parse_url(Config::get('app_url', 'http://localhost'), PHP_URL_HOST) ?: 'localhost';
        $cmd("EHLO $ehlo", [250]);
        if ($c['encryption'] === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('STARTTLS negotiation failed');
            }
            $cmd("EHLO $ehlo", [250]);
        }
        if ($c['username']) {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($c['username']), [334]);
            $cmd(base64_encode((string) $c['password']), [235]);
        }
        $from = $c['from_email'] ?: $c['username'];
        $cmd("MAIL FROM:<$from>", [250]);
        $cmd("RCPT TO:<$to>", [250, 251]);
        $cmd('DATA', [354]);
        $msgId = bin2hex(random_bytes(12)) . '@' . $ehlo;
        $enc = static fn(string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.6;color:#222">' . nl2br(htmlspecialchars($text)) . '</div>';
        $boundary = 'b' . bin2hex(random_bytes(8));
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $enc($c['from_name'] ?: 'ClassNex') . " <$from>",
            'To: ' . ($toName ? $enc($toName) . ' ' : '') . "<$to>",
            'Subject: ' . $enc($subject),
            "Message-ID: <$msgId>",
            'MIME-Version: 1.0',
            "Content-Type: multipart/alternative; boundary=\"$boundary\"",
        ];
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text)) .
            "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "--$boundary--";
        $data = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $data = preg_replace('/^\./m', '..', $data);
        $cmd($data . "\r\n.", [250]);
        $cmd('QUIT', [221, 250]);
        fclose($fp);
        return $msgId;
    }
}
