<?php

namespace Modules\ContactSender\Models;

class MailerModel {
    // Zadejte e-mail, na který chcete přijímat zprávy z kontaktního formuláře
    private $to_email = "";

    /**
     * Odešle e-mail.
     * @param string $sender_email E-mail odesílatele (Reply-To)
     * @param string $subject Předmět zprávy
     * @param string $message_text Tělo zprávy
     * @return bool True při úspěchu, False při selhání
     */
    public function sendContactEmail(string $sender_email, string $subject, string $message_text): bool {
        $message_text = strip_tags($message_text);

        $headers = "From: webserver@vasedomena.com\r\n";
        $headers .= "Reply-To: " . $sender_email . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

        return mail($this->to_email, $subject, $message_text, $headers);
    }
}