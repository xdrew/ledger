<?php

declare(strict_types=1);

namespace App\Infrastructure\OpenApi\Console;

use App\Infrastructure\OpenApi\OpenApiGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Dumps the generated OpenAPI document to stdout or a file (json|yaml).
 */
#[AsCommand(name: 'api:openapi:generate', description: 'Generate the OpenAPI document from the API controllers')]
final class GenerateOpenApiCommand extends Command
{
    public function __construct(private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format (json or yaml)', 'json')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write to this file instead of stdout');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $formatOption = $input->getOption('format');
        $format = \is_string($formatOption) ? $formatOption : 'json';

        $spec = (new OpenApiGenerator())->generate($this->projectDir . '/src/Api');

        $rendered = match ($format) {
            'yaml' => Yaml::dump($spec, 10, 2, Yaml::DUMP_OBJECT_AS_MAP),
            'json' => json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            default => null,
        };
        if ($rendered === null) {
            $io->error('Invalid format. Use "json" or "yaml".');

            return Command::FAILURE;
        }

        $outputPath = $input->getOption('output');
        if (\is_string($outputPath) && $outputPath !== '') {
            file_put_contents($outputPath, $rendered);
            $io->success(\sprintf('OpenAPI document written to %s', $outputPath));

            return Command::SUCCESS;
        }

        $output->writeln($rendered);

        return Command::SUCCESS;
    }
}
