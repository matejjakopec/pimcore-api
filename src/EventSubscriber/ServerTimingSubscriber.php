<?php
namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

final class ServerTimingSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST  => ['onRequest', 10000],
            KernelEvents::RESPONSE => ['onResponse', -10000],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) return;
        $event->getRequest()->attributes->set('_st_start', microtime(true));
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) return;

        $req  = $event->getRequest();
        $resp = $event->getResponse();

        $start = $req->attributes->get('_st_start');
        if (!$start) return;

        $appMs = (microtime(true) - $start) * 1000.0;
        $dbMs  = $req->attributes->get('_st_db_ms');
        $esMs  = $req->attributes->get('_st_es_ms');

        $parts = [sprintf('app;dur=%.1f', $appMs)];
        if ($dbMs !== null) $parts[] = sprintf('db;dur=%.1f', (float)$dbMs);
        if ($esMs !== null) $parts[] = sprintf('es;dur=%.1f', (float)$esMs);

        $val = implode(', ', $parts);

        $resp->headers->set('Server-Timing', $val);

        $resp->headers->set('X-Server-Timing', $val);

        if (!$resp->headers->has('Timing-Allow-Origin')) {
            $resp->headers->set('Timing-Allow-Origin', '*');
        }
    }
}
