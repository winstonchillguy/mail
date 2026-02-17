<?php
$feedback = null;
$feedbackType = null;
$debugDetails = null;

$fromAddress = getenv('MAIL_FROM_ADDRESS') ?: 'no-reply@example.com';
$envelopeFrom = getenv('MAIL_ENVELOPE_FROM') ?: $fromAddress;
$mailTransport = strtolower(getenv('MAIL_TRANSPORT') ?: 'mail');

function smtpExpect($socket, array $expectedCodes, &$debugLog)
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    if ($response === '') {
        $debugLog[] = 'SMTP: No response from server.';
        return false;
    }

    $code = (int) substr($response, 0, 3);
    $debugLog[] = 'SMTP <= ' . trim($response);

    return in_array($code, $expectedCodes, true);
}

function smtpCommand($socket, $command, array $expectedCodes, &$debugLog)
{
    $debugLog[] = 'SMTP => ' . $command;
    fwrite($socket, $command . "\r\n");
    return smtpExpect($socket, $expectedCodes, $debugLog);
}

function sendViaSmtp($recipient, $subject, $message, $fromAddress, $envelopeFrom, $replyToName, $replyToAddress, &$debugDetails)
{
    $host = getenv('SMTP_HOST') ?: '';
    $port = (int) (getenv('SMTP_PORT') ?: 587);
    $username = getenv('SMTP_USERNAME') ?: '';
    $password = getenv('SMTP_PASSWORD') ?: '';
    $encryption = strtolower(getenv('SMTP_ENCRYPTION') ?: 'tls');
    $timeout = 10;
    $debugLog = [];

    if ($host === '') {
        $debugDetails = 'SMTP_HOST is empty.';
        return false;
    }

    $target = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $socket = @stream_socket_client($target, $errno, $errstr, $timeout);

    if (!$socket) {
        $debugDetails = 'SMTP connect failed: ' . $errstr . ' (' . $errno . ').';
        return false;
    }

    stream_set_timeout($socket, $timeout);

    if (!smtpExpect($socket, [220], $debugLog)) {
        fclose($socket);
        $debugDetails = implode("\n", $debugLog);
        return false;
    }

    $hostname = gethostname() ?: 'localhost';
    if (!smtpCommand($socket, 'EHLO ' . $hostname, [250], $debugLog)) {
        fclose($socket);
        $debugDetails = implode("\n", $debugLog);
        return false;
    }

    if ($encryption === 'tls') {
        if (!smtpCommand($socket, 'STARTTLS', [220], $debugLog)) {
            fclose($socket);
            $debugDetails = implode("\n", $debugLog);
            return false;
        }

        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            $debugDetails = 'SMTP TLS negotiation failed.';
            return false;
        }

        if (!smtpCommand($socket, 'EHLO ' . $hostname, [250], $debugLog)) {
            fclose($socket);
            $debugDetails = implode("\n", $debugLog);
            return false;
        }
    }

    if ($username !== '' || $password !== '') {
        if (!smtpCommand($socket, 'AUTH LOGIN', [334], $debugLog)
            || !smtpCommand($socket, base64_encode($username), [334], $debugLog)
            || !smtpCommand($socket, base64_encode($password), [235], $debugLog)) {
            fclose($socket);
            $debugDetails = implode("\n", $debugLog);
            return false;
        }
    }

    if (!smtpCommand($socket, 'MAIL FROM:<' . $envelopeFrom . '>', [250], $debugLog)
        || !smtpCommand($socket, 'RCPT TO:<' . $recipient . '>', [250, 251], $debugLog)
        || !smtpCommand($socket, 'DATA', [354], $debugLog)) {
        fclose($socket);
        $debugDetails = implode("\n", $debugLog);
        return false;
    }

    $encodedSubject = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($subject, 'UTF-8')
        : $subject;

    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: Mail Tool <' . $fromAddress . '>',
        'Reply-To: ' . $replyToName . ' <' . $replyToAddress . '>',
        'To: <' . $recipient . '>',
        'Subject: ' . $encodedSubject,
        'X-Mailer: PHP/' . phpversion(),
    ];

    $normalizedBody = str_replace(["\r\n", "\r"], "\n", $message);
    $dotStuffed = preg_replace('/^\./m', '..', $normalizedBody);
    $payload = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $dotStuffed) . "\r\n.";

    $debugLog[] = 'SMTP => [message body omitted]';
    fwrite($socket, $payload . "\r\n");

    if (!smtpExpect($socket, [250], $debugLog)) {
        fclose($socket);
        $debugDetails = implode("\n", $debugLog);
        return false;
    }

    smtpCommand($socket, 'QUIT', [221], $debugLog);
    fclose($socket);

    return true;
}


