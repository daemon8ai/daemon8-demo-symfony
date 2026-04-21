<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

final class MailerController extends AbstractController
{
    #[Route('/demo/mail', name: 'demo_mail')]
    public function send(MailerInterface $mailer): JsonResponse
    {
        $email = (new Email())
            ->from('demo@example.test')
            ->to('inbox@example.test')
            ->subject('demo-mail-subject')
            ->text('demo-mail-body');

        $mailer->send($email);

        return new JsonResponse(['sent' => true]);
    }
}
