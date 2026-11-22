<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Http\Middleware;

use Dev1\Pey\Exception\UnexpectedResponseException;
use Dev1\Pey\Exception\WebhookVerificationException;
use Dev1\Pey\Webhooks\VerifiedWebhook;
use Dev1\Pey\Webhooks\WebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies a Pey webhook with the SDK's WebhookVerifier before anything else
 * reads it, and passes the verified webhook on in the request attributes.
 *
 * It verifies the raw body (`$request->getContent()`) and the headers, and
 * never reads the request input, so nothing acts on an unverified body. A
 * request that fails is answered `400 {"error":"invalid_webhook"}`, and only
 * the reason is logged: never the body, the headers or the secret.
 *
 * The verifier is resolved per request, so a missing PEY_WEBHOOK_SECRET
 * surfaces as a ConfigurationException when a webhook arrives, not at boot.
 */
final class VerifyPeyWebhook
{
    /**
     * The request attribute that holds the VerifiedWebhook.
     */
    public const ATTRIBUTE = 'pey.webhook';

    public function __construct(
        private readonly WebhookVerifier $verifier,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param \Closure(Request): Response $next
     */
    public function handle(Request $request, \Closure $next): Response
    {
        try {
            $webhook = $this->verifier->verify($request->getContent(), $request->headers->all());
        } catch (WebhookVerificationException $e) {
            $this->logger->warning('Pey webhook rejected: {reason}.', ['reason' => $e->failure()->value]);

            return self::rejected();
        } catch (UnexpectedResponseException $e) {
            // The signature is valid, but the SDK cannot read the event. The
            // message names the field, never its value. Pey retries it, so an
            // SDK upgrade can still process it.
            $this->logger->error('A verified Pey webhook could not be read: {error}', ['reason' => 'invalid_event', 'error' => $e->getMessage()]);

            return self::rejected();
        }

        $request->attributes->set(self::ATTRIBUTE, $webhook);

        return $next($request);
    }

    /**
     * The webhook this middleware verified, for a controller behind it.
     *
     * @throws \LogicException when the request did not pass through this middleware
     */
    public static function verifiedWebhook(Request $request): VerifiedWebhook
    {
        $webhook = $request->attributes->get(self::ATTRIBUTE);

        return $webhook instanceof VerifiedWebhook
            ? $webhook
            : throw new \LogicException('This request has no verified Pey webhook. Put the route behind the VerifyPeyWebhook middleware.');
    }

    private static function rejected(): JsonResponse
    {
        return new JsonResponse(['error' => 'invalid_webhook'], Response::HTTP_BAD_REQUEST);
    }
}
