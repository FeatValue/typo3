<?php

declare(strict_types=1);

namespace Featvalue\Typo3\Mail;

use Featvalue\Typo3\Api\SignedClient;
use Featvalue\Typo3\Api\SiteApi;
use Featvalue\Typo3\Options;
use Symfony\Component\Mime\Address;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class MailRelay
{
    public static function pull(): void
    {
        if (!SiteApi::isConnected() || !SiteApi::isSetupComplete()) {
            return;
        }
        $siteId = (int) Options::get(Options::SITE_ID, 0);
        if ($siteId < 1) {
            return;
        }
        try {
            $result = SignedClient::request('GET', '/api/self-hosted/' . $siteId . '/relay/messages');
        } catch (\Throwable $e) {
            return;
        }
        if (($result['status'] ?? 0) !== 200 || !is_array($result['body']['messages'] ?? null)) {
            return;
        }
        $hadError = false;
        foreach ($result['body']['messages'] as $message) {
            if (!is_array($message) || empty($message['id'])) {
                continue;
            }
            $to = (string) ($message['to_email'] ?? '');
            $subject = (string) ($message['subject'] ?? '');
            $html = (string) ($message['html'] ?? '');
            $id = (int) $message['id'];
            if ($to === '' || $id < 1) {
                continue;
            }
            $sent = false;
            try {
                $mail = GeneralUtility::makeInstance(MailMessage::class);
                $mail->to(new Address($to));
                $mail->subject($subject);
                $mail->html($html);
                $sent = $mail->send() > 0;
            } catch (\Throwable $e) {
                $sent = false;
            }
            try {
                SignedClient::request('POST', '/api/self-hosted/' . $siteId . '/relay/messages/ack', $sent
                    ? ['id' => $id, 'status' => 'sent']
                    : ['id' => $id, 'status' => 'failed', 'error' => 'typo3_mail_failed']);
            } catch (\Throwable $e) {
            }
            if (!$sent) {
                $hadError = true;
            }
        }
        if ($hadError) {
            Options::set(Options::MAIL_ERROR, 'FeatValue could not send one or more emails via TYPO3.');
        } elseif ($result['body']['messages'] !== []) {
            Options::delete(Options::MAIL_ERROR);
        }
    }
}
