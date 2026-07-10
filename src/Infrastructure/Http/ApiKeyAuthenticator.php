<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Static API-key authentication on the Symfony firewall (the travel project
 * plugs in a custom authenticator for bearer JWT; the ledger is a
 * service-to-service backend, so a single shared key is the right shape). The
 * key is compared in constant time; success yields a fixed `api-client`
 * identity. Failures render RFC 9457 problem+json (D5).
 */
final class ApiKeyAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public const string HEADER = 'X-Api-Key';
    private const string IDENTITY = 'api-client';

    public function __construct(private readonly string $apiKey) {}

    public function supports(Request $request): bool
    {
        return $request->headers->has(self::HEADER);
    }

    public function authenticate(Request $request): Passport
    {
        $presented = (string) $request->headers->get(self::HEADER);
        if ($presented === '' || !hash_equals($this->apiKey, $presented)) {
            throw new CustomUserMessageAuthenticationException('Invalid API key.');
        }

        return new SelfValidatingPassport(new UserBadge(self::IDENTITY));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return ProblemDetails::response(Response::HTTP_UNAUTHORIZED, 'Invalid API key.');
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return ProblemDetails::response(Response::HTTP_UNAUTHORIZED, 'API key is required.');
    }
}
