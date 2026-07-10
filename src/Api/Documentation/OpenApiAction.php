<?php

declare(strict_types=1);

namespace App\Api\Documentation;

use App\Infrastructure\OpenApi\OpenApiGenerator;
use App\Infrastructure\OpenApi\OpenApiPublic;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Yaml\Yaml;

/**
 * Serves the generated OpenAPI 3.1 document as JSON or YAML. Public: the
 * contract is not a secret and tooling must read it without a key.
 */
#[OpenApiPublic]
final readonly class OpenApiAction
{
    public function __construct(private string $projectDir) {}

    #[Route('/openapi.{format}', name: 'api_openapi', methods: ['GET'], requirements: ['format' => 'json|yaml'])]
    public function __invoke(Request $request, string $format = 'json'): Response
    {
        $spec = new OpenApiGenerator()->generate($this->projectDir . '/src/Api');
        $spec['servers'] = [['url' => $request->getSchemeAndHttpHost(), 'description' => 'Current server']];

        if ($format === 'yaml') {
            return new Response(
                Yaml::dump($spec, 10, 2, Yaml::DUMP_OBJECT_AS_MAP),
                Response::HTTP_OK,
                ['Content-Type' => 'application/yaml'],
            );
        }

        return new JsonResponse($spec);
    }
}
