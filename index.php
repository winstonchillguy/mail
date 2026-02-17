<?php
$feedback = null;
$feedbackType = null;

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

        // Legitimate sender envelope/header. User input goes into Reply-To only.
        $fromAddress = 'no-reply@yourdomain.example';
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: Mail Tool <' . $fromAddress . '>',
            'Reply-To: ' . $safeName . ' <' . $safeSender . '>',
            'X-Mailer: PHP/' . phpversion(),
        ];

        $sent = mail($safeRecipient, $subject, $message, implode("\r\n", $headers));

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
            max-width: 640px;
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
    </style>
</head>
<body>
<div class="container">
    <h1>Send Email</h1>
    <p class="hint">This form supports legitimate sending with a fixed <code>From</code> address and a user-defined <code>Reply-To</code>.</p>

    <?php if ($feedback !== null): ?>
        <div class="feedback <?= htmlspecialchars($feedbackType, ENT_QUOTES, 'UTF-8'); ?>">
            <?= htmlspecialchars($feedback, ENT_QUOTES, 'UTF-8'); ?>
        </div>
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
