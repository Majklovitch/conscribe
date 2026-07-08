<?php

namespace Modules\ContactSender\Controllers;

use Modules\ContactSender\Models\MailerModel;
class ContactController {
    
    public function sendMail(): void{
        // Validate redirect target: only allow same-host redirects to prevent open redirect
        $redirect_url = '/';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host !== '' && $referer !== '') {
            $refererHost = parse_url($referer, PHP_URL_HOST) ?? '';
            if ($refererHost === $host) {
                $redirect_url = parse_url($referer, PHP_URL_PATH) ?? '/';
            }
        }

        if ($_SERVER["REQUEST_METHOD"] === "POST" && $_POST['form_id'] === 'contact_form') {
            // CSRF validation must be the first check
            check_csrf();

            $current_time = time();
            $load_time = $_SESSION['form_load_time'] ?? 0;

            if (($current_time - $load_time) < 3) {
                die("Jste příliš rychlý! (Robot?)");
            }
            if (!empty($_POST['communication_type'])) {
                $_SESSION['mail_result'] = "❌ Chyba: Detekován spam.";
                header("Location: " . $redirect_url);
                exit;
            }

            $sender_email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
            $subjectRaw = $_POST['subject'] ?? '';
            $subject = trim(strip_tags($subjectRaw));

            $messageRaw = $_POST['text'] ?? '';
            $message_text = trim(strip_tags($messageRaw));

            if (!$sender_email || empty($subject) || empty($message_text)) {
                $_SESSION['mail_result'] = "❌ Chyba: Vyplňte prosím všechna pole!";
            } else {
                $mailer = new MailerModel();
                $success = $mailer->sendContactEmail($sender_email, $subject, $message_text);

                if ($success) {
                    $_SESSION['mail_result'] = "✅ Děkujeme, Vaše zpráva byla odeslána.";
                } else {
                    $_SESSION['mail_result'] = "❌ Chyba serveru při odesílání e-mailu.";
                }
            }
            header("Location: " . $redirect_url);
            exit;
        }
        header("Location: /");
        exit;
    }
}