function sendViaResendApi($recipient, $subject, $message, $fromAddress, $replyToName, $replyToAddress, &$debugDetails)
{
    $apiKey = getenv('RESEND_API_KEY') ?: '';
    $from = getenv('RESEND_FROM') ?: $fromAddress;

    if ($apiKey === '') {
        $debugDetails = 'RESEND_API_KEY is empty.';
        return false;
    }

    $payload = [
        'from' => $from,
        'to' => [$recipient],
        'subject' => $subject,
        'text' => $message,
        'reply_to' => [$replyToName . ' <' . $replyToAddress . '>'],
    ];

    $jsonPayload = json_encode($payload);

    if ($jsonPayload === false) {
        $debugDetails = 'Failed to encode JSON payload.';
        return false;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            $debugDetails = 'Resend API failed. HTTP ' . $httpCode . ($curlError !== '' ? ' | cURL: ' . $curlError : '') . ($response ? ' | Response: ' . $response : '');
            return false;
        }

        return true;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Authorization: Bearer {$apiKey}
Content-Type: application/json
",
            'content' => $jsonPayload,
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents('https://api.resend.com/emails', false, $context);
    $statusLine = $http_response_header[0] ?? '';

    if ($response === false || !preg_match('/\s(2\d\d)\s/', $statusLine)) {
        $debugDetails = 'Resend API failed via stream context. Status: ' . ($statusLine !== '' ? $statusLine : 'unknown') . ($response ? ' | Response: ' . $response : '');
        return false;
    }

    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $senderName = trim($_POST['sender_name'] ?? '');
    $senderEmail = trim($_POST['sender_email'] ?? '');
    $recipientEmail = trim($_POST['recipient_email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($senderName === '' || $senderEmail === '' || $recipientEmail === '' || $subject === '' || $message === '') {
        $feedback = 'Please fill out all fields.';
        $feedbackType = 'error';
    } elseif (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        $feedback = 'Please provide valid email addresses.';
        $feedbackType = 'error';
    } else {
        $safeName = str_replace(["\r", "\n"], '', $senderName);
        $safeSender = str_replace(["\r", "\n"], '', $senderEmail);
        $safeRecipient = str_replace(["\r", "\n"], '', $recipientEmail);

        if ($mailTransport === 'smtp') {
            $sent = sendViaSmtp($safeRecipient, $subject, $message, $fromAddress, $envelopeFrom, $safeName, $safeSender, $debugDetails);
        } elseif ($mailTransport === 'resend') {
            $sent = sendViaResendApi($safeRecipient, $subject, $message, $fromAddress, $safeName, $safeSender, $debugDetails);
        } else {
            $headers = [
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'From: Mail Tool <' . $fromAddress . '>',
                'Reply-To: ' . $safeName . ' <' . $safeSender . '>',
                'X-Mailer: PHP/' . phpversion(),
            ];

            $additionalParams = '-f' . escapeshellarg($envelopeFrom);
            $sent = mail($safeRecipient, $subject, $message, implode("\r\n", $headers), $additionalParams);

            if (!$sent) {
                $lastError = error_get_last();
                $sendmailPath = ini_get('sendmail_path');
                $smtpHost = ini_get('SMTP');
                $smtpPort = ini_get('smtp_port');
                $debugDetails = ($lastError['message'] ?? 'No PHP error was reported by mail().')
                    . ' | sendmail_path=' . ($sendmailPath !== '' ? $sendmailPath : '(empty)')
                    . ' | SMTP=' . ($smtpHost !== '' ? $smtpHost : '(empty)')
                    . ' | smtp_port=' . ($smtpPort !== '' ? $smtpPort : '(empty)');
            }
        }

        if ($sent) {
            $feedback = 'Message sent successfully.';
            $feedbackType = 'success';
        } else {
            $feedback = 'Message could not be sent by the server.';
            $feedbackType = 'error';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PHP Mail Sender</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f5f7fb;
            margin: 0;
            padding: 2rem;
        }

        .container {
            max-width: 700px;
            margin: 0 auto;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
            padding: 1.5rem;
        }

        h1 {
            margin-top: 0;
            font-size: 1.4rem;
        }

        .hint {
            color: #555;
            font-size: 0.95rem;
            margin: 0.2rem 0;
        }

        label {
            display: block;
            font-weight: bold;
            margin: 0.8rem 0 0.35rem;
        }

        input,
        textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 0.6rem;
            border: 1px solid #c9d3e0;
            border-radius: 6px;
            font-size: 0.95rem;
        }

        textarea {
            min-height: 130px;
            resize: vertical;
        }

        button {
            margin-top: 1rem;
            background: #2c64ff;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 0.65rem 1rem;
            font-size: 0.95rem;
            cursor: pointer;
        }

        .feedback {
            margin-bottom: 1rem;
            padding: 0.7rem 0.9rem;
            border-radius: 6px;
            font-weight: bold;
        }

        .feedback.success {
            background: #e8f8ef;
            color: #117a3f;
        }

        .feedback.error {
            background: #fdecec;
            color: #a42020;
        }

        code {
            background: #f0f2f7;
            padding: 0.05rem 0.35rem;
            border-radius: 4px;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Send Email</h1>
    <p class="hint">Uses <code>From</code> from <code>MAIL_FROM_ADDRESS</code> and user-entered <code>Reply-To</code>.</p>
    <p class="hint">Default transport is <code>mail()</code>. For InfinityFree, prefer API mode: <code>MAIL_TRANSPORT=resend</code>, <code>RESEND_API_KEY</code>, <code>RESEND_FROM</code>.</p>
    <p class="hint">SMTP relay mode is still available with <code>MAIL_TRANSPORT=smtp</code>, <code>SMTP_HOST</code>, <code>SMTP_PORT</code>, <code>SMTP_USERNAME</code>, <code>SMTP_PASSWORD</code>, <code>SMTP_ENCRYPTION</code>.</p>

    <?php if ($feedback !== null): ?>
        <div class="feedback <?= htmlspecialchars($feedbackType, ENT_QUOTES, 'UTF-8'); ?>">
            <?= htmlspecialchars($feedback, ENT_QUOTES, 'UTF-8'); ?>
        </div>

        <?php if ($feedbackType === 'error' && $debugDetails !== null): ?>
            <div class="feedback error">
                <strong>Debug:</strong> <?= nl2br(htmlspecialchars($debugDetails, ENT_QUOTES, 'UTF-8')); ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <form method="post" action="">
        <label for="sender_name">Sender Name</label>
        <input type="text" id="sender_name" name="sender_name" required value="<?= htmlspecialchars($_POST['sender_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

        <label for="sender_email">Sender Email</label>
        <input type="email" id="sender_email" name="sender_email" required value="<?= htmlspecialchars($_POST['sender_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

        <label for="recipient_email">Recipient Email</label>
        <input type="email" id="recipient_email" name="recipient_email" required value="<?= htmlspecialchars($_POST['recipient_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

        <label for="subject">Subject</label>
        <input type="text" id="subject" name="subject" required value="<?= htmlspecialchars($_POST['subject'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

        <label for="message">Message</label>
        <textarea id="message" name="message" required><?= htmlspecialchars($_POST['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>

        <button type="submit">Send Email</button>
    </form>
</div>
</body>
</html>